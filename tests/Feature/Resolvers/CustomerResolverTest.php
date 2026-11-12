<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Resolvers\CustomerResolver;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
    config(['moloni.prefix' => 'GR']);
    useMoloniSettings(['company_id' => 5, 'maturity_dates' => [0 => 11, 30 => 12], 'payment_methods' => ['transfer' => 21, 'card' => 22]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function crFakeEndpoints(array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/countries/getAll/*' => [['country_id' => 1, 'iso_3166_1' => 'PT'], ['country_id' => 70, 'iso_3166_1' => 'ES']],
        '*/languages/getAll/*' => [['language_id' => 1, 'code' => 'PT'], ['language_id' => 2, 'code' => 'EN']],
        '*/customers/getByVat/*' => Http::response([]),
        '*/customers/getByNumber/*' => Http::response([]),
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function crRow(array $overrides = []): array
{
    return [
        'customer_id' => 301,
        'number' => 'GR26-U12',
        'name' => 'Acme Lda',
        'vat' => '509999999',
        'address' => 'Rua Um 1',
        'city' => 'Lisboa',
        'zip_code' => '1000-001',
        'country_id' => '1',
        'email' => 'billing@acme.test',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function crCustomerData(array $overrides = []): CustomerData
{
    return new CustomerData(...[
        'key' => 'U12',
        'vat' => '509999999',
        'name' => 'Acme Lda',
        'email' => 'billing@acme.test',
        'address' => 'Rua Um 1',
        'postalCode' => '1000-001',
        'city' => 'Lisboa',
        'isBusiness' => true,
        ...$overrides,
    ]);
}

function crRequests(string $endpoint): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), "/customers/{$endpoint}/"))->count();
}

/**
 * @return array<array-key, mixed>
 */
function crSentData(string $endpoint): array
{
    $request = Http::recorded(fn (Request $request) => str_contains($request->url(), "/customers/{$endpoint}/"))->first()[0] ?? null;

    return $request instanceof Request ? $request->data() : [];
}

it('finds a customer by exact VAT and ignores partial matches', function () {
    crFakeEndpoints(['*/customers/getByVat/*' => [
        crRow(['customer_id' => 300, 'vat' => '1509999999', 'number' => 'C1']),
        crRow(['customer_id' => 299, 'vat' => '50999999', 'number' => 'C2']),
        crRow(['customer_id' => '301', 'vat' => 'PT 509999999', 'number' => 'OLD-7']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData());

    expect($resolved->id)->toBe(301)
        ->and($resolved->number)->toBe('OLD-7')
        ->and(crRequests('getByNumber'))->toBe(0)
        ->and(crRequests('insert'))->toBe(0)
        ->and(crRequests('update'))->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByVat/')
        && $request['vat'] === '509999999'
        && $request['company_id'] === 5);
});

it('prefers our number among several exact VAT matches, else the oldest customer', function (array $rows, int $expected) {
    crFakeEndpoints(['*/customers/getByVat/*' => $rows]);

    expect(app(CustomerResolver::class)->resolve(crCustomerData())->id)->toBe($expected);
})->with([
    'our number' => [[crRow(['customer_id' => 310, 'number' => 'X1']), crRow(['customer_id' => 320, 'number' => 'GR26-U12'])], 320],
    'lowest id' => [[crRow(['customer_id' => 330, 'number' => 'X1']), crRow(['customer_id' => 320, 'number' => 'X2'])], 320],
]);

it('updates name and address when they changed, without sending vat or number', function () {
    crFakeEndpoints([
        '*/customers/getByVat/*' => [crRow()],
        '*/customers/update/*' => ['valid' => 1, 'customer_id' => 301],
    ]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData(['name' => 'Acme SA', 'address' => 'Rua Dois 2']));

    expect($resolved->id)->toBe(301)
        ->and(crSentData('update'))->toBe([
            'customer_id' => 301,
            'name' => 'Acme SA',
            'address' => 'Rua Dois 2',
            'company_id' => 5,
        ]);
});

it('updates the city, postal code, country and email when they changed', function () {
    crFakeEndpoints([
        '*/customers/getByVat/*' => [crRow(['vat' => 'ESB12345674'])],
        '*/customers/update/*' => ['valid' => 1, 'customer_id' => 301],
    ]);

    app(CustomerResolver::class)->resolve(crCustomerData([
        'vat' => 'ESB12345674', 'city' => 'Madrid', 'postalCode' => '28001', 'country' => 'ES', 'email' => 'new@acme.test',
    ]));

    expect(crSentData('update'))->toBe([
        'customer_id' => 301,
        'city' => 'Madrid',
        'zip_code' => '28001',
        'country_id' => 70,
        'email' => 'new@acme.test',
        'company_id' => 5,
    ]);
});

it('does not call update when nothing changed', function () {
    crFakeEndpoints(['*/customers/getByVat/*' => [crRow(['name' => ' Acme Lda ', 'country_id' => 1])]]);

    app(CustomerResolver::class)->resolve(crCustomerData(['city' => 'Lisboa ']));

    expect(crRequests('update'))->toBe(0);
});

it('never blanks data Moloni already has', function () {
    crFakeEndpoints(['*/customers/getByVat/*' => [crRow()]]);

    app(CustomerResolver::class)->resolve(crCustomerData(['email' => null, 'address' => ' ', 'city' => null, 'postalCode' => 'invalid']));

    expect(crRequests('update'))->toBe(0);
});

it('does not search by VAT for a final consumer and suffixes the number with F', function (?string $vat) {
    crFakeEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => '401']]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData(['vat' => $vat, 'name' => 'Ana Silva', 'isBusiness' => false]));

    expect($resolved->id)->toBe(401)
        ->and($resolved->number)->toBe('GR26-U12F')
        ->and(crRequests('getByVat'))->toBe(0)
        ->and(crSentData('insert'))->toBe([
            'vat' => '999999990',
            'number' => 'GR26-U12F',
            'name' => 'Ana Silva',
            'language_id' => 1,
            'address' => 'Rua Um 1',
            'city' => 'Lisboa',
            'zip_code' => '1000-001',
            'country_id' => 1,
            'email' => 'billing@acme.test',
            'maturity_date_id' => 11,
            'payment_method_id' => 21,
            'salesman_id' => 0,
            'payment_day' => 0,
            'discount' => 0,
            'credit_limit' => 0,
            'delivery_method_id' => 0,
            'company_id' => 5,
        ]);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByNumber/') && $request['number'] === 'GR26-U12F');
})->with(['no VAT' => [null], 'generic VAT' => ['999999990']]);

it('finds a final consumer by exact number', function () {
    crFakeEndpoints(['*/customers/getByNumber/*' => [
        crRow(['customer_id' => 500, 'vat' => '999999990', 'number' => 'GR26-U12F1']),
        crRow(['customer_id' => 501, 'vat' => '999999990', 'number' => 'GR26-U12F']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData(['vat' => null]));

    expect($resolved->id)->toBe(501)
        ->and(crRequests('insert'))->toBe(0);
});

it('creates a business customer that does not exist yet', function () {
    crFakeEndpoints([
        '*/customers/getByVat/*' => [crRow(['vat' => '1509999999'])],
        '*/customers/insert/*' => ['valid' => 1, 'customer_id' => 402],
    ]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData(['vat' => 'PT 509 999 999']));

    expect($resolved->id)->toBe(402)
        ->and($resolved->number)->toBe('GR26-U12')
        ->and(crSentData('insert'))->toMatchArray(['vat' => '509999999', 'number' => 'GR26-U12', 'name' => 'Acme Lda']);
});

it('refuses a customer whose number exists in Moloni with another VAT', function () {
    crFakeEndpoints(['*/customers/getByNumber/*' => [crRow(['vat' => '501111110'])]]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData()));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('GR26-U12')
        ->and($e->getMessage())->toContain('customer key')
        ->and($e->getMessage())->not->toContain('501111110')
        ->and(crRequests('insert'))->toBe(0)
        ->and(crRequests('update'))->toBe(0);
});

it('uses the generic customer when there is no billing data at all', function (?string $vat) {
    crFakeEndpoints(['*/customers/getByNumber/*' => [
        crRow(['customer_id' => 900, 'number' => '19999']),
        crRow(['customer_id' => '901', 'number' => '9999', 'name' => 'Consumidor final']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(new CustomerData(key: 'U12', vat: $vat, name: ' ', email: null, address: '', postalCode: '1000-001', city: 'Lisboa'));

    expect($resolved->id)->toBe(901)
        ->and($resolved->number)->toBe('9999')
        ->and(crRequests('getByVat'))->toBe(0)
        ->and(crRequests('insert'))->toBe(0)
        ->and(crRequests('update'))->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByNumber/') && $request['number'] === '9999');
})->with(['no VAT' => [null], 'generic VAT' => ['999999990']]);

it('fails when the generic customer does not exist in Moloni', function () {
    crFakeEndpoints(['*/customers/getByNumber/*' => [crRow(['number' => '19999'])]]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(new CustomerData(key: 'U12', vat: null, name: null, email: null, address: null, postalCode: null, city: null)));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('9999');
});

it('builds the number from prefix, year and key within 20 characters', function () {
    $resolver = app(CustomerResolver::class);

    expect($resolver->numberFor(new CustomerData(key: 'U12', vat: '509999999', name: 'A', email: null, address: null, postalCode: null, city: null)))
        ->toBe('GR26-U12')
        ->and($resolver->numberFor(crCustomerData(['createdAt' => Carbon::parse('2025-12-31')])))->toBe('GR25-U12')
        ->and($resolver->numberFor(crCustomerData(['key' => 'ORDER-123456789'])))->toBe('GR26-ORDER-123456789')
        ->and($resolver->numberFor(crCustomerData(['key' => 'ORDER-12345678', 'vat' => null])))->toBe('GR26-ORDER-12345678F');
});

it('refuses a key that does not fit in the 20-character number instead of cutting it', function (?string $vat, int $max) {
    $e = catchMoloniException(fn () => app(CustomerResolver::class)->numberFor(crCustomerData(['key' => 'ORDER-1234567890123', 'vat' => $vat])));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('too long')
        ->and($e->getMessage())->toContain("at most {$max} characters");
})->with(['company' => ['509999999', 15], 'final consumer' => [null, 14]]);

it('refuses to build a number without a prefix or with one that leaves no room for the key', function (?string $prefix) {
    config(['moloni.prefix' => $prefix]);

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->numberFor(crCustomerData()))->type)
        ->toBe(FailureType::Validation);
})->with(['missing' => [null], 'empty' => [''], 'too long' => ['ABCDEFGHIJKLMNOPQ']]);

it('refuses to build a number without a key', function () {
    expect(catchMoloniException(fn () => app(CustomerResolver::class)->numberFor(crCustomerData(['key' => ' '])))->type)
        ->toBe(FailureType::Validation);
});

it('replaces an invalid Portuguese postal code with 0000-000', function (?string $postalCode, string $expected) {
    crFakeEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 403]]);

    app(CustomerResolver::class)->resolve(crCustomerData(['postalCode' => $postalCode, 'address' => null, 'city' => ' ']));

    expect(crSentData('insert'))->toMatchArray(['zip_code' => $expected, 'address' => 'Desconhecido', 'city' => 'Desconhecido']);
})->with([
    'invalid' => ['ABC', '0000-000'],
    'too short' => ['1000-01', '0000-000'],
    'empty' => [null, '0000-000'],
    'without dash' => ['1000001', '1000-001'],
    'valid' => [' 4000-123 ', '4000-123'],
]);

it('keeps a foreign VAT prefix and resolves the country from the catalog', function () {
    crFakeEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 404]]);

    app(CustomerResolver::class)->resolve(crCustomerData(['vat' => 'es b12345674', 'country' => 'ES', 'postalCode' => 'E-28001']));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByVat/') && $request['vat'] === 'ESB12345674');
    expect(crSentData('insert'))->toMatchArray([
        'vat' => 'ESB12345674',
        'country_id' => 70,
        'language_id' => 2,
        'zip_code' => 'E-28001',
    ]);
});

it('leaves out an empty foreign postal code', function () {
    crFakeEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 405]]);

    app(CustomerResolver::class)->resolve(crCustomerData(['vat' => 'ESB12345674', 'country' => 'ES', 'postalCode' => ' ']));

    expect(crSentData('insert'))->not->toHaveKey('zip_code');
});

it('does not create a customer without a maturity date or payment method in the settings', function (array $settings) {
    useMoloniSettings($settings);
    crFakeEndpoints();

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData()))->type)->toBe(FailureType::Validation)
        ->and(crRequests('insert'))->toBe(0);
})->with([
    'no zero-day maturity' => [['maturity_dates' => [30 => 12]]],
    'no payment method' => [['payment_methods' => []]],
]);

it('does not need the insert settings for a customer that exists', function () {
    useMoloniSettings(['maturity_dates' => [], 'payment_methods' => []]);
    crFakeEndpoints(['*/customers/getByVat/*' => [crRow()]]);

    expect(app(CustomerResolver::class)->resolve(crCustomerData())->id)->toBe(301);
});

it('uses the customer created when the insert response was lost', function () {
    crFakeEndpoints([
        '*/customers/getByVat/*' => Http::sequence()->push([])->push([crRow(['customer_id' => 406])]),
        '*/customers/insert/*' => Http::response('', 500),
    ]);

    expect(app(CustomerResolver::class)->resolve(crCustomerData())->id)->toBe(406)
        ->and(crRequests('insert'))->toBe(1)
        ->and(crRequests('getByVat'))->toBe(2)
        ->and(crRequests('update'))->toBe(0);
});

it('rethrows the lost insert when the customer still cannot be found', function (mixed $secondSearch) {
    crFakeEndpoints([
        '*/customers/getByNumber/*' => Http::sequence()->push([])->push(...$secondSearch),
        '*/customers/insert/*' => Http::response('', 500),
    ]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData(['vat' => null])));

    expect($e->type)->toBe(FailureType::Unknown)
        ->and(crRequests('insert'))->toBe(1)
        ->and(crRequests('getByNumber'))->toBe(2);
})->with([
    'not found' => [[[]]],
    'search failed' => [['', 500]],
]);

it('does not search again when Moloni refused the insert', function () {
    crFakeEndpoints(['*/customers/insert/*' => [['code' => '1 address', 'description' => "Field 'address' is required"]]]);

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData()))->type)->toBe(FailureType::Validation)
        ->and(crRequests('getByVat'))->toBe(1);
});

it('uses the customer that already exists when Moloni refuses the insert as a duplicate', function (string $code, string $search) {
    $existing = $search === 'getByNumber' ? ['vat' => '999999990', 'number' => 'GR26-U12F'] : [];
    crFakeEndpoints([
        "*/customers/{$search}/*" => Http::sequence()->push([])->push([crRow(['customer_id' => 407, ...$existing])]),
        '*/customers/insert/*' => [['code' => $code, 'description' => 'Field must be unique']],
    ]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData($search === 'getByNumber' ? ['vat' => null] : []));

    expect($resolved->id)->toBe(407)
        ->and(crRequests('insert'))->toBe(1)
        ->and(crRequests($search))->toBe(2);
})->with([
    'duplicate VAT' => ['4 vat', 'getByVat'],
    'duplicate number' => ['4 number', 'getByNumber'],
]);

it('rethrows the duplicate refusal when the customer still cannot be found', function () {
    crFakeEndpoints(['*/customers/insert/*' => [['code' => '4 vat', 'description' => "Field 'vat' must be unique"]]]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData()));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->errors[0]['code'] ?? null)->toBe('4 vat')
        ->and(crRequests('getByVat'))->toBe(2);
});

it('only updates the customer this application numbered in own mode', function () {
    crFakeEndpoints(['*/customers/getByVat/*' => [crRow(['customer_id' => 310, 'number' => 'SHOP-7'])]]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData(['name' => 'Someone Else', 'email' => 'attacker@example.test']));

    expect($resolved->id)->toBe(310)
        ->and($resolved->number)->toBe('SHOP-7')
        ->and(crRequests('update'))->toBe(0);
});

it('updates any matching customer in all mode', function () {
    config(['moloni.customer_updates' => 'all']);
    crFakeEndpoints([
        '*/customers/getByVat/*' => [crRow(['customer_id' => 310, 'number' => 'SHOP-7'])],
        '*/customers/update/*' => ['valid' => 1, 'customer_id' => 310],
    ]);

    app(CustomerResolver::class)->resolve(crCustomerData(['name' => 'Acme SA']));

    expect(crSentData('update'))->toBe(['customer_id' => 310, 'name' => 'Acme SA', 'company_id' => 5]);
});

it('never updates the generic customer', function (string $mode) {
    config(['moloni.customer_updates' => $mode]);
    crFakeEndpoints(['*/customers/getByNumber/*' => [crRow(['customer_id' => 901, 'number' => '9999', 'name' => 'Consumidor final', 'city' => 'Porto'])]]);

    $resolved = app(CustomerResolver::class)->resolve(new CustomerData(key: 'U12', vat: null, name: null, email: null, address: null, postalCode: '1000-001', city: 'Lisboa', country: 'ES'));

    expect($resolved->id)->toBe(901)
        ->and(crRequests('update'))->toBe(0);
})->with(['own', 'all']);

it('refuses an unknown customer update mode', function () {
    config(['moloni.customer_updates' => 'some']);
    crFakeEndpoints(['*/customers/getByVat/*' => [crRow()]]);

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData()))->type)->toBe(FailureType::Validation)
        ->and(crRequests('update'))->toBe(0);
});

it('uses the customer as stored when Moloni refuses the update', function () {
    crFakeEndpoints([
        '*/customers/getByVat/*' => [crRow()],
        '*/customers/update/*' => [['code' => '1 name', 'description' => "Field 'name' is required"]],
    ]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData(['name' => 'Acme SA']));

    expect($resolved->id)->toBe(301)
        ->and($resolved->number)->toBe('GR26-U12')
        ->and(crRequests('update'))->toBe(1);
});

it('lets an uncertain update fail', function (mixed $response, FailureType $type) {
    crFakeEndpoints([
        '*/customers/getByVat/*' => [crRow()],
        '*/customers/update/*' => Http::response(...$response),
    ]);

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->resolve(crCustomerData(['name' => 'Acme SA'])))->type)->toBe($type);
})->with([
    'unknown' => [['', 500], FailureType::Unknown],
    'access denied' => [['', 403], FailureType::Authentication],
]);

it('does not touch the country of an existing customer when the country is unknown', function () {
    crFakeEndpoints(['*/customers/getByVat/*' => [crRow(['country_id' => 70])]]);

    expect(app(CustomerResolver::class)->resolve(crCustomerData(['country' => null]))->id)->toBe(301)
        ->and(crRequests('update'))->toBe(0);
});

it('ignores a country Moloni does not know when updating, but not when creating', function () {
    crFakeEndpoints([
        '*/customers/getByVat/*' => Http::sequence()->push([crRow(['name' => 'Old name'])])->push([]),
        '*/customers/update/*' => ['valid' => 1, 'customer_id' => 301],
    ]);
    $resolver = app(CustomerResolver::class);

    $resolver->resolve(crCustomerData(['country' => 'XX']));

    expect(crSentData('update'))->toBe(['customer_id' => 301, 'name' => 'Acme Lda', 'company_id' => 5])
        ->and(catchMoloniException(fn () => $resolver->resolve(crCustomerData(['country' => 'XX'])))->type)->toBe(FailureType::Validation)
        ->and(crRequests('insert'))->toBe(0);
});

it('creates a customer of unknown country as Portuguese', function () {
    crFakeEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 408]]);

    app(CustomerResolver::class)->resolve(crCustomerData(['country' => null, 'postalCode' => 'ABC']));

    expect(crSentData('insert'))->toMatchArray(['country_id' => 1, 'language_id' => 1, 'zip_code' => '0000-000']);
});

it('skips rows without a usable number', function () {
    crFakeEndpoints(['*/customers/getByVat/*' => [
        crRow(['customer_id' => 100, 'number' => null]),
        crRow(['customer_id' => 101, 'number' => ['x']]),
        crRow(['customer_id' => 320, 'number' => 'X2']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(crCustomerData());

    expect($resolved->id)->toBe(320)
        ->and($resolved->number)->toBe('X2');
});
