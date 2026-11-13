<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Http\TokenStore;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
});

function connectorWithCompany(?int $companyId): MoloniConnector
{
    return new MoloniConnector(app(TokenStore::class), fn () => $companyId);
}

function isEndpointRequest(Request $request, string $endpoint): bool
{
    return str_starts_with($request->url(), 'https://api.moloni.pt/v1/'.$endpoint.'/?');
}

it('posts json with the access token, json and human_errors flags and the company id', function () {
    Http::fake([
        '*/grant/*' => moloniGrant('access-1'),
        '*/customers/getAll/*' => [['customer_id' => 1]],
    ]);

    $result = connectorWithCompany(5)->read('customers/getAll', ['search' => 'Ana']);

    expect($result)->toBe([['customer_id' => 1]]);
    Http::assertSent(fn (Request $request) => isEndpointRequest($request, 'customers/getAll')
        && $request->method() === 'POST'
        && $request->isJson()
        && str_contains($request->url(), 'access_token=access-1')
        && str_contains($request->url(), 'json=true')
        && str_contains($request->url(), 'human_errors=true')
        && $request->data() === ['search' => 'Ana', 'company_id' => 5]);
});

it('lets an explicit company id win and omits it when there is none', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/companies/getOne/*' => ['company_id' => 9],
        '*/countries/getAll/*' => Http::response([]),
    ]);

    connectorWithCompany(5)->read('companies/getOne', ['company_id' => 9]);
    app(MoloniConnector::class)->read('countries/getAll');

    Http::assertSent(fn (Request $request) => isEndpointRequest($request, 'companies/getOne')
        && $request->data() === ['company_id' => 9]);
    Http::assertSent(fn (Request $request) => isEndpointRequest($request, 'countries/getAll')
        && ! array_key_exists('company_id', $request->data()));
});

it('sends no company id when the caller passes an explicit null, also on the token retry', function () {
    $resolved = 0;
    $connector = new MoloniConnector(app(TokenStore::class), function () use (&$resolved) {
        $resolved++;

        return 5;
    });
    Http::fake([
        '*/grant/*' => Http::sequence()->push(moloniGrant('access-1', 'refresh-1'))->push(moloniGrant('access-2', 'refresh-2')),
        '*/countries/getAll/*' => Http::sequence()->push('', 401)->push([]),
    ]);

    $connector->read('countries/getAll', ['company_id' => null, 'qty' => 50]);

    expect($resolved)->toBe(0);
    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request) => isEndpointRequest($request, 'countries/getAll')
        && array_key_exists('company_id', $request->data()));
});

it('returns an empty list from a read as a normal result', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getBySearch/*' => Http::response([]),
    ]);

    expect(connectorWithCompany(5)->read('customers/getBySearch', ['search' => 'nobody']))->toBe([]);
});

it('returns the body of a valid write', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/invoices/insert/*' => ['valid' => 1, 'document_id' => 123],
    ]);

    expect(connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]))
        ->toBe(['valid' => 1, 'document_id' => 123]);
});

it('retries once when the token is rejected, then throws Authentication', function () {
    Http::fake([
        '*/grant/*' => Http::sequence()
            ->push(moloniGrant('access-1', 'refresh-1'))
            ->push(moloniGrant('access-2', 'refresh-2')),
        '*/invoices/insert/*' => Http::response(['error' => 'invalid_request', 'error_description' => 'The access token provided is invalid'], 401),
    ]);

    expect(catchMoloniException(fn () => connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]))->type)->toBe(FailureType::Authentication);

    $calls = Http::recorded(fn (Request $request) => isEndpointRequest($request, 'invoices/insert'))->values();
    expect($calls)->toHaveCount(2)
        ->and($calls->map(fn (array $call) => $call[0]->url())->all())->sequence(
            fn ($url) => $url->toContain('access_token=access-1'),
            fn ($url) => $url->toContain('access_token=access-2'),
        );
    // The retry got its new token from the cached refresh token, not from a second password login.
    Http::assertSent(fn (Request $request) => isGrantRequest($request, 'refresh_token')
        && grantQuery($request) === ['refresh_token' => 'refresh-1']);
});

it('succeeds on the retry when the new token is accepted', function () {
    Http::fake([
        '*/grant/*' => Http::sequence()
            ->push(moloniGrant('access-1', 'refresh-1'))
            ->push(moloniGrant('access-2', 'refresh-2')),
        '*/customers/getAll/*' => Http::sequence()
            ->push(['error' => 'invalid_request', 'error_description' => 'The access token provided has expired'], 401)
            ->push([['customer_id' => 1]]),
    ]);

    expect(connectorWithCompany(5)->read('customers/getAll'))->toBe([['customer_id' => 1]]);
});

it('sends a write exactly twice and returns the result when the retry is accepted', function () {
    Http::fake([
        '*/grant/*' => Http::sequence()
            ->push(moloniGrant('access-1', 'refresh-1'))
            ->push(moloniGrant('access-2', 'refresh-2')),
        '*/invoices/insert/*' => Http::sequence()
            ->push(['error' => 'invalid_request', 'error_description' => 'The access token provided has expired'], 401)
            ->push(['valid' => 1, 'document_id' => 77]),
    ]);

    expect(connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]))->toBe(['valid' => 1, 'document_id' => 77]);
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'invoices/insert')))->toHaveCount(2);
});

it('throws Authentication without retrying when the company or method is forbidden', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getAll/*' => Http::response(['error' => 'Forbidden', 'error_description' => 'Forbidden'], 403),
    ]);

    expect(catchMoloniException(fn () => connectorWithCompany(5)->read('customers/getAll'))->type)->toBe(FailureType::Authentication);
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'customers/getAll')))->toHaveCount(1);
});

it('classifies an unknown endpoint as Validation', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/nope/*' => Http::response(['error' => 'Not Found', 'error_description' => 'Class not found'], 404),
    ]);

    expect(catchMoloniException(fn () => connectorWithCompany(5)->read('customers/nope'))->type)->toBe(FailureType::Validation);
});

it('accepts valid as int 1 or string "1" as a successful write', function (int|string $valid) {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/documentSets/insert/*' => ['valid' => $valid, 'document_set_id' => '4321'],
    ]);

    expect(connectorWithCompany(5)->write('documentSets/insert', ['name' => 'Shop']))
        ->toBe(['valid' => $valid, 'document_set_id' => '4321']);
})->with([1, '1']);

it('classifies a write refusal as Validation with normalised errors', function (array $body, array $errors) {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/invoices/insert/*' => Http::response($body),
    ]);

    $e = catchMoloniException(fn () => connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->errors)->toBe($errors);
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'invoices/insert')))->toHaveCount(1);
})->with([
    'flat list' => [
        [['code' => '1 products', 'description' => "Field 'products' is required"], ['code' => '1 expiration_date', 'description' => "Field 'expiration_date' is required"]],
        [['code' => '1 products', 'description' => "Field 'products' is required"], ['code' => '1 expiration_date', 'description' => "Field 'expiration_date' is required"]],
    ],
    'nested list per document line' => [
        [[['code' => '10 associated_id', 'description' => 'The associated document is not valid.'], ['code' => '2 value 0 null null 0 0', 'description' => "Field 'value' must be float"]], [['code' => '1 exemption_reason', 'description' => "Field 'exemption_reason' is required"]]],
        [['code' => '10 associated_id', 'description' => 'The associated document is not valid.'], ['code' => '2 value 0 null null 0 0', 'description' => "Field 'value' must be float"], ['code' => '1 exemption_reason', 'description' => "Field 'exemption_reason' is required"]],
    ],
    'object keyed by field' => [
        ['taxes[0]' => [['code' => '5 tax_id', 'description' => "Field 'tax_id' must be valid"]]],
        [['code' => '5 tax_id', 'description' => "Field 'tax_id' must be valid", 'field' => 'taxes[0]']],
    ],
    'plain codes without human errors' => [
        [['1 exemption_reason']],
        [['code' => '1 exemption_reason', 'description' => '1 exemption_reason']],
    ],
    'valid 0 with a message' => [
        ['valid' => 0, 'message' => [['code' => '10 associated_id', 'description' => 'The associated document is not valid.']]],
        [['code' => '10 associated_id', 'description' => 'The associated document is not valid.']],
    ],
    'valid 0 with plain message codes' => [
        ['valid' => 0, 'message' => ['1 document']],
        [['code' => '1 document', 'description' => '1 document']],
    ],
    'valid 0 alone' => [
        ['valid' => 0],
        [['code' => 'not_confirmed', 'description' => 'Moloni did not confirm the operation']],
    ],
    'no valid flag' => [
        ['document_id' => 1],
        [['code' => 'not_confirmed', 'description' => 'Moloni did not confirm the operation']],
    ],
    'no valid flag with scalar values' => [
        ['document_id' => '7', 'status' => 'ok'],
        [['code' => 'not_confirmed', 'description' => 'Moloni did not confirm the operation']],
    ],
    'field-keyed errors next to scalar values' => [
        ['document_id' => '7', 'products[0]' => ['1 exemption_reason']],
        [['code' => '1 exemption_reason', 'description' => '1 exemption_reason', 'field' => 'products[0]']],
    ],
    'empty list' => [
        [],
        [['code' => 'empty response', 'description' => 'Moloni returned an empty response.']],
    ],
]);

it('classifies an empty JSON object on a write as Validation', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/invoices/insert/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
    ]);

    $e = catchMoloniException(fn () => connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->errors)->toBe([['code' => 'empty response', 'description' => 'Moloni returned an empty response.']]);
});

it('classifies a 400 OAuth error on a write as Validation', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/invoices/insert/*' => Http::response(['error' => 'invalid_request', 'error_description' => 'Malformed request'], 400),
    ]);

    $e = catchMoloniException(fn () => connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->errors)->toBe([['code' => 'invalid_request', 'description' => 'Malformed request']]);
});

it('classifies a connection failure as Transient on read and Unknown on write', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getAll/*' => Http::failedConnection(),
        '*/invoices/insert/*' => Http::failedConnection(),
    ]);
    $connector = connectorWithCompany(5);

    expect(catchMoloniException(fn () => $connector->read('customers/getAll'))->type)->toBe(FailureType::Transient);
    expect(catchMoloniException(fn () => $connector->write('invoices/insert', ['customer_id' => 1]))->type)->toBe(FailureType::Unknown);
    // A write whose outcome is unknown is never sent twice.
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'invoices/insert')))->toHaveCount(1);
});

it('classifies a 5xx as Transient on read and Unknown on write', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getAll/*' => Http::response('Bad gateway', 502),
        '*/invoices/insert/*' => Http::response('Bad gateway', 502),
    ]);
    $connector = connectorWithCompany(5);

    expect(catchMoloniException(fn () => $connector->read('customers/getAll'))->type)->toBe(FailureType::Transient);
    expect(catchMoloniException(fn () => $connector->write('invoices/insert', ['customer_id' => 1]))->type)->toBe(FailureType::Unknown);
});

it('classifies an unexpected write response as Unknown', function (mixed $body, int $status) {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/invoices/insert/*' => Http::response($body, $status),
    ]);

    expect(catchMoloniException(fn () => connectorWithCompany(5)->write('invoices/insert', ['customer_id' => 1]))->type)->toBe(FailureType::Unknown);
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'invoices/insert')))->toHaveCount(1);
})->with([
    'html body' => ['<html>oops</html>', 200],
    'html client error' => ['<html>Bad Request</html>', 400],
    'conflict' => [['message' => 'Conflict'], 409],
    'too many requests' => [['error' => 'Too Many Requests'], 429],
]);

it('never classifies a failed read as Unknown', function (mixed $body, int $status, FailureType $type) {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getAll/*' => Http::response($body, $status),
    ]);

    expect(catchMoloniException(fn () => connectorWithCompany(5)->read('customers/getAll'))->type)->toBe($type);
})->with([
    'html body' => ['<html>oops</html>', 200, FailureType::Transient],
    'too many requests' => [['error' => 'Too Many Requests'], 429, FailureType::Transient],
    'bad request' => [['error' => 'Bad Request', 'error_description' => 'Bad Request'], 400, FailureType::Validation],
]);

it('maps any exception thrown while sending to Unknown on write and Transient on read, without chaining it', function () {
    $boom = fn () => throw new RuntimeException('Unexpected failure for https://api.moloni.pt/v1/x/?access_token=access-1');
    Http::fake([
        '*/grant/*' => moloniGrant('access-1'),
        '*/customers/getAll/*' => $boom,
        '*/invoices/insert/*' => $boom,
    ]);
    $connector = connectorWithCompany(5);

    $read = catchMoloniException(fn () => $connector->read('customers/getAll'));
    $write = catchMoloniException(fn () => $connector->write('invoices/insert', ['customer_id' => 1]));

    expect($read->type)->toBe(FailureType::Transient)
        ->and($write->type)->toBe(FailureType::Unknown)
        ->and($read->getPrevious())->toBeNull()
        ->and($write->getPrevious())->toBeNull()
        ->and($write->getMessage())->not->toContain('access-1');
});

it('returns read rows shaped like field errors as data', function () {
    $rows = [['code' => 'A1', 'description' => 'First'], ['code' => 'B2', 'description' => 'Second']];
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/taxExemptions/getAll/*' => Http::response($rows),
    ]);

    expect(connectorWithCompany(5)->read('taxExemptions/getAll'))->toBe($rows);
});

it('paginates readAll in pages of 50 until a short page', function () {
    $rows = fn (int $from, int $count) => array_map(fn ($i) => ['product_id' => $i], range($from, $from + $count - 1));
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/products/getAll/*' => Http::sequence()
            ->push($rows(1, 50))
            ->push($rows(51, 50))
            ->push($rows(101, 7)),
    ]);

    $result = connectorWithCompany(5)->readAll('products/getAll', ['category_id' => 3]);

    expect($result)->toHaveCount(107)
        ->and($result[106])->toBe(['product_id' => 107]);
    $calls = Http::recorded(fn (Request $request) => isEndpointRequest($request, 'products/getAll'))->values();
    expect($calls)->toHaveCount(3)
        ->and(array_map(fn ($call) => $call[0]->data(), $calls->all()))->toBe([
            ['category_id' => 3, 'qty' => 50, 'offset' => 0, 'company_id' => 5],
            ['category_id' => 3, 'qty' => 50, 'offset' => 50, 'company_id' => 5],
            ['category_id' => 3, 'qty' => 50, 'offset' => 100, 'company_id' => 5],
        ]);
});

it('returns a 244-row list once when the endpoint ignores paging', function () {
    $countries = array_map(fn ($i) => ['country_id' => $i], range(1, 244));
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/countries/getAll/*' => $countries,
    ]);

    expect(app(MoloniConnector::class)->readAll('countries/getAll'))->toBe($countries);
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'countries/getAll')))->toHaveCount(1);
});

it('stops when a page repeats', function () {
    $fifty = array_map(fn ($i) => ['currency_id' => $i], range(1, 50));
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/currencies/getAll/*' => $fifty,
    ]);

    expect(app(MoloniConnector::class)->readAll('currencies/getAll'))->toBe($fifty);
    expect(Http::recorded(fn (Request $request) => isEndpointRequest($request, 'currencies/getAll')))->toHaveCount(2);
});

it('gives up with Transient after 200 pages', function () {
    $page = 0;
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/products/getAll/*' => function () use (&$page) {
            $page++;

            return Http::response(array_map(fn ($i) => ['product_id' => $page * 100 + $i], range(1, 50)));
        },
    ]);

    expect(catchMoloniException(fn () => connectorWithCompany(5)->readAll('products/getAll'))->type)->toBe(FailureType::Transient);
    expect($page)->toBe(200);
});

it('redacts URL-encoded secrets and secrets in error keys', function () {
    config(['moloni.password' => 'p@ss w/rd&1', 'moloni.email' => 'owner+shop@example.test']);
    Http::fake([
        '*/grant/*' => moloniGrant('tok/en+1', 'refresh-1'),
        '*/customers/insert/*' => Http::response([
            'p@ss w/rd&1' => [['code' => '2 name', 'description' => 'raw p%40ss%20w%2Frd%261 plus p%40ss+w%2Frd%261']],
            'tok%2Fen%2B1' => [['code' => '2 vat', 'description' => 'owner%2Bshop%40example.test']],
        ]),
    ]);

    $e = catchMoloniException(fn () => connectorWithCompany(5)->write('customers/insert', ['name' => 'x']));
    $haystack = json_encode($e->errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->errors)->toBe([
            ['code' => '2 name', 'description' => 'raw [redacted] plus [redacted]', 'field' => '[redacted]'],
            ['code' => '2 vat', 'description' => '[redacted]', 'field' => '[redacted]'],
        ]);
    foreach (['p@ss w/rd&1', 'p%40ss', 'tok/en+1', 'tok%2Fen', 'owner+shop', 'owner%2Bshop'] as $secret) {
        expect($haystack)->not->toContain($secret);
    }
});

it('keeps the access token out of stray-request errors', function () {
    Http::fake(['*/grant/*' => moloniGrant('access-1', 'refresh-1')]);

    $e = catchStrayRequest(fn () => connectorWithCompany(5)->read('customers/getAll'));

    expect($e->getMessage())->toContain('https://api.moloni.pt/v1/customers/getAll/')
        ->and($e->getMessage())->not->toContain('access-1')
        ->and($e->getMessage())->not->toContain('access_token')
        ->and($e->getPrevious())->toBeNull();
});

it('never puts credentials or tokens in the exception message', function () {
    $secrets = ['fake-password', 'fake-client-secret', 'access-1', 'refresh-1'];
    Http::fake([
        '*/grant/*' => moloniGrant('access-1', 'refresh-1'),
        '*/customers/getAll/*' => fn () => throw new ConnectionException(
            'cURL error 28: timed out for https://api.moloni.pt/v1/customers/getAll/?access_token=access-1&json=true'
        ),
        '*/customers/insert/*' => Http::response([
            ['code' => '2 name', 'description' => 'Echoed fake-password fake-client-secret access-1 refresh-1'],
        ]),
    ]);
    $connector = connectorWithCompany(5);

    $assertClean = function (MoloniException $e) use ($secrets) {
        $haystack = json_encode($e->errors).$e->getMessage();
        for ($previous = $e->getPrevious(); $previous; $previous = $previous->getPrevious()) {
            $haystack .= $previous->getMessage();
        }
        foreach ($secrets as $secret) {
            expect($haystack)->not->toContain($secret);
        }
    };

    $assertClean(catchMoloniException(fn () => $connector->read('customers/getAll')));
    $refusal = catchMoloniException(fn () => $connector->write('customers/insert', ['name' => 'x']));
    $assertClean($refusal);
    expect($refusal->errors[0]['description'] ?? null)->toBe('Echoed [redacted] [redacted] [redacted] [redacted]');
});

it('is registered as a singleton', function () {
    expect(app(MoloniConnector::class))->toBe(app(MoloniConnector::class))
        ->and(app(TokenStore::class))->toBe(app(TokenStore::class));
});

it('caps the HTTP timeouts so the time guard of the issuing job holds', function (int $timeout, int $connect, int $expectedTimeout, int $expectedConnect) {
    config(['moloni.timeout' => $timeout, 'moloni.connect_timeout' => $connect]);
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        if (str_contains($request->url(), '/grant/')) {
            return Http::response(moloniGrant());
        }

        $seen = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return Http::response([]);
    });

    connectorWithCompany(5)->read('customers/getAll', []);

    expect($seen)->toBe([$expectedTimeout, $expectedConnect]);
})->with([
    'within the caps' => [15, 5, 15, 5],
    'above the caps' => [120, 60, 30, 10],
    'zero' => [0, 0, 1, 1],
]);

it('never sends the token request without a timeout', function () {
    config(['moloni.timeout' => 0, 'moloni.connect_timeout' => 'none']);
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        if (str_contains($request->url(), '/grant/')) {
            $seen = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

            return Http::response(moloniGrant());
        }

        return Http::response([]);
    });

    connectorWithCompany(5)->read('customers/getAll', []);

    expect($seen)->toBe([1, 5]);
});
