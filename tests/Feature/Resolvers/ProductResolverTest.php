<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Resolvers\ProductResolver;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
    useMoloniSettings(['company_id' => 5, 'default_category_id' => 51, 'default_unit_id' => 61]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeProductEndpoints(array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/taxes/getAll/*' => [['tax_id' => 82, 'type' => 1, 'saft_type' => 1, 'value' => 23, 'fiscal_zone' => 'PT', 'active_by_default' => 0]],
        '*/taxExemptions/getAll/*' => [['code' => 'M07', 'at_code' => 'M07']],
        ...$overrides,
    ]);
}

function productRequests(string $endpoint): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), "/products/{$endpoint}/"))->count();
}

it('uses the product id given on the line without calling Moloni', function () {
    Http::fake();

    expect(app(ProductResolver::class)->resolve(new InvoiceLine('Fee', 1, '10', 23, productId: 7)))->toBe(7);

    Http::assertNothingSent();
});

it('finds a product by exact reference, ignoring partial matches', function () {
    fakeProductEndpoints([
        '*/products/getByReference/*' => [
            ['product_id' => 301, 'reference' => 'SRV-10'],
            ['product_id' => 302, 'reference' => 'srv-1'],
            ['product_id' => 304, 'reference' => 'SRV-1'],
            ['product_id' => '303', 'reference' => 'SRV-1'],
        ],
    ]);

    expect(app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1')))->toBe(303);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/getByReference/')
        && $request['reference'] === 'SRV-1'
        && $request['exact'] === 1
        && $request['company_id'] === 5);
    expect(productRequests('insert'))->toBe(0);
});

it('looks a reference up in Moloni only once per resolver', function () {
    fakeProductEndpoints(['*/products/getByReference/*' => [['product_id' => 303, 'reference' => 'SRV-1']]]);
    $resolver = app(ProductResolver::class);

    $resolver->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1'));

    expect($resolver->resolve(new InvoiceLine('Setup', 2, '10', 23, reference: 'SRV-1')))->toBe(303)
        ->and(productRequests('getByReference'))->toBe(1);
});

it('creates a service product when the reference does not exist', function () {
    fakeProductEndpoints([
        '*/products/getByReference/*' => [['product_id' => 302, 'reference' => 'srv-1']],
        '*/products/insert/*' => ['valid' => 1, 'product_id' => '401'],
    ]);

    expect(app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 3, '8.13008', 23, reference: 'SRV-1')))->toBe(401);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/insert/')
        && $request->data() === [
            'category_id' => 51,
            'type' => 2,
            'name' => 'Setup',
            'reference' => 'SRV-1',
            'price' => 8.13008,
            'unit_id' => 61,
            'has_stock' => 0,
            'taxes' => [['tax_id' => 82, 'value' => 23.0, 'order' => 1, 'cumulative' => 0]],
            'company_id' => 5,
        ]);
});

it('creates a zero-rate product with its exemption reason and no taxes', function () {
    fakeProductEndpoints([
        '*/products/getByReference/*' => Http::response([]),
        '*/products/insert/*' => ['valid' => 1, 'product_id' => 402],
    ]);

    expect(app(ProductResolver::class)->resolve(new InvoiceLine('Export', 1, '10', 0, exemptionReason: 'M07', reference: 'EXP-1')))->toBe(402);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/insert/')
        && $request->data() === [
            'category_id' => 51,
            'type' => 2,
            'name' => 'Export',
            'reference' => 'EXP-1',
            'price' => 10.0,
            'unit_id' => 61,
            'has_stock' => 0,
            'exemption_reason' => 'M07',
            'company_id' => 5,
        ]);
});

it('does not create a zero-rate product with an invalid exemption reason', function () {
    fakeProductEndpoints(['*/products/getByReference/*' => Http::response([])]);

    $e = catchMoloniException(fn () => app(ProductResolver::class)->resolve(new InvoiceLine('Export', 1, '10', 0, exemptionReason: 'MXX', reference: 'EXP-1')));

    expect($e->type)->toBe(FailureType::Validation)
        ->and(productRequests('insert'))->toBe(0);
});

it('does not create a product without a default category or unit', function (string $setting) {
    useMoloniSettings([$setting => null]);
    fakeProductEndpoints(['*/products/getByReference/*' => Http::response([])]);

    $e = catchMoloniException(fn () => app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1')));

    expect($e->type)->toBe(FailureType::Validation)
        ->and(productRequests('insert'))->toBe(0);
})->with(['default_category_id', 'default_unit_id']);

it('uses the product created when the insert response was lost', function () {
    fakeProductEndpoints([
        '*/products/getByReference/*' => Http::sequence()
            ->push([])
            ->push([['product_id' => 403, 'reference' => 'SRV-1']]),
        '*/products/insert/*' => Http::response('', 500),
    ]);

    expect(app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1')))->toBe(403)
        ->and(productRequests('insert'))->toBe(1)
        ->and(productRequests('getByReference'))->toBe(2);
});

it('rethrows the lost insert when the product still cannot be found', function (mixed $secondSearch) {
    fakeProductEndpoints([
        '*/products/getByReference/*' => Http::sequence()->push([])->push(...$secondSearch),
        '*/products/insert/*' => Http::response('', 500),
    ]);

    $e = catchMoloniException(fn () => app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1')));

    expect($e->type)->toBe(FailureType::Unknown)
        ->and(productRequests('insert'))->toBe(1);
})->with([
    'not found' => [[[]]],
    'search failed' => [['', 500]],
]);

it('does not search again when Moloni refused the insert', function () {
    fakeProductEndpoints([
        '*/products/getByReference/*' => Http::response([]),
        '*/products/insert/*' => [['code' => '4 reference', 'description' => "Field 'reference' must be unique"]],
    ]);

    $e = catchMoloniException(fn () => app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1')));

    expect($e->type)->toBe(FailureType::Validation)
        ->and(productRequests('getByReference'))->toBe(1);
});

it('treats a confirmed insert without a product id as Unknown', function () {
    fakeProductEndpoints([
        '*/products/getByReference/*' => Http::response([]),
        '*/products/insert/*' => ['valid' => 1],
    ]);

    expect(catchMoloniException(fn () => app(ProductResolver::class)->resolve(new InvoiceLine('Setup', 1, '10', 23, reference: 'SRV-1')))->type)
        ->toBe(FailureType::Unknown);
});

it('falls back to the default product when the line has no reference', function () {
    useMoloniSettings(['default_product_id' => 71]);
    Http::fake();

    expect(app(ProductResolver::class)->resolve(new InvoiceLine('Fee', 1, '10', 23)))->toBe(71);

    Http::assertNothingSent();
});

it('throws Validation when the line has no product, no reference and there is no default product', function () {
    Http::fake();

    expect(catchMoloniException(fn () => app(ProductResolver::class)->resolve(new InvoiceLine('Fee', 1, '10', 23)))->type)
        ->toBe(FailureType::Validation);
});

it('keeps one resolver per scope', function () {
    expect(app(ProductResolver::class))->toBe(app(ProductResolver::class));

    $first = app(ProductResolver::class);
    app()->forgetScopedInstances();

    expect(app(ProductResolver::class))->not->toBe($first);
});
