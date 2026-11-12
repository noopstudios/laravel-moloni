<?php

namespace Noop\Moloni\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;

class MoloniConnector
{
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 200;

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
        return $this->send($endpoint, $params, isWrite: false);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     */
    public function write(string $endpoint, array $params): array
    {
        return $this->send($endpoint, $params, isWrite: true);
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
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>
     */
    private function send(string $endpoint, array $params, bool $isWrite, bool $isRetry = false): array
    {
        $companyId = $this->companyId !== null ? ($this->companyId)() : null;
        if ($companyId !== null) {
            $params += ['company_id' => $companyId];
        }

        $token = $this->tokens->accessToken();
        // Unknown on a write: the request may have reached Moloni and been applied.
        $uncertain = $isWrite ? FailureType::Unknown : FailureType::Transient;

        try {
            $response = Http::timeout((int) config('moloni.timeout'))
                ->connectTimeout((int) config('moloni.connect_timeout'))
                ->withQueryParameters(['access_token' => $token, 'json' => 'true', 'human_errors' => 'true'])
                ->post($this->url($endpoint), $params);
        } catch (ConnectionException) {
            // The previous exception is dropped on purpose: its message contains the URL with the token.
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

        $errors = $this->validationErrors($response);
        if ($errors !== null) {
            throw new MoloniException("Moloni rejected the data sent to {$endpoint}.", FailureType::Validation, $this->redact($errors, $token));
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw new MoloniException("Moloni returned an unexpected response for {$endpoint}.", FailureType::Unknown);
        }

        if ($isWrite && (array_is_list($body) || ! is_numeric($body['valid'] ?? null) || (int) $body['valid'] !== 1)) {
            throw new MoloniException("Moloni did not confirm the write to {$endpoint}.", FailureType::Unknown);
        }

        return $body;
    }

    /**
     * Field errors (a list of {code, description}) or a `valid: 0` write response.
     * Both shapes are still to be confirmed against the sandbox; keep the parsing here.
     *
     * @return array<array-key, mixed>|null
     */
    private function validationErrors(Response $response): ?array
    {
        $body = $response->json();

        if (! is_array($body) || $body === []) {
            return null;
        }

        if (array_is_list($body)) {
            foreach ($body as $error) {
                if (! is_array($error)
                    || ! isset($error['code'], $error['description'])
                    || array_diff(array_keys($error), ['code', 'description']) !== []) {
                    return null;
                }
            }

            return $body;
        }

        if (is_numeric($body['valid'] ?? null) && (int) $body['valid'] === 0) {
            return $body;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $errors
     * @return array<array-key, mixed>
     */
    private function redact(array $errors, string $token): array
    {
        $secrets = [$token, ...$this->tokens->secrets()];

        array_walk_recursive($errors, function (mixed &$value) use ($secrets) {
            if (is_string($value)) {
                $value = str_replace($secrets, '[redacted]', $value);
            }
        });

        return $errors;
    }

    private function url(string $endpoint): string
    {
        $baseUrl = config('moloni.base_url');

        return (is_string($baseUrl) ? $baseUrl : '').trim($endpoint, '/').'/';
    }
}
