<?php

namespace Noop\Moloni\Http;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use SensitiveParameter;
use Throwable;

class MoloniConnector
{
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 200;

    /** Seconds; a larger moloni.timeout is capped to this. */
    public const MAX_TIMEOUT = 30;

    /** Seconds; a larger moloni.connect_timeout is capped to this. */
    public const MAX_CONNECT_TIMEOUT = 10;

    /**
     * @param  (Closure(): ?int)|null  $companyId
     */
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly ?Closure $companyId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     */
    public function read(string $endpoint, array $params = []): array
    {
        return $this->send($endpoint, $this->withCompany($params), isWrite: false);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     */
    public function write(string $endpoint, array $params): array
    {
        return $this->send($endpoint, $this->withCompany($params), isWrite: true);
    }

    /**
     * Reads every page of a list endpoint. Many Moloni list endpoints ignore qty/offset
     * and return the full list on every call, so paging stops on a page that is not
     * exactly one page long or that repeats the previous one.
     *
     * @param  array<string, mixed>  $params
     * @return list<mixed>
     */
    public function readAll(string $endpoint, array $params = []): array
    {
        $rows = [];
        $previous = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->read($endpoint, [...$params, 'qty' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE]);

            if ($result === $previous) {
                return $rows;
            }

            array_push($rows, ...array_values($result));

            if (count($result) !== self::PAGE_SIZE) {
                return $rows;
            }

            $previous = $result;
        }

        throw new MoloniException("Moloni kept returning pages for {$endpoint}.", FailureType::Transient);
    }

    /**
     * Adds the configured company unless the caller set company_id itself.
     * An explicit null means "send no company_id" (global endpoints such as countries/getAll).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withCompany(array $params): array
    {
        if (array_key_exists('company_id', $params)) {
            if ($params['company_id'] === null) {
                unset($params['company_id']);
            }

            return $params;
        }

        $companyId = $this->companyId !== null ? ($this->companyId)() : null;

        return $companyId !== null ? [...$params, 'company_id' => $companyId] : $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     */
    private function send(string $endpoint, array $params, bool $isWrite, bool $isRetry = false): array
    {
        $token = $this->tokens->accessToken();
        // Unknown on a write: the request may have reached Moloni and been applied.
        $uncertain = $isWrite ? FailureType::Unknown : FailureType::Transient;

        try {
            // Capped: the issuing job never sends an insert after 110 s of a 120 s timeout.
            $response = Http::timeout(min((int) config('moloni.timeout'), self::MAX_TIMEOUT))
                ->connectTimeout(min((int) config('moloni.connect_timeout'), self::MAX_CONNECT_TIMEOUT))
                ->withQueryParameters(['access_token' => $token, 'json' => 'true', 'human_errors' => 'true'])
                ->post($this->url($endpoint), $params);
        } catch (StrayRequestException) {
            // Laravel's message embeds the full URL, whose query string carries the access token.
            throw new StrayRequestException($this->url($endpoint));
        } catch (Throwable) {
            // Whatever failed, the request may have reached Moloni. The original exception is
            // dropped on purpose: its message can contain the URL with the token.
            throw new MoloniException("Could not complete the Moloni request to {$endpoint}.", $uncertain);
        }

        if ($response->status() === 401) {
            if ($isRetry) {
                throw new MoloniException("Moloni rejected the access token for {$endpoint}.", FailureType::Authentication);
            }
            // Rejected tokens are not processed, so a write is safe to repeat once with a new token.
            $this->tokens->forget($token);

            return $this->send($endpoint, $params, $isWrite, isRetry: true);
        }

        if ($response->status() === 403) {
            throw new MoloniException("Moloni denied access to {$endpoint}.", FailureType::Authentication);
        }

        if ($response->serverError()) {
            throw new MoloniException("Moloni failed while handling {$endpoint}.", $uncertain);
        }

        if ($response->status() === 404) {
            throw new MoloniException("Moloni does not know the endpoint {$endpoint}.", FailureType::Validation);
        }

        $body = $response->json();

        if ($isWrite) {
            return $this->writeResult($endpoint, $response, $body, $token);
        }

        if (! $response->successful() || ! is_array($body)) {
            // A read never changes anything, so it is never Unknown: a refused read is Validation, the rest can be retried.
            $type = $response->clientError() && $response->status() !== 429 ? FailureType::Validation : FailureType::Transient;

            throw new MoloniException("Moloni returned an unexpected response for {$endpoint}.", $type);
        }

        return $body;
    }

    /**
     * Moloni confirms a write only with `valid` 1. Any other JSON body on a 2xx is a refusal:
     * field errors come back as HTTP 200 in several shapes, and `{valid: 0, message}` on some endpoints.
     *
     * @return array<array-key, mixed>
     */
    private function writeResult(string $endpoint, Response $response, mixed $body, #[SensitiveParameter] string $token): array
    {
        if ($response->successful() && is_array($body)) {
            if (! array_is_list($body) && in_array($body['valid'] ?? null, [1, '1'], true)) {
                return $body;
            }

            throw new MoloniException(
                "Moloni rejected the data sent to {$endpoint}.",
                FailureType::Validation,
                $this->redact($this->normaliseErrors($body), $token),
            );
        }

        if ($response->status() === 400 && is_array($body) && isset($body['error'])) {
            $error = ['code' => $body['error'], 'description' => $body['error_description'] ?? $body['error']];

            throw new MoloniException("Moloni rejected the request to {$endpoint}.", FailureType::Validation, $this->redact([$error], $token));
        }

        throw new MoloniException("Moloni returned an unexpected response for {$endpoint}.", FailureType::Unknown);
    }

    /**
     * Flattens Moloni's error shapes into a list of {code, description[, field]}:
     * a flat list of {code, description}, one nested list per document line, an object keyed
     * by `field[index]`, plain code strings (without human_errors), or `{valid: 0, message: [...]}`.
     *
     * @param  array<array-key, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function normaliseErrors(array $body): array
    {
        $errors = [];

        $collect = function (mixed $value, ?string $field) use (&$collect, &$errors): void {
            if (is_array($value) && isset($value['code'])) {
                $errors[] = [
                    'code' => $value['code'],
                    'description' => $value['description'] ?? $value['code'],
                    ...($field !== null ? ['field' => $field] : []),
                ];
            } elseif (is_string($value)) {
                $errors[] = ['code' => $value, 'description' => $value, ...($field !== null ? ['field' => $field] : [])];
            } elseif (is_array($value)) {
                foreach ($value as $key => $item) {
                    $collect($item, is_string($key) ? $key : $field);
                }
            }
        };

        $source = match (true) {
            array_key_exists('valid', $body) => $body['message'] ?? [],
            // Top-level scalars of an object (ids, statuses) are not errors; only field-keyed lists are.
            ! array_is_list($body) => array_filter($body, is_array(...)),
            default => $body,
        };
        $collect($source, null);

        if ($errors !== []) {
            return $errors;
        }

        return [$body === []
            ? ['code' => 'empty response', 'description' => 'Moloni returned an empty response.']
            : ['code' => 'not_confirmed', 'description' => 'Moloni did not confirm the operation']];
    }

    /**
     * @param  array<array-key, mixed>  $errors
     * @return array<array-key, mixed>
     */
    private function redact(array $errors, #[SensitiveParameter] string $token): array
    {
        $secrets = [];
        foreach ([$token, ...$this->tokens->secrets()] as $secret) {
            array_push($secrets, $secret, rawurlencode($secret), urlencode($secret));
        }
        // Longest first, so a secret is never partly replaced by a shorter one it contains.
        $secrets = array_unique($secrets);
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $this->redactWith($errors, $secrets);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $secrets
     * @return array<array-key, mixed>
     */
    private function redactWith(array $values, #[SensitiveParameter] array $secrets): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $key = is_string($key) ? str_replace($secrets, '[redacted]', $key) : $key;
            $redacted[$key] = match (true) {
                is_string($value) => str_replace($secrets, '[redacted]', $value),
                is_array($value) => $this->redactWith($value, $secrets),
                default => $value,
            };
        }

        return $redacted;
    }

    private function url(string $endpoint): string
    {
        $baseUrl = config('moloni.base_url');

        return (is_string($baseUrl) ? $baseUrl : '').trim($endpoint, '/').'/';
    }
}
