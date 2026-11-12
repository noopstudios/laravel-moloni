<?php

namespace Noop\Moloni\Settings;

use Closure;
use Illuminate\Support\Collection;
use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Http\TokenStore;

/**
 * Verifies that the login works and that every id in the settings exists in Moloni.
 * Shared by the moloni:check command and the settings page.
 *
 * Both checks flush the catalog cache and make about ten Moloni requests, so run them
 * only on an explicit action (the command, a save or "check" button), never on page render.
 */
class SettingsCheck
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(
        private readonly TokenStore $tokens,
        private readonly MoloniConnector $connector,
        private readonly MoloniCatalog $catalog,
        private readonly MoloniSettings $settings,
    ) {}

    /**
     * Problems with what is configured: the login, the company and every configured id must exist
     * in Moloni, and every value must be well formed. Settings left empty are not problems here.
     *
     * Flushes the catalog cache and makes about ten Moloni requests: for explicit actions only.
     *
     * @return list<string> One translated message per problem; empty when everything checks out.
     */
    public function problems(): array
    {
        $this->problems = [];
        // Check against what Moloni has now, not what was cached before the settings changed.
        $this->catalog->flush();

        // Null too while the settings do not exist yet; the other settings are only read after this.
        $companyId = $this->settings->companyIdOrNull();

        if ($companyId !== null && ! in_array($this->settings->vies_unavailable, ['charge', 'block'], true)) {
            $this->problems[] = __('The vies_unavailable setting must be "charge" or "block".');
        }

        try {
            $this->tokens->accessToken();
        } catch (MoloniException $e) {
            $this->problems[] = __('Could not log in to Moloni: :reason', ['reason' => $e->getMessage()]);

            return $this->problems;
        }

        if ($companyId === null) {
            $this->problems[] = __('No Moloni company is configured.');

            return $this->problems;
        }

        $companyExists = $this->exists($this->catalog->companies(...), $this->field('company_id', $companyId));
        if ($companyExists === false) {
            $this->problems[] = __('The company :id does not exist in Moloni or this account cannot access it.', ['id' => $companyId]);
        }
        if ($companyExists !== true) {
            return $this->problems;
        }

        foreach ($this->settings->document_sets as $type => $value) {
            $this->expectId('document_sets', $type, $value, $this->catalog->documentSets(...), 'document_set_id',
                fn (int $id) => __('The :type document set :id does not exist in Moloni.', ['type' => $type, 'id' => $id]));
        }

        foreach ($this->settings->payment_methods as $key => $value) {
            $this->expectId('payment_methods', $key, $value, $this->catalog->paymentMethods(...), 'payment_method_id',
                fn (int $id) => __('The :key payment method :id does not exist in Moloni.', ['key' => $key, 'id' => $id]));
        }

        foreach ($this->settings->maturity_dates as $days => $value) {
            $this->expectId('maturity_dates', $days, $value, $this->catalog->maturityDates(...), 'maturity_date_id',
                fn (int $id) => __('The :days-day maturity date :id does not exist in Moloni.', ['days' => $days, 'id' => $id]));
        }

        if ($this->settings->default_category_id !== null) {
            $this->expectId('default_category_id', 'default_category_id', $this->settings->default_category_id, $this->catalog->productCategories(...), 'category_id',
                fn (int $id) => __('The default category :id is not a top-level product category in Moloni.', ['id' => $id]));
        }

        if ($this->settings->default_unit_id !== null) {
            $this->expectId('default_unit_id', 'default_unit_id', $this->settings->default_unit_id, $this->catalog->measurementUnits(...), 'unit_id',
                fn (int $id) => __('The default measurement unit :id does not exist in Moloni.', ['id' => $id]));
        }

        foreach ($this->settings->tax_overrides as $percentage => $value) {
            $this->expectId('tax_overrides', $percentage, $value, $this->catalog->taxes(...), 'tax_id',
                fn (int $id) => __('The tax :id configured for :percentage% does not exist in Moloni.', ['id' => $id, 'percentage' => $percentage]));
        }

        $zone = $this->settings->fiscal_zone;
        if ($this->exists($this->catalog->taxes(...), fn (array $row) => ($row['fiscal_zone'] ?? null) === $zone) === false) {
            $this->problems[] = __('The fiscal zone :zone has no taxes in Moloni.', ['zone' => $zone]);
        }

        foreach ($this->settings->exemption_reasons as $case => $code) {
            $this->expectExemption($case, $code);
        }

        if ($this->settings->default_product_id !== null) {
            $this->checkProduct($this->settings->default_product_id);
        }

        return $this->problems;
    }

    /**
     * Everything problems() reports, plus the settings that issuing documents cannot do without.
     *
     * Flushes the catalog cache and makes about ten Moloni requests: for explicit actions only.
     *
     * @return list<string>
     */
    public function readiness(): array
    {
        $problems = $this->problems();

        if ($this->settings->companyIdOrNull() === null) {
            // The rest cannot be read before the settings exist, and means little without a company.
            return array_values(array_unique([...$problems, __('No Moloni company is configured.')]));
        }

        foreach (['invoice_receipt', 'invoice'] as $type) {
            if (! isset($this->settings->document_sets[$type])) {
                $problems[] = __('No document set is configured for :type.', ['type' => $type]);
            }
        }

        if ($this->settings->payment_methods === []) {
            $problems[] = __('No payment method is configured.');
        }

        if (! isset($this->settings->maturity_dates[0])) {
            $problems[] = __('No maturity date is configured for 0 days.');
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  Closure(): Collection<int, array<string, mixed>>  $list
     * @param  Closure(int): string  $missing
     */
    private function expectId(string $setting, int|string $key, mixed $value, Closure $list, string $field, Closure $missing): void
    {
        $id = $this->validId($value);

        if ($id === null) {
            $this->problems[] = $this->invalidValue($setting, $key);

            return;
        }

        if ($this->exists($list, $this->field($field, $id)) === false) {
            $this->problems[] = $missing($id);
        }
    }

    /**
     * Matched as invoice lines are (see MoloniCatalog::hasExemption()).
     */
    private function expectExemption(int|string $case, mixed $code): void
    {
        if (! is_string($code) || $code === '') {
            $this->problems[] = $this->invalidValue('exemption_reasons', $case);

            return;
        }

        try {
            $known = $this->catalog->hasExemption($code);
        } catch (MoloniException $e) {
            $this->readFailed($e);

            return;
        }

        if (! $known) {
            $this->problems[] = __('The exemption reason :code configured for :case does not exist in Moloni.', ['code' => $code, 'case' => $case]);
        }
    }

    private function invalidValue(string $setting, int|string $key): string
    {
        return __('The :setting setting has an invalid value for :key.', ['setting' => $setting, 'key' => $key]);
    }

    private function validId(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @return Closure(array<string, mixed>): bool
     */
    private function field(string $field, int $id): Closure
    {
        return fn (array $row) => is_numeric($row[$field] ?? null) && (int) $row[$field] === $id;
    }

    /**
     * @param  Closure(): Collection<int, array<string, mixed>>  $list
     * @param  Closure(array<string, mixed>): bool  $matches
     * @return bool|null Null when the list could not be read; that is recorded as a problem once.
     */
    private function exists(Closure $list, Closure $matches): ?bool
    {
        try {
            $rows = $list();
        } catch (MoloniException $e) {
            $this->readFailed($e);

            return null;
        }

        return $rows->contains($matches);
    }

    /**
     * Records a list that could not be read, once per reason.
     */
    private function readFailed(MoloniException $e): void
    {
        $problem = __('Could not read from Moloni: :reason', ['reason' => $e->getMessage()]);
        if (! in_array($problem, $this->problems, true)) {
            $this->problems[] = $problem;
        }
    }

    /**
     * products/getOne has no "not found": for an unknown id it returns some other product,
     * so the returned product_id must match the requested one.
     */
    private function checkProduct(int $id): void
    {
        try {
            $row = $this->connector->read('products/getOne', ['product_id' => $id]);
        } catch (MoloniException $e) {
            $this->problems[] = __('Could not read from Moloni: :reason', ['reason' => $e->getMessage()]);

            return;
        }

        if (! is_numeric($row['product_id'] ?? null) || (int) $row['product_id'] !== $id) {
            $this->problems[] = __('The default product :id does not exist in Moloni.', ['id' => $id]);
        }
    }
}
