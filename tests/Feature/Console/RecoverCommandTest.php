<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    config(['moloni.queue_connection' => 'database']);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Queue::fake();
});

function rcmDocument(DocumentStatus $status, int $minutesAgo): InvoiceDocument
{
    travelTo(now()->subMinutes($minutesAgo));
    $document = InvoiceDocument::factory()->invoiceReceipt()->create(['status' => $status]);
    travelTo(now()->addMinutes($minutesAgo));

    return $document;
}

it('recover re-dispatches pending documents older than five minutes', function () {
    $stale = rcmDocument(DocumentStatus::Pending, 6);
    rcmDocument(DocumentStatus::Pending, 4);

    expect(Artisan::call('moloni:recover'))->toBe(0);

    Queue::assertPushed(IssueDocument::class, 1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($stale)
        && $job->connection === 'database'
        && $job->afterCommit === true);
});

it('recover re-dispatches documents stuck issuing for more than fifteen minutes', function () {
    $stuck = rcmDocument(DocumentStatus::Issuing, 16);
    rcmDocument(DocumentStatus::Issuing, 14);

    expect(Artisan::call('moloni:recover'))->toBe(0);

    Queue::assertPushed(IssueDocument::class, 1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($stuck));
});

it('recover leaves unknown, failed and settled documents alone', function (DocumentStatus $status) {
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
