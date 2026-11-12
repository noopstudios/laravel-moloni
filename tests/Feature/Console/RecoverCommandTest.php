<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    config(['moloni.queue_connection' => 'database']);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
});

function rcmDocument(DocumentStatus $status, int $minutesAgo): InvoiceDocument
{
    travelTo(now()->subMinutes($minutesAgo));
    $document = InvoiceDocument::factory()->invoiceReceipt()->create(['status' => $status]);
    travelTo(now()->addMinutes($minutesAgo));

    return $document;
}

it('recover re-dispatches pending documents older than five minutes', function () {
    Queue::fake();
    $stale = rcmDocument(DocumentStatus::Pending, 6);
    rcmDocument(DocumentStatus::Pending, 4);

    expect(Artisan::call('moloni:recover'))->toBe(0);

    Queue::assertPushed(IssueDocument::class, 1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($stale)
        && $job->connection === 'database'
        && $job->afterCommit === true);
});

it('recover re-dispatches documents stuck issuing for more than fifteen minutes', function () {
    Queue::fake();
    $stuck = rcmDocument(DocumentStatus::Issuing, 16);
    rcmDocument(DocumentStatus::Issuing, 14);

    expect(Artisan::call('moloni:recover'))->toBe(0);

    Queue::assertPushed(IssueDocument::class, 1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($stuck));
});

it('recover leaves unknown, failed and settled documents alone', function (DocumentStatus $status) {
    Queue::fake();
    rcmDocument($status, 60);

    expect(Artisan::call('moloni:recover'))->toBe(0);

    Queue::assertNothingPushed();
})->with([DocumentStatus::Unknown, DocumentStatus::Failed, DocumentStatus::Issued, DocumentStatus::Superseded, DocumentStatus::Voided]);

it('schedules recovery every five minutes without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'moloni:recover'));

    expect($events)->toHaveCount(1)
        ->and($events->first()?->expression)->toBe('*/5 * * * *')
        ->and($events->first()?->withoutOverlapping)->toBeTrue();
});

it('recover re-dispatches issued documents whose read-back failed, and no other issued ones', function () {
    Queue::fake();
    travelTo(now()->subMinutes(6));
    $repair = InvoiceDocument::factory()->invoiceReceipt()->issued()->create(['error_type' => 'readback_failed']);
    InvoiceDocument::factory()->invoiceReceipt()->issued()->create(['error_type' => 'total_mismatch']);
    InvoiceDocument::factory()->invoiceReceipt()->create(['status' => DocumentStatus::Issued, 'error_type' => 'readback_failed']);
    travelTo(now()->addMinutes(6));

    expect(Artisan::call('moloni:recover'))->toBe(0);

    Queue::assertPushed(IssueDocument::class, 1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($repair));
});

it('recover keeps going when a document fails on a sync connection', function () {
    useIssueSettings();
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => Http::response('Unavailable', 503)]);
    // Each inline run reaches Moloni, finds it unavailable and throws for a later retry.
    rcmDocument(DocumentStatus::Pending, 6)->fill(['company_id' => 5])->save();
    rcmDocument(DocumentStatus::Pending, 7)->fill(['company_id' => 5])->save();
    travelTo(now()->addMinutes(6));

    expect(Artisan::call('moloni:recover'))->toBe(0)
        ->and(Artisan::output())->toContain('2 Moloni documents failed while being dispatched again.');
});
