<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Noop\Moloni\Filament\Pages\ManageMoloniSettings;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    actingAs(User::create(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'secret']));
    Gate::define('manage moloni', fn () => true);
    useFakeMoloniCredentials();
    useMoloniSettings(['company_id' => 5]);
});

/**
 * The titles of the notifications sent so far, read as assertNotified() reads them (which takes
 * them all at once, so it cannot check several).
 *
 * @return array<mixed>
 */
function sentNotificationTitles(): array
{
    app(NotificationMailbox::class)->flush();
    $notifications = new Notifications;
    $notifications->mount();
    $notifications->pullNotifications();

    return $notifications->notifications->map(fn (Notification $notification) => $notification->getTitle())->values()->all();
}

/**
 * Moloni's lookup lists for company 5: document sets 41 (GR) and 42 (FR), payment method 21,
 * maturity date 11 (0 days), the 23% tax 82 in PT and the M40/M44 exemptions.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeSettingsCatalog(array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/companies/getAll/*' => [['company_id' => 5, 'name' => 'Acme Lda']],
        '*/documentSets/getAll/*' => [['document_set_id' => 41, 'name' => 'GR'], ['document_set_id' => 42, 'name' => 'FR']],
        '*/paymentMethods/getAll/*' => [['payment_method_id' => 21, 'name' => 'Cartão']],
        '*/maturityDates/getAll/*' => [['maturity_date_id' => 11, 'name' => 'Pronto pagamento', 'days' => 0]],
        '*/taxes/getAll/*' => [['tax_id' => 82, 'name' => 'IVA Normal', 'value' => 23, 'fiscal_zone' => 'PT']],
        '*/taxExemptions/getAll/*' => [['code' => 'M40', 'at_code' => 'M40', 'name' => 'Artigo 6.º'], ['code' => 'M44', 'at_code' => 'M44', 'name' => 'Artigo 6.º (consumidor)']],
        '*/measurementUnits/getAll/*' => [['unit_id' => 1, 'name' => 'Unidade']],
        '*/productCategories/getAll/*' => [['category_id' => 7, 'name' => 'Serviços']],
        ...$overrides,
    ]);
}

it('fills the document set select from the Moloni catalog', function () {
    fakeSettingsCatalog();

    Livewire::test(ManageMoloniSettings::class)
        ->assertFormFieldExists('document_sets.invoice', fn (Select $field) => $field->getOptions() === [41 => 'GR', 42 => 'FR'])
        ->assertFormFieldIsEnabled('document_sets.invoice')
        ->assertFormFieldExists('company_id', fn (Select $field) => $field->getOptions() === [5 => 'Acme Lda']);

    // Read once and cached: rendering again asks Moloni nothing new.
    $requests = count(Http::recorded());
    Livewire::test(ManageMoloniSettings::class);
    expect(Http::recorded())->toHaveCount($requests);
});

it('shows a failed login and disables the selects, without the credentials', function () {
    Http::fake(['*/grant/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid username and password combination'], 400)]);

    $component = Livewire::test(ManageMoloniSettings::class)
        ->assertOk()
        ->assertSee(__('Could not read from Moloni: :reason', ['reason' => 'Moloni refused the login.']))
        ->assertFormFieldIsDisabled('document_sets.invoice')
        ->assertFormFieldIsDisabled('company_id')
        ->assertActionDisabled('save');

    foreach (['fake-password', 'fake-client-secret', 'shop@example.test', 'fake-client'] as $secret) {
        $component->assertDontSee($secret);
    }

    // One login attempt for the whole page, not one per select.
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '/grant/')))->toHaveCount(1);
});

it('refreshes the lists from Moloni', function () {
    fakeSettingsCatalog(['*/documentSets/getAll/*' => Http::sequence()
        ->push([['document_set_id' => 41, 'name' => 'GR']])
        ->push([['document_set_id' => 43, 'name' => 'NC']]),
    ]);
    Livewire::test(ManageMoloniSettings::class)
        ->assertFormFieldExists('document_sets.invoice', fn (Select $field) => $field->getOptions() === [41 => 'GR']);

    Livewire::test(ManageMoloniSettings::class)
        ->callAction('refreshLists')
        ->assertNotified(__('The Moloni lists were read again.'))
        ->assertFormFieldExists('document_sets.invoice', fn (Select $field) => $field->getOptions() === [43 => 'NC']);
});

it('saves settings and reports what moloni:check finds missing', function () {
    fakeSettingsCatalog();

    Livewire::test(ManageMoloniSettings::class)
        ->fillForm([
            'document_sets' => ['invoice' => 41, 'invoice_receipt' => null],
            'default_category_id' => 7,
            'vies_unavailable' => 'block',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(sentNotificationTitles())->toBe([
        __('The Moloni settings were saved.'),
        __('No document set is configured for :type.', ['type' => 'invoice_receipt']),
        __('No payment method is configured.'),
        __('No maturity date is configured for 0 days.'),
    ]);

    $settings = app(MoloniSettings::class);
    $settings->refresh();
    expect($settings->company_id)->toBe(5)
        ->and($settings->document_sets)->toBe(['invoice' => 41])
        ->and($settings->default_category_id)->toBe(7)
        ->and($settings->vies_unavailable)->toBe('block')
        ->and($settings->exemption_reasons)->toBe(['eu_business' => 'M40', 'non_eu_business' => 'M40', 'non_eu_consumer' => 'M44']);
});

it('saves the maps and reports valid settings', function () {
    fakeSettingsCatalog();

    Livewire::test(ManageMoloniSettings::class)
        ->fillForm([
            'document_sets' => ['invoice' => 41, 'invoice_receipt' => 42],
            'payment_methods' => [['key' => 'card', 'payment_method_id' => 21]],
            'maturity_dates' => [['days' => 0, 'maturity_date_id' => 11]],
            'tax_overrides' => [['percentage' => 23, 'tax_id' => 82]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(sentNotificationTitles())->toBe([__('The Moloni settings were saved.'), __('The Moloni settings are valid.')]);

    $settings = app(MoloniSettings::class);
    $settings->refresh();
    expect($settings->payment_methods)->toBe(['card' => 21])
        ->and($settings->maturity_dates)->toBe([0 => 11])
        ->and($settings->tax_overrides)->toBe([23 => 82]);
});
