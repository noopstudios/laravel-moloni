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
        && $request['refresh_token'] === 'refresh-1');
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

it('classifies valid:0 as Validation with the field errors', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/invoices/insert/*' => ['valid' => 0, 'errors' => [['code' => '2 customer_id', 'description' => 'Customer is required']]],
    ]);

    $e = catchMoloniException(fn () => connectorWithCompany(5)->write('invoices/insert', []));

    expect($e->type)->toBe(FailureType::Validation)
        ->and(json_encode($e->errors))->toContain('Customer is required');
});

it('classifies a field error list as Validation with the field errors', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/insert/*' => Http::response([
            ['code' => '2 vat', 'description' => 'VAT number is invalid'],
            ['code' => '1 name', 'description' => 'Name is required'],
        ], 400),
    ]);

    $e = catchMoloniException(fn () => connectorWithCompany(5)->write('customers/insert', ['vat' => 'x']));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->errors)->toBe([
            ['code' => '2 vat', 'description' => 'VAT number is invalid'],
            ['code' => '1 name', 'description' => 'Name is required'],
        ]);
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
    'no valid flag' => [['document_id' => 1], 200],
    'empty list' => [[], 200],
    'other client error' => [['error' => 'Bad Request'], 400],
]);

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

it('never puts credentials or tokens in the exception message', function () {
    $secrets = ['fake-password', 'fake-client-secret', 'access-1', 'refresh-1'];
    Http::fake([
        '*/grant/*' => moloniGrant('access-1', 'refresh-1'),
        '*/customers/getAll/*' => fn () => throw new ConnectionException(
            'cURL error 28: timed out for https://api.moloni.pt/v1/customers/getAll/?access_token=access-1&json=true'
        ),
        '*/customers/insert/*' => Http::response([
            ['code' => '2 name', 'description' => 'Echoed fake-password fake-client-secret access-1 refresh-1'],
        ], 400),
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
    $assertClean(catchMoloniException(fn () => $connector->write('customers/insert', ['name' => 'x'])));
});

it('is registered as a singleton', function () {
    expect(app(MoloniConnector::class))->toBe(app(MoloniConnector::class))
        ->and(app(TokenStore::class))->toBe(app(TokenStore::class));
});
