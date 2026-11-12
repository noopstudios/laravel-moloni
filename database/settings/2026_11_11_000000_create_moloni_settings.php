<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('moloni.company_id', null);
        $this->migrator->add('moloni.document_sets', []);
        $this->migrator->add('moloni.payment_methods', []);
        $this->migrator->add('moloni.maturity_dates', []);
        $this->migrator->add('moloni.default_category_id', null);
        $this->migrator->add('moloni.default_unit_id', null);
        $this->migrator->add('moloni.default_product_id', null);
        $this->migrator->add('moloni.tax_overrides', []);
        $this->migrator->add('moloni.exemption_reasons', [
            'eu_business' => 'M40',
            'non_eu_business' => 'M40',
            'non_eu_consumer' => 'M44',
        ]);
        $this->migrator->add('moloni.vies_unavailable', 'charge');
    }
};
