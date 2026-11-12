<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Tests\DiscoveryOrderTestCase;
use Noop\Moloni\Tests\TestCase;
use PHPUnit\Framework\Assert;

pest()->extend(DiscoveryOrderTestCase::class)->in('DiscoveryOrder');
pest()->extend(TestCase::class)->in('Feature', 'Unit');

function useFakeMoloniCredentials(): void
{
    config([
        'moloni.client_id' => 'fake-client',
        'moloni.client_secret' => 'fake-client-secret',
        'moloni.email' => 'shop@example.test',
        'moloni.password' => 'fake-password',
    ]);
}

/**
 * @return array<string, mixed>
 */
function moloniGrant(string $access = 'access-1', string $refresh = 'refresh-1'): array
{
    return [
        'access_token' => $access,
        'expires_in' => 3600,
        'token_type' => 'bearer',
        'scope' => null,
        'refresh_token' => $refresh,
    ];
}

function catchMoloniException(Closure $call): MoloniException
{
    try {
        $call();
    } catch (MoloniException $e) {
        return $e;
    }

    Assert::fail('Expected a MoloniException, but none was thrown.');
}

/**
 * @return array<array-key, mixed>
 */
function grantQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function catchStrayRequest(Closure $call): StrayRequestException
{
    try {
        $call();
    } catch (StrayRequestException $e) {
        return $e;
    }

    Assert::fail('Expected a StrayRequestException, but none was thrown.');
}

/**
 * Asserts no login credential or token appears in the exception, its errors or its previous chain.
 */
function expectNoGrantSecrets(Throwable $e): void
{
    $haystack = $e instanceof MoloniException ? json_encode($e->errors) : '';
    for ($current = $e; $current !== null; $current = $current->getPrevious()) {
        $haystack .= $current->getMessage();
    }

    foreach (['fake-password', 'fake-client-secret', 'shop@example.test', 'shop%40example.test', 'refresh-1', 'access-1'] as $secret) {
        expect($haystack)->not->toContain($secret);
    }
}

function isGrantRequest(Request $request, ?string $grantType = null): bool
{
    return str_contains($request->url(), '/grant/')
        && ($grantType === null || $request['grant_type'] === $grantType);
}

/**
 * @param  array<string, mixed>  $values
 */
function useMoloniSettings(array $values): void
{
    app(MoloniSettings::class)->fill($values)->save();
}
