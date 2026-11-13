<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Tax\ViesClient;
use Noop\Moloni\Tax\ViesResult;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['moloni.vies.base_url' => 'https://vies.example.test/rest-api']);
});

it('posts the country and the number without its prefix to the check endpoint', function () {
    Http::fake(['*' => ['valid' => true, 'requestIdentifier' => 'REQ-1', 'userError' => 'VALID']]);

    $result = app(ViesClient::class)->check('DE', 'de 123.456-789');

    expect($result->available)->toBeTrue()
        ->and($result->valid)->toBeTrue()
        ->and($result->requestIdentifier)->toBe('REQ-1');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://vies.example.test/rest-api/check-vat-number'
        && $request->data() === ['countryCode' => 'DE', 'vatNumber' => '123456789']);
});

it('sends EL for Greece', function () {
    Http::fake(['*' => ['valid' => false, 'userError' => 'INVALID']]);

    $result = app(ViesClient::class)->check('GR', 'GR123456789');

    expect($result->valid)->toBeFalse();
    Http::assertSent(fn (Request $request) => $request->data() === ['countryCode' => 'EL', 'vatNumber' => '123456789']);
});

it('strips the EL prefix a Greek customer typed', function () {
    Http::fake(['*' => ['valid' => true, 'userError' => 'VALID']]);

    app(ViesClient::class)->check('GR', 'EL123456789');

    Http::assertSent(fn (Request $request) => $request->data()['vatNumber'] === '123456789');
});

it('treats a definitive INVALID answer as invalid, not unavailable', function () {
    Http::fake(['*' => ['valid' => false, 'userError' => 'INVALID']]);

    $result = app(ViesClient::class)->check('FR', 'FR12345678901');

    expect($result->available)->toBeTrue()->and($result->valid)->toBeFalse();
});

it('treats a member state error as unavailable', function (string $userError) {
    Http::fake(['*' => ['valid' => false, 'userError' => $userError]]);

    $result = app(ViesClient::class)->check('FR', '12345678901');

    expect($result->available)->toBeFalse()->and($result->valid)->toBeNull();
})->with(['MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'TIMEOUT', 'SERVICE_UNAVAILABLE', 'GLOBAL_MAX_CONCURRENT_REQ']);

it('bounds the configured timeout, so VIES can never hold the checkout without one', function (mixed $configured, int $seconds) {
    config(['moloni.vies.timeout' => $configured]);
    $options = [];
    Http::fake(['*' => function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['valid' => true, 'userError' => 'VALID']);
    }]);

    app(ViesClient::class)->check('DE', '123456789');

    expect($options['timeout'] ?? null)->toBe($seconds)
        ->and($options['connect_timeout'] ?? null)->toBe($seconds);
})->with([
    'as configured' => [5, 5],
    'zero, which would mean no timeout' => [0, 1],
    'too long' => [60, 10],
    'not a number' => ['soon', 10],
]);

it('treats a timeout as unavailable, not invalid', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    $result = app(ViesClient::class)->check('FR', '12345678901');

    expect($result->available)->toBeFalse()->and($result->valid)->toBeNull();
});

it('treats an HTTP error or an unparseable body as unavailable', function (mixed $response) {
    Http::fake(['*' => $response]);

    $result = app(ViesClient::class)->check('FR', '12345678901');

    expect($result->available)->toBeFalse()->and($result->valid)->toBeNull();
})->with([
    'server error' => fn () => Http::response('boom', 500),
    'not json' => fn () => Http::response('<html></html>', 200),
    'no valid flag' => fn () => Http::response(['actionSucceed' => false], 200),
]);

it('round-trips a result through an array', function () {
    $result = new ViesResult(true, true, 'REQ-9', CarbonImmutable::parse('2026-11-12 10:11:12.345678', 'Europe/Lisbon'));

    $copy = ViesResult::fromArray($result->toArray());

    expect($copy->available)->toBeTrue()
        ->and($copy->valid)->toBeTrue()
        ->and($copy->requestIdentifier)->toBe('REQ-9')
        ->and($copy->checkedAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-12T10:11:12.345678+00:00');
});
