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
     * @return list<string> One translated message per problem; empty when everything checks out.
     */
    public function problems(): array
    {
        $this->problems = [];
        // Check against what Moloni has now, not what was cached before the settings changed.
        $this->catalog->flush();

        try {
            $this->tokens->accessToken();
        } catch (MoloniException $e) {
            return [__('Could not log in to Moloni: :reason', ['reason' => $e->getMessage()])];
        }

        $companyId = $this->settings->companyIdOrNull();
        if ($companyId === null) {
            return [__('No Moloni company is configured.')];
        }

        $companyExists = $this->exists(fn () => $this->catalog->companies(), 'company_id', $companyId);
        if ($companyExists === false) {
            return [__('The company :id does not exist in Moloni or this account cannot access it.', ['id' => $companyId])];
        }
        if ($companyExists === null) {
            return $this->problems;
        }

        foreach ($this->settings->document_sets as $type => $id) {
            $this->expect($this->catalog->documentSets(...), 'document_set_id', $id,
                __('The :type document set :id does not exist in Moloni.', ['type' => $type, 'id' => $id]));
        }

        foreach ($this->settings->payment_methods as $key => $id) {
            $this->expect($this->catalog->paymentMethods(...), 'payment_method_id', $id,
                __('The :key payment method :id does not exist in Moloni.', ['key' => $key, 'id' => $id]));
        }

        foreach ($this->settings->maturity_dates as $days => $id) {
            $this->expect($this->catalog->maturityDates(...), 'maturity_date_id', $id,
                __('The :days-day maturity date :id does not exist in Moloni.', ['days' => $days, 'id' => $id]));
        }

        if ($this->settings->default_category_id !== null) {
            $this->expect($this->catalog->productCategories(...), 'category_id', $this->settings->default_category_id,
                __('The default product category :id does not exist in Moloni.', ['id' => $this->settings->default_category_id]));
        }

        if ($this->settings->default_unit_id !== null) {
            $this->expect($this->catalog->measurementUnits(...), 'unit_id', $this->settings->default_unit_id,
                __('The default measurement unit :id does not exist in Moloni.', ['id' => $this->settings->default_unit_id]));
        }

        foreach ($this->settings->tax_overrides as $percentage => $id) {
            $this->expect($this->catalog->taxes(...), 'tax_id', $id,
                __('The tax :id configured for :percentage% does not exist in Moloni.', ['id' => $id, 'percentage' => $percentage]));
        }

        foreach ($this->settings->exemption_reasons as $case => $code) {
            $this->expect($this->catalog->taxExemptions(...), 'code', $code,
                __('The exemption reason :code configured for :case does not exist in Moloni.', ['code' => $code, 'case' => $case]));
        }

        if ($this->settings->default_product_id !== null) {
            $this->checkProduct($this->settings->default_product_id);
        }

        return $this->problems;
    }

    /**
     * @param  Closure(): Collection<int, array<string, mixed>>  $list
     */
    private function expect(Closure $list, string $field, int|string $id, string $problem): void
    {
        if ($this->exists($list, $field, $id) === false) {
            $this->problems[] = $problem;
        }
    }

    /**
     * @param  Closure(): Collection<int, array<string, mixed>>  $list
     * @return bool|null Null when the list could not be read; that is recorded as a problem once.
     */
    private function exists(Closure $list, string $field, int|string $id): ?bool
    {
        try {
            $rows = $list();
        } catch (MoloniException $e) {
            $problem = __('Could not read from Moloni: :reason', ['reason' => $e->getMessage()]);
            if (! in_array($problem, $this->problems, true)) {
                $this->problems[] = $problem;
            }

            return null;
        }

        return $rows->contains(fn (array $row) => isset($row[$field]) && (string) $row[$field] === (string) $id);
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
