<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Http\TokenStore;

use function Pest\Laravel\travel;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
});

it('logs in with the password grant and caches both tokens', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(moloniGrant('access-2', 'refresh-2')),
    ]);
    $store = app(TokenStore::class);

    expect($store->accessToken())->toBe('access-1')
        ->and($store->accessToken())->toBe('access-1');

    Http::assertSentCount(1);
    // Moloni reads username and password only from the query string; the client credentials go in the form body.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->isForm()
        && str_starts_with($request->url(), 'https://api.moloni.pt/v1/grant/?')
        && $request->data() === ['grant_type' => 'password', 'client_id' => 'fake-client', 'client_secret' => 'fake-client-secret']
        && grantQuery($request) === ['username' => 'shop@example.test', 'password' => 'fake-password']);

    // The refresh token was cached too: once the access token expires it is used instead of the password.
    travel(3600)->seconds();

    expect($store->accessToken())->toBe('access-2');
    Http::assertSent(fn (Request $request) => isGrantRequest($request, 'refresh_token')
        && grantQuery($request) === ['refresh_token' => 'refresh-1']);
});

it('refreshes with the refresh token when the access token expired', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(moloniGrant('access-2', 'refresh-2'))
        ->push(moloniGrant('access-3', 'refresh-3')),
    ]);
    $store = app(TokenStore::class);
    $store->accessToken();

    // Stored with a 60 s margin: still valid at 3539 s, gone at 3540 s.
    travel(3539)->seconds();
    expect($store->accessToken())->toBe('access-1');

    travel(1)->seconds();
    expect($store->accessToken())->toBe('access-2');

    // Moloni reads refresh_token only from the query string; grant_type and the client credentials go in the form body.
    Http::assertSent(fn (Request $request) => isGrantRequest($request, 'refresh_token')
        && $request->data() === ['grant_type' => 'refresh_token', 'client_id' => 'fake-client', 'client_secret' => 'fake-client-secret']
        && grantQuery($request) === ['refresh_token' => 'refresh-1']);

    // Both tokens rotate on refresh: the next refresh uses the new refresh token.
    travel(3600)->seconds();
    expect($store->accessToken())->toBe('access-3');
    Http::assertSent(fn (Request $request) => isGrantRequest($request, 'refresh_token')
        && grantQuery($request) === ['refresh_token' => 'refresh-2']);
    expect(Http::recorded(fn (Request $request) => isGrantRequest($request, 'password')))->toHaveCount(1);
});

it('logs in again once the refresh token is older than 14 days', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(moloniGrant('access-2', 'refresh-2')),
    ]);
    $store = app(TokenStore::class);
    $store->accessToken();

    travel(14)->days();

    expect($store->accessToken())->toBe('access-2');
    expect(Http::recorded(fn (Request $request) => isGrantRequest($request, 'password')))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request) => isGrantRequest($request, 'refresh_token')))->toHaveCount(0);
});

it('requests a single refresh when two callers race', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(moloniGrant('access-2', 'refresh-2'))
        ->push(moloniGrant('access-3', 'refresh-3')),
    ]);
    $store = app(TokenStore::class);
    $store->accessToken();
    travel(3600)->seconds();

    // Caller A holds the lock while it refreshes.
    $callerALock = Cache::lock('moloni-token', 30, 'caller-a');
    expect($callerALock->get())->toBeTrue();

    $callerBWaited = false;
    Sleep::fake();
    Sleep::whenFakingSleep(function () use (&$callerBWaited, $callerALock, $store) {
        if ($callerBWaited) {
            return;
        }
        $callerBWaited = true;

        // While caller B waits on the lock, caller A finishes its refresh and releases it.
        $callerALock->release();
        expect($store->accessToken())->toBe('access-2');
    });

    // Caller B found no access token, waited for the lock, and must reuse caller A's token.
    expect($store->accessToken())->toBe('access-2')
        ->and($callerBWaited)->toBeTrue()
        ->and(Http::recorded(fn (Request $request) => isGrantRequest($request, 'refresh_token')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request) => isGrantRequest($request)))->toHaveCount(2);
});

it('falls back to a password login once when the refresh token is rejected', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token'], 400)
        ->push(moloniGrant('access-2', 'refresh-2')),
    ]);
    $store = app(TokenStore::class);
    $store->accessToken();
    travel(3600)->seconds();

    expect($store->accessToken())->toBe('access-2');
    expect(Http::recorded(fn (Request $request) => isGrantRequest($request, 'password')))->toHaveCount(2);
});

it('never sends a refresh token again after a failed refresh', function (array $failures, FailureType $type) {
    $sequence = Http::sequence()->push(moloniGrant('access-1', 'refresh-1'));
    foreach ($failures as $failure) {
        $sequence->push(...$failure);
    }
    Http::fake(['*/grant/*' => $sequence->push(moloniGrant('access-2', 'refresh-2'))]);
    $store = app(TokenStore::class);
    $store->accessToken();
    travel(3600)->seconds();

    expect(catchMoloniException(fn () => $store->accessToken())->type)->toBe($type);
    expect($store->accessToken())->toBe('access-2');

    expect(Http::recorded(fn (Request $request) => isGrantRequest($request, 'refresh_token')))->toHaveCount(1);
})->with([
    // Refused refresh → fallback login is refused too → Authentication; the next call logs in.
    'refused' => [[
        [['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token'], 400],
        [['error' => 'invalid_grant', 'error_description' => 'Invalid username and password combination'], 400],
    ], FailureType::Authentication],
    // The refresh may have consumed the token server-side; the next call logs in instead of re-sending it.
    'server error' => [[['', 503]], FailureType::Transient],
]);

it('throws Authentication when the login is refused', function () {
    Http::fake(['*/grant/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid username and password combination', 'error_uri' => 'https://example.test/dev/auth/'], 400)]);

    $e = catchMoloniException(fn () => app(TokenStore::class)->accessToken());

    expect($e->type)->toBe(FailureType::Authentication)
        ->and($e->getMessage())->not->toContain('fake-password')
        ->and($e->getMessage())->not->toContain('fake-client-secret');
});

it('throws Authentication when the refresh and the fallback login are both refused', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(['error' => 'invalid_grant'], 400)
        ->push(['error' => 'invalid_grant'], 400),
    ]);
    $store = app(TokenStore::class);
    $store->accessToken();
    travel(3600)->seconds();

    expect(catchMoloniException(fn () => $store->accessToken())->type)->toBe(FailureType::Authentication);
    Http::assertSentCount(3);
});

it('classifies a grant failure that is not a credential refusal as Transient', function (Closure|array $response) {
    Http::fake(['*/grant/*' => is_array($response) ? Http::response(...$response) : $response]);

    $e = catchMoloniException(fn () => app(TokenStore::class)->accessToken());

    expect($e->type)->toBe(FailureType::Transient)
        ->and($e->getPrevious())->toBeNull()
        ->and($e->getMessage().json_encode($e->errors))->not->toContain('fake-password');
})->with([
    'connection failure' => fn () => Http::failedConnection(),
    'other exception' => fn () => fn () => throw new RuntimeException('Failed: https://api.moloni.pt/v1/grant/?username=shop%40example.test&password=fake-password'),
    'server error' => [['', 503]],
    'too many requests' => [[['error' => 'Too Many Requests'], 429]],
    'html body' => [['<html>oops</html>', 200]],
    'client error without an oauth error' => [[['message' => 'Bad Request'], 400]],
]);

it('caps the grant timeouts so a refresh and a login fit inside the lock', function () {
    config(['moloni.timeout' => 60, 'moloni.connect_timeout' => 30]);
    $options = [];
    Http::fake(['*/grant/*' => function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(moloniGrant());
    }]);

    app(TokenStore::class)->accessToken();

    expect($options['timeout'] ?? null)->toBe(10)
        ->and($options['connect_timeout'] ?? null)->toBe(5);
});

it('caches a short-lived access token for at least one second', function () {
    Http::fake(['*/grant/*' => Http::response([...moloniGrant(), 'expires_in' => 30])]);
    $store = app(TokenStore::class);

    expect($store->accessToken())->toBe('access-1')
        ->and($store->accessToken())->toBe('access-1');
    Http::assertSentCount(1);
});

it('throws Transient when the token lock cannot be acquired in time', function () {
    Http::fake(['*/grant/*' => moloniGrant()]);
    expect(Cache::lock('moloni-token', 30, 'another-worker')->get())->toBeTrue();
    Sleep::fake(syncWithCarbon: true);

    expect(catchMoloniException(fn () => app(TokenStore::class)->accessToken())->type)->toBe(FailureType::Transient);
    Http::assertNothingSent();
});

it('forgets the access token only while it is still the cached one', function () {
    Http::fake(['*/grant/*' => Http::sequence()
        ->push(moloniGrant('access-1', 'refresh-1'))
        ->push(moloniGrant('access-2', 'refresh-2')),
    ]);
    $store = app(TokenStore::class);
    $store->accessToken();

    $store->forget('some-older-token');
    expect($store->accessToken())->toBe('access-1');

    $store->forget('access-1');
    expect($store->accessToken())->toBe('access-2');
    Http::assertSent(fn (Request $request) => isGrantRequest($request, 'refresh_token'));
});
