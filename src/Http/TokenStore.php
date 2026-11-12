<?php

namespace Noop\Moloni\Http;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;

class TokenStore
{
    private const SAFETY_MARGIN_SECONDS = 60;

    private const REFRESH_TOKEN_SECONDS = 14 * 24 * 3600;

    public function accessToken(): string
    {
        $cached = $this->cachedAccessToken();
        if ($cached !== null) {
            return $cached;
        }

        try {
            return Cache::lock('moloni-token', 30)->block(10, function (): string {
                // Another caller may have refreshed while this one waited for the lock.
                return $this->cachedAccessToken() ?? $this->obtainTokens();
            });
        } catch (LockTimeoutException) {
            throw new MoloniException('Timed out waiting for the Moloni token lock.', FailureType::Transient);
        }
    }

    /**
     * Drops the cached access token, unless another caller already replaced it.
     */
    public function forget(string $accessToken): void
    {
        if ($this->cachedAccessToken() === $accessToken) {
            Cache::forget($this->cacheKey('access'));
        }
    }

    /**
     * Values that must never appear in exceptions or logs.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([
            $this->cachedAccessToken(),
            $this->cachedRefreshToken(),
            $this->config('client_secret'),
            $this->config('password'),
        ], fn (?string $secret) => $secret !== null && $secret !== ''));
    }

    private function obtainTokens(): string
    {
        $refreshToken = $this->cachedRefreshToken();

        if ($refreshToken !== null) {
            $tokens = $this->grant([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
            if ($tokens !== null) {
                return $this->store($tokens);
            }
        }

        $tokens = $this->grant([
            'grant_type' => 'password',
            'username' => $this->config('email'),
            'password' => $this->config('password'),
        ]);

        if ($tokens === null) {
            throw new MoloniException('Moloni refused the login.', FailureType::Authentication);
        }

        return $this->store($tokens);
    }

    /**
     * Returns the token response, or null when Moloni refused the grant.
     *
     * @param  array<string, string|null>  $params
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    private function grant(array $params): ?array
    {
        try {
            $response = Http::asForm()
                ->timeout((int) config('moloni.timeout'))
                ->connectTimeout((int) config('moloni.connect_timeout'))
                ->post($this->config('base_url').'grant/', [
                    ...$params,
                    'client_id' => $this->config('client_id'),
                    'client_secret' => $this->config('client_secret'),
                ]);
        } catch (ConnectionException) {
            // The previous exception is dropped on purpose: its message may contain request details.
            throw new MoloniException('Could not connect to Moloni to obtain a token.', FailureType::Transient);
        }

        if ($response->serverError()) {
            throw new MoloniException('Moloni failed while issuing a token.', FailureType::Transient);
        }

        $body = $response->json();

        if (! $response->successful()
            || ! is_array($body)
            || ! is_string($body['access_token'] ?? null)
            || ! is_string($body['refresh_token'] ?? null)) {
            return null;
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'],
            'expires_in' => is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 3600,
        ];
    }

    /**
     * @param  array{access_token: string, refresh_token: string, expires_in: int}  $tokens
     */
    private function store(array $tokens): string
    {
        Cache::put($this->cacheKey('access'), $tokens['access_token'], $tokens['expires_in'] - self::SAFETY_MARGIN_SECONDS);
        Cache::put($this->cacheKey('refresh'), $tokens['refresh_token'], self::REFRESH_TOKEN_SECONDS - self::SAFETY_MARGIN_SECONDS);

        return $tokens['access_token'];
    }

    private function cachedAccessToken(): ?string
    {
        $token = Cache::get($this->cacheKey('access'));

        return is_string($token) ? $token : null;
    }

    private function cachedRefreshToken(): ?string
    {
        $token = Cache::get($this->cacheKey('refresh'));

        return is_string($token) ? $token : null;
    }

    private function cacheKey(string $kind): string
    {
        return 'moloni.token.'.hash('sha256', $this->config('client_id').'|'.$this->config('email')).'.'.$kind;
    }

    private function config(string $key): ?string
    {
        $value = config('moloni.'.$key);

        return is_scalar($value) ? (string) $value : null;
    }
}
