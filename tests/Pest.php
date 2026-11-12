<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
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

/**
 * Settings and config for issuing documents in tests: company 5, document sets 41 (invoice) and
 * 42 (invoice-receipt), payment methods card/transfer, maturity dates for 0 and 30 days, and a
 * default product, so lines without a reference need no product lookup.
 *
 * @param  array<string, mixed>  $settings
 */
function useIssueSettings(array $settings = []): void
{
    useFakeMoloniCredentials();
    config(['moloni.prefix' => 'GR', 'moloni.queue_connection' => 'sync']);
    useMoloniSettings([
        'company_id' => 5,
        'document_sets' => ['invoice' => 41, 'invoice_receipt' => 42],
        'payment_methods' => ['card' => 21, 'transfer' => 22],
        'maturity_dates' => [0 => 11, 30 => 12],
        'default_product_id' => 900,
        ...$settings,
    ]);
}

/**
 * Moloni as seen while issuing: the customer 301 (GR26-C1, VAT 509999999), a 23% tax 82, the M07
 * exemption, and document 7001 (GR/8, total 123.00) inserted and read back.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeMoloniIssuing(array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getByVat/*' => [['customer_id' => 301, 'number' => 'GR26-C1', 'vat' => '509999999', 'name' => 'Acme Lda']],
        '*/customers/getByNumber/*' => Http::response([]),
        '*/taxes/getAll/*' => [['tax_id' => 82, 'type' => 1, 'saft_type' => 1, 'value' => 23, 'fiscal_zone' => 'PT', 'active_by_default' => 1]],
        '*/taxExemptions/getAll/*' => [['code' => 'M07', 'at_code' => 'M07', 'name' => 'Isento artigo 9.º']],
        '*/invoices/insert/*' => ['valid' => 1, 'document_id' => 7001],
        '*/invoiceReceipts/insert/*' => ['valid' => 1, 'document_id' => 7001],
        '*/invoices/getAll/*' => Http::response([]),
        '*/invoiceReceipts/getAll/*' => Http::response([]),
        '*/documents/getOne/*' => moloniDocumentRow(),
        ...$overrides,
    ]);
}

/**
 * A Moloni document as documents/getOne and the getAll lists return it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function moloniDocumentRow(array $overrides = []): array
{
    return [
        'document_id' => 7001,
        'document_set_id' => 42,
        'document_set_name' => 'GR',
        'number' => 8,
        'status' => 1,
        'our_reference' => '#ORDER-1',
        'customer_id' => 301,
        'entity_vat' => '509999999',
        'gross_value' => 100,
        'net_value' => 123,
        ...$overrides,
    ];
}

/**
 * Requests to a Moloni endpoint, e.g. "invoiceReceipts/insert".
 *
 * @return list<Request>
 */
function moloniRequests(string $endpoint): array
{
    return array_values(Http::recorded(fn (Request $request) => str_contains($request->url(), "/{$endpoint}/"))
        ->map(fn (array $pair) => $pair[0])
        ->all());
}

/**
 * Runs the issuing job directly, as attempt number $attempt, with faked queue interactions.
 */
function runIssueJob(InvoiceDocument $document, int $attempt = 1): IssueDocument
{
    $fake = new FakeJob;
    $fake->attempts = $attempt;
    $job = (new IssueDocument($document))->withFakeQueueInteractions();
    $job->setJob($fake);
    app()->call([$job, 'handle']);

    return $job;
}
