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
function fakeCustomerEndpoints(array $overrides = []): void
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
function customerRow(array $overrides = []): array
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
function customerData(array $overrides = []): CustomerData
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

function customerRequests(string $endpoint): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), "/customers/{$endpoint}/"))->count();
}

/**
 * @return array<array-key, mixed>
 */
function sentCustomerData(string $endpoint): array
{
    $request = Http::recorded(fn (Request $request) => str_contains($request->url(), "/customers/{$endpoint}/"))->first()[0] ?? null;

    return $request instanceof Request ? $request->data() : [];
}

it('finds a customer by exact VAT and ignores partial matches', function () {
    fakeCustomerEndpoints(['*/customers/getByVat/*' => [
        customerRow(['customer_id' => 300, 'vat' => '1509999999', 'number' => 'C1']),
        customerRow(['customer_id' => 299, 'vat' => '50999999', 'number' => 'C2']),
        customerRow(['customer_id' => '301', 'vat' => 'PT 509999999', 'number' => 'OLD-7']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(customerData());

    expect($resolved->id)->toBe(301)
        ->and($resolved->number)->toBe('OLD-7')
        ->and(customerRequests('getByNumber'))->toBe(0)
        ->and(customerRequests('insert'))->toBe(0)
        ->and(customerRequests('update'))->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByVat/')
        && $request['vat'] === '509999999'
        && $request['company_id'] === 5);
});

it('prefers our number among several exact VAT matches, else the oldest customer', function (array $rows, int $expected) {
    fakeCustomerEndpoints(['*/customers/getByVat/*' => $rows]);

    expect(app(CustomerResolver::class)->resolve(customerData())->id)->toBe($expected);
})->with([
    'our number' => [[customerRow(['customer_id' => 310, 'number' => 'X1']), customerRow(['customer_id' => 320, 'number' => 'GR26-U12'])], 320],
    'lowest id' => [[customerRow(['customer_id' => 330, 'number' => 'X1']), customerRow(['customer_id' => 320, 'number' => 'X2'])], 320],
]);

it('updates name and address when they changed, without sending vat or number', function () {
    fakeCustomerEndpoints([
        '*/customers/getByVat/*' => [customerRow()],
        '*/customers/update/*' => ['valid' => 1, 'customer_id' => 301],
    ]);

    $resolved = app(CustomerResolver::class)->resolve(customerData(['name' => 'Acme SA', 'address' => 'Rua Dois 2']));

    expect($resolved->id)->toBe(301)
        ->and(sentCustomerData('update'))->toBe([
            'customer_id' => 301,
            'name' => 'Acme SA',
            'address' => 'Rua Dois 2',
            'company_id' => 5,
        ]);
});

it('updates the city, postal code, country and email when they changed', function () {
    fakeCustomerEndpoints([
        '*/customers/getByVat/*' => [customerRow(['vat' => 'ESB12345674'])],
        '*/customers/update/*' => ['valid' => 1, 'customer_id' => 301],
    ]);

    app(CustomerResolver::class)->resolve(customerData([
        'vat' => 'ESB12345674', 'city' => 'Madrid', 'postalCode' => '28001', 'country' => 'ES', 'email' => 'new@acme.test',
    ]));

    expect(sentCustomerData('update'))->toBe([
        'customer_id' => 301,
        'city' => 'Madrid',
        'zip_code' => '28001',
        'country_id' => 70,
        'email' => 'new@acme.test',
        'company_id' => 5,
    ]);
});

it('does not call update when nothing changed', function () {
    fakeCustomerEndpoints(['*/customers/getByVat/*' => [customerRow(['name' => ' Acme Lda ', 'country_id' => 1])]]);

    app(CustomerResolver::class)->resolve(customerData(['city' => 'Lisboa ']));

    expect(customerRequests('update'))->toBe(0);
});

it('never blanks data Moloni already has', function () {
    fakeCustomerEndpoints(['*/customers/getByVat/*' => [customerRow()]]);

    app(CustomerResolver::class)->resolve(customerData(['email' => null, 'address' => ' ', 'city' => null, 'postalCode' => 'invalid']));

    expect(customerRequests('update'))->toBe(0);
});

it('does not search by VAT for a final consumer and suffixes the number with F', function (?string $vat) {
    fakeCustomerEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => '401']]);

    $resolved = app(CustomerResolver::class)->resolve(customerData(['vat' => $vat, 'name' => 'Ana Silva', 'isBusiness' => false]));

    expect($resolved->id)->toBe(401)
        ->and($resolved->number)->toBe('GR26-U12F')
        ->and(customerRequests('getByVat'))->toBe(0)
        ->and(sentCustomerData('insert'))->toBe([
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
    fakeCustomerEndpoints(['*/customers/getByNumber/*' => [
        customerRow(['customer_id' => 500, 'vat' => '999999990', 'number' => 'GR26-U12F1']),
        customerRow(['customer_id' => 501, 'vat' => '999999990', 'number' => 'GR26-U12F']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(customerData(['vat' => null]));

    expect($resolved->id)->toBe(501)
        ->and(customerRequests('insert'))->toBe(0);
});

it('creates a business customer that does not exist yet', function () {
    fakeCustomerEndpoints([
        '*/customers/getByVat/*' => [customerRow(['vat' => '1509999999'])],
        '*/customers/insert/*' => ['valid' => 1, 'customer_id' => 402],
    ]);

    $resolved = app(CustomerResolver::class)->resolve(customerData(['vat' => 'PT 509 999 999']));

    expect($resolved->id)->toBe(402)
        ->and($resolved->number)->toBe('GR26-U12')
        ->and(sentCustomerData('insert'))->toMatchArray(['vat' => '509999999', 'number' => 'GR26-U12', 'name' => 'Acme Lda']);
});

it('refuses a customer whose number exists in Moloni with another VAT', function () {
    fakeCustomerEndpoints(['*/customers/getByNumber/*' => [customerRow(['vat' => '501111110'])]]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(customerData()));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('501111110')
        ->and(customerRequests('insert'))->toBe(0)
        ->and(customerRequests('update'))->toBe(0);
});

it('uses the generic customer when there is no billing data at all', function (?string $vat) {
    fakeCustomerEndpoints(['*/customers/getByNumber/*' => [
        customerRow(['customer_id' => 900, 'number' => '19999']),
        customerRow(['customer_id' => '901', 'number' => '9999', 'name' => 'Consumidor final']),
    ]]);

    $resolved = app(CustomerResolver::class)->resolve(new CustomerData(key: 'U12', vat: $vat, name: ' ', email: null, address: '', postalCode: '1000-001', city: 'Lisboa'));

    expect($resolved->id)->toBe(901)
        ->and($resolved->number)->toBe('9999')
        ->and(customerRequests('getByVat'))->toBe(0)
        ->and(customerRequests('insert'))->toBe(0)
        ->and(customerRequests('update'))->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByNumber/') && $request['number'] === '9999');
})->with(['no VAT' => [null], 'generic VAT' => ['999999990']]);

it('fails when the generic customer does not exist in Moloni', function () {
    fakeCustomerEndpoints(['*/customers/getByNumber/*' => [customerRow(['number' => '19999'])]]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(new CustomerData(key: 'U12', vat: null, name: null, email: null, address: null, postalCode: null, city: null)));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('9999');
});

it('builds the number from prefix, year and key within 20 characters', function () {
    $resolver = app(CustomerResolver::class);

    expect($resolver->numberFor(new CustomerData(key: 'U12', vat: '509999999', name: 'A', email: null, address: null, postalCode: null, city: null)))
        ->toBe('GR26-U12')
        ->and($resolver->numberFor(customerData(['createdAt' => Carbon::parse('2025-12-31')])))->toBe('GR25-U12')
        ->and($resolver->numberFor(customerData(['key' => 'ORDER-1234567890123'])))->toBe('GR26-ORDER-123456789')
        ->and($resolver->numberFor(customerData(['key' => 'ORDER-1234567890123', 'vat' => null])))->toBe('GR26-ORDER-12345678F');
});

it('refuses to build a number without a prefix or with one that leaves no room for the key', function (?string $prefix) {
    config(['moloni.prefix' => $prefix]);

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->numberFor(customerData()))->type)
        ->toBe(FailureType::Validation);
})->with(['missing' => [null], 'empty' => [''], 'too long' => ['ABCDEFGHIJKLMNOPQ']]);

it('refuses to build a number without a key', function () {
    expect(catchMoloniException(fn () => app(CustomerResolver::class)->numberFor(customerData(['key' => ' '])))->type)
        ->toBe(FailureType::Validation);
});

it('replaces an invalid Portuguese postal code with 0000-000', function (?string $postalCode, string $expected) {
    fakeCustomerEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 403]]);

    app(CustomerResolver::class)->resolve(customerData(['postalCode' => $postalCode, 'address' => null, 'city' => ' ']));

    expect(sentCustomerData('insert'))->toMatchArray(['zip_code' => $expected, 'address' => 'Desconhecido', 'city' => 'Desconhecido']);
})->with([
    'invalid' => ['ABC', '0000-000'],
    'too short' => ['1000-01', '0000-000'],
    'empty' => [null, '0000-000'],
    'without dash' => ['1000001', '1000-001'],
    'valid' => [' 4000-123 ', '4000-123'],
]);

it('keeps a foreign VAT prefix and resolves the country from the catalog', function () {
    fakeCustomerEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 404]]);

    app(CustomerResolver::class)->resolve(customerData(['vat' => 'es b12345674', 'country' => 'ES', 'postalCode' => 'E-28001']));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/customers/getByVat/') && $request['vat'] === 'ESB12345674');
    expect(sentCustomerData('insert'))->toMatchArray([
        'vat' => 'ESB12345674',
        'country_id' => 70,
        'language_id' => 2,
        'zip_code' => 'E-28001',
    ]);
});

it('leaves out an empty foreign postal code', function () {
    fakeCustomerEndpoints(['*/customers/insert/*' => ['valid' => 1, 'customer_id' => 405]]);

    app(CustomerResolver::class)->resolve(customerData(['vat' => 'ESB12345674', 'country' => 'ES', 'postalCode' => ' ']));

    expect(sentCustomerData('insert'))->not->toHaveKey('zip_code');
});

it('does not create a customer without a maturity date or payment method in the settings', function (array $settings) {
    useMoloniSettings($settings);
    fakeCustomerEndpoints();

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->resolve(customerData()))->type)->toBe(FailureType::Validation)
        ->and(customerRequests('insert'))->toBe(0);
})->with([
    'no zero-day maturity' => [['maturity_dates' => [30 => 12]]],
    'no payment method' => [['payment_methods' => []]],
]);

it('does not need the insert settings for a customer that exists', function () {
    useMoloniSettings(['maturity_dates' => [], 'payment_methods' => []]);
    fakeCustomerEndpoints(['*/customers/getByVat/*' => [customerRow()]]);

    expect(app(CustomerResolver::class)->resolve(customerData())->id)->toBe(301);
});

it('uses the customer created when the insert response was lost', function () {
    fakeCustomerEndpoints([
        '*/customers/getByVat/*' => Http::sequence()->push([])->push([customerRow(['customer_id' => 406])]),
        '*/customers/insert/*' => Http::response('', 500),
    ]);

    expect(app(CustomerResolver::class)->resolve(customerData())->id)->toBe(406)
        ->and(customerRequests('insert'))->toBe(1)
        ->and(customerRequests('getByVat'))->toBe(2)
        ->and(customerRequests('update'))->toBe(0);
});

it('rethrows the lost insert when the customer still cannot be found', function (mixed $secondSearch) {
    fakeCustomerEndpoints([
        '*/customers/getByNumber/*' => Http::sequence()->push([])->push(...$secondSearch),
        '*/customers/insert/*' => Http::response('', 500),
    ]);

    $e = catchMoloniException(fn () => app(CustomerResolver::class)->resolve(customerData(['vat' => null])));

    expect($e->type)->toBe(FailureType::Unknown)
        ->and(customerRequests('insert'))->toBe(1)
        ->and(customerRequests('getByNumber'))->toBe(2);
})->with([
    'not found' => [[[]]],
    'search failed' => [['', 500]],
]);

it('does not search again when Moloni refused the insert', function () {
    fakeCustomerEndpoints(['*/customers/insert/*' => [['code' => '4 vat', 'description' => "Field 'vat' must be unique"]]]);

    expect(catchMoloniException(fn () => app(CustomerResolver::class)->resolve(customerData()))->type)->toBe(FailureType::Validation)
        ->and(customerRequests('getByVat'))->toBe(1);
});
