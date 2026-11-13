<?php

namespace Noop\Moloni\Filament\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Policies\InvoiceDocumentPolicy;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Settings\SettingsCheck;
use Noop\Moloni\Support\Message;

/**
 * Edits MoloniSettings with selects fed by MoloniCatalog (cached for a day; "Refresh lists"
 * reads them again). Saving runs the moloni:check readiness check and reports what it finds.
 * The credentials come from the environment only and are never shown here.
 *
 * @property-read Schema $form
 */
class ManageMoloniSettings extends Page
{
    /** The lists that need the configured company; they are read for the saved one. */
    private const COMPANY_LISTS = ['documentSets', 'paymentMethods', 'maturityDates', 'taxes', 'measurementUnits', 'productCategories'];

    /** The settings chosen from the company lists, left as they are while those cannot be read. */
    private const COMPANY_SETTINGS = ['document_sets', 'payment_methods', 'maturity_dates', 'tax_overrides', 'default_category_id', 'default_unit_id'];

    protected static ?string $slug = 'moloni-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, array<int|string, string>|null> The options of each list read for this request; null when unavailable. */
    protected array $lists = [];

    /** The first catalog read that failed in this request (often the login); the whole form waits for it. */
    protected ?string $catalogError = null;

    public static function canAccess(): bool
    {
        return InvoiceDocumentPolicy::canManage(Filament::auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('Moloni settings');
    }

    public function getTitle(): string
    {
        return __('Moloni settings');
    }

    public function mount(): void
    {
        $settings = app(MoloniSettings::class);

        $this->form->fill([
            'company_id' => $settings->company_id,
            'document_sets' => $settings->document_sets,
            'payment_methods' => self::toRows($settings->payment_methods, 'key', 'payment_method_id'),
            'maturity_dates' => self::toRows($settings->maturity_dates, 'days', 'maturity_date_id'),
            'default_category_id' => $settings->default_category_id,
            'default_unit_id' => $settings->default_unit_id,
            'default_product_id' => $settings->default_product_id,
            'tax_overrides' => self::toRows($settings->tax_overrides, 'percentage', 'tax_id'),
            'exemption_reasons' => $settings->exemption_reasons,
            'vies_unavailable' => $settings->vies_unavailable,
            'fiscal_zone' => $settings->fiscal_zone,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => $this->catalogError() !== null)
            ->components([
                Section::make(__('Moloni company'))
                    ->schema([
                        $this->catalogSelect('company_id', __('Moloni company'), 'companies'),
                        Select::make('fiscal_zone')
                            ->label(__('Fiscal zone of the company'))
                            ->options(['PT' => __('Mainland Portugal'), 'PT-AC' => __('Azores'), 'PT-MA' => __('Madeira')])
                            ->required(),
                    ]),
                Section::make(__('Document sets'))
                    ->statePath('document_sets')
                    ->columns(2)
                    ->schema([
                        $this->catalogSelect('proforma', __('Pro forma invoice'), 'documentSets'),
                        $this->catalogSelect('invoice', __('Invoice'), 'documentSets'),
                        $this->catalogSelect('invoice_receipt', __('Invoice receipt'), 'documentSets'),
                        $this->catalogSelect('receipt', __('Receipt'), 'documentSets'),
                        $this->catalogSelect('credit_note', __('Credit note'), 'documentSets'),
                    ]),
                Section::make(__('Payment settings'))
                    ->schema([
                        $this->mapRepeater('payment_methods', __('Payment methods'), 'paymentMethods', [
                            TextInput::make('key')->label(__('Payment method key'))->required()->maxLength(50),
                            $this->catalogSelect('payment_method_id', __('Moloni payment method'), 'paymentMethods')->required(),
                        ]),
                        $this->mapRepeater('maturity_dates', __('Maturity dates'), 'maturityDates', [
                            TextInput::make('days')->label(__('Days to pay'))->integer()->minValue(0)->required(),
                            $this->catalogSelect('maturity_date_id', __('Moloni maturity date'), 'maturityDates')->required(),
                        ]),
                    ]),
                Section::make(__('Products and taxes'))
                    ->columns(2)
                    ->schema([
                        $this->catalogSelect('default_category_id', __('Default product category'), 'productCategories'),
                        $this->catalogSelect('default_unit_id', __('Default measurement unit'), 'measurementUnits'),
                        TextInput::make('default_product_id')
                            ->label(__('Default Moloni product id'))
                            ->integer()
                            ->minValue(1),
                        $this->mapRepeater('tax_overrides', __('Taxes by VAT rate'), 'taxes', [
                            TextInput::make('percentage')->label(__('VAT rate (%)'))->numeric()->minValue(0)->required(),
                            $this->catalogSelect('tax_id', __('Moloni tax'), 'taxes')->required(),
                        ])->columnSpanFull(),
                    ]),
                Section::make(__('VAT exemptions'))
                    ->statePath('exemption_reasons')
                    ->columns(3)
                    ->schema([
                        $this->catalogSelect('eu_business', __('Exemption for an EU business'), 'taxExemptions'),
                        $this->catalogSelect('non_eu_business', __('Exemption for a non-EU business'), 'taxExemptions'),
                        $this->catalogSelect('non_eu_consumer', __('Exemption for a non-EU consumer'), 'taxExemptions'),
                    ]),
                Section::make(__('VIES validation'))
                    ->schema([
                        Select::make('vies_unavailable')
                            ->label(__('When VIES cannot be reached'))
                            ->options(['charge' => __('Charge VAT'), 'block' => __('Block the document')])
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(fn (): ?string => ($error = $this->catalogError()) === null ? null : Message::get('Could not read from Moloni: :reason', ['reason' => $error]))
                ->description(__('Check the Moloni credentials in the environment, then refresh the lists.'))
                ->danger()
                ->visible(fn (): bool => $this->catalogError() !== null),
            Callout::make(__('Choose the Moloni company and save: the lists that depend on it are read afterwards.'))
                ->info()
                ->visible(fn (): bool => $this->catalogError() === null && ! $this->hasCompany()),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([$this->saveAction()])]),
        ]);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('Save settings'))
            ->submit('save')
            ->disabled(fn (): bool => $this->catalogError() !== null);
    }

    /**
     * Saves what the form holds, then runs the moloni:check readiness check (about ten Moloni
     * requests, so only here) and shows each problem it finds.
     */
    public function save(): void
    {
        if ($this->catalogError() !== null) {
            return;
        }

        $state = $this->form->getState();
        $values = [
            'company_id' => self::positiveInt($state['company_id'] ?? null),
            'document_sets' => self::positiveInts($state['document_sets'] ?? []),
            'payment_methods' => self::fromRows($state['payment_methods'] ?? [], 'key', 'payment_method_id'),
            'maturity_dates' => self::fromRows($state['maturity_dates'] ?? [], 'days', 'maturity_date_id'),
            'default_category_id' => self::positiveInt($state['default_category_id'] ?? null),
            'default_unit_id' => self::positiveInt($state['default_unit_id'] ?? null),
            'default_product_id' => self::positiveInt($state['default_product_id'] ?? null),
            'tax_overrides' => self::fromRows($state['tax_overrides'] ?? [], 'percentage', 'tax_id'),
            'exemption_reasons' => array_filter(
                is_array($state['exemption_reasons'] ?? null) ? $state['exemption_reasons'] : [],
                fn (mixed $code) => is_string($code) && $code !== '',
            ),
            'vies_unavailable' => (string) ($state['vies_unavailable'] ?? 'charge'),
            'fiscal_zone' => (string) ($state['fiscal_zone'] ?? 'PT'),
        ];

        if (! $this->hasCompany()) {
            // Their selects could not be shown, so the form holds nothing for them.
            $values = array_diff_key($values, array_flip(self::COMPANY_SETTINGS));
        }

        app(MoloniSettings::class)->fill($values)->save();
        Notification::make()->success()->title(__('The Moloni settings were saved.'))->send();

        $problems = app(SettingsCheck::class)->readiness();

        if ($problems === []) {
            Notification::make()->success()->title(__('The Moloni settings are valid.'))->send();
        }

        foreach ($problems as $problem) {
            Notification::make()->danger()->title($problem)->persistent()->send();
        }

        $this->lists = [];
        $this->catalogError = null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshLists')
                ->label(__('Refresh lists'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    app(MoloniCatalog::class)->flush();
                    $this->lists = [];
                    $this->catalogError = null;

                    Notification::make()->success()->title(__('The Moloni lists were read again.'))->send();
                }),
        ];
    }

    private function catalogSelect(string $name, string $label, string $list): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(fn (): array => $this->options($list) ?? [])
            ->searchable()
            // A list that cannot be read cannot be chosen from; what is stored stays.
            ->disabled(fn (): bool => $this->options($list) === null)
            ->validatedWhenNotDehydrated(false);
    }

    /**
     * @param  array<int, TextInput|Select>  $fields
     */
    private function mapRepeater(string $name, string $label, string $list, array $fields): Repeater
    {
        return Repeater::make($name)
            ->label($label)
            ->schema($fields)
            ->columns(2)
            ->defaultItems(0)
            ->reorderable(false)
            ->addActionLabel(__('Add a row'))
            ->disabled(fn (): bool => $this->options($list) === null)
            ->validatedWhenNotDehydrated(false);
    }

    /**
     * The options of a catalog list. Every list is read at once (cached by the catalog), so the
     * first failure is known before anything renders and the rest are not attempted.
     *
     * @return array<int|string, string>|null Null when the list cannot be read.
     */
    private function options(string $list): ?array
    {
        $this->readLists();

        return $this->lists[$list] ?? null;
    }

    private function catalogError(): ?string
    {
        $this->readLists();

        return $this->catalogError;
    }

    private function hasCompany(): bool
    {
        return app(MoloniSettings::class)->companyIdOrNull() !== null;
    }

    private function readLists(): void
    {
        if ($this->lists !== [] || $this->catalogError !== null) {
            return;
        }

        $catalog = app(MoloniCatalog::class);
        $label = fn (string ...$fields): Closure => fn (array $row): string => implode(' — ', array_filter(
            array_map(fn (string $field) => is_scalar($row[$field] ?? null) ? (string) $row[$field] : '', $fields),
            fn (string $part) => $part !== '',
        ));
        $readers = [
            'companies' => [$catalog->companies(...), 'company_id', $label('name')],
            'documentSets' => [$catalog->documentSets(...), 'document_set_id', $label('name')],
            'paymentMethods' => [$catalog->paymentMethods(...), 'payment_method_id', $label('name')],
            'maturityDates' => [$catalog->maturityDates(...), 'maturity_date_id', $label('name')],
            'taxes' => [$catalog->taxes(...), 'tax_id', fn (array $row): string => $label('name', 'fiscal_zone')($row).(is_numeric($row['value'] ?? null) ? " ({$row['value']}%)" : '')],
            'taxExemptions' => [$catalog->taxExemptions(...), null, $label('code', 'name')],
            'measurementUnits' => [$catalog->measurementUnits(...), 'unit_id', $label('name')],
            'productCategories' => [$catalog->productCategories(...), 'category_id', $label('name')],
        ];
        $hasCompany = $this->hasCompany();

        foreach ($readers as $list => [$read, $key, $toLabel]) {
            if (! $hasCompany && in_array($list, self::COMPANY_LISTS, true)) {
                $this->lists[$list] = null;

                continue;
            }

            try {
                $this->lists[$list] = self::toOptions($read(), $key, $toLabel);
            } catch (MoloniException $e) {
                $this->catalogError = $e->getMessage();
                $this->lists = [];

                return;
            }
        }
    }

    /**
     * Rows keyed by their id, or exemptions by the code invoice lines carry (see
     * MoloniCatalog::hasExemption()).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Closure(array<string, mixed>): string  $toLabel
     * @return array<int|string, string>
     */
    private static function toOptions(Collection $rows, ?string $key, Closure $toLabel): array
    {
        $options = [];

        foreach ($rows as $row) {
            $atCode = $row['at_code'] ?? null;
            $value = $key !== null ? ($row[$key] ?? null) : (is_string($atCode) && $atCode !== '' ? $atCode : ($row['code'] ?? null));

            if (is_numeric($value)) {
                $options[(int) $value] = $toLabel($row);
            } elseif (is_string($value) && $value !== '') {
                $options[$value] = $toLabel($row);
            }
        }

        return $options;
    }

    /**
     * @param  array<int|string, int>  $map
     * @return list<array<string, int|string>>
     */
    private static function toRows(array $map, string $keyField, string $idField): array
    {
        $rows = [];

        foreach ($map as $key => $id) {
            $rows[] = [$keyField => $key, $idField => $id];
        }

        return $rows;
    }

    /**
     * @return array<int|string, int>
     */
    private static function fromRows(mixed $rows, string $keyField, string $idField): array
    {
        $map = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = is_array($row) ? ($row[$keyField] ?? null) : null;
            $id = self::positiveInt(is_array($row) ? ($row[$idField] ?? null) : null);

            if ($id === null || ! is_scalar($key) || (string) $key === '') {
                continue;
            }

            // "23" becomes the int key 23 as PHP does; "13.5" stays a string (see TaxResolver).
            $map[(string) $key] = $id;
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private static function positiveInts(mixed $values): array
    {
        $ids = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (($id = self::positiveInt($value)) !== null) {
                $ids[(string) $key] = $id;
            }
        }

        return $ids;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
