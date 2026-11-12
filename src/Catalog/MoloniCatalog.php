<?php

namespace Noop\Moloni\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Settings\MoloniSettings;

/**
 * Lookup lists read from Moloni, cached for a day. Company lists are cached per company.
 */
class MoloniCatalog
{
    private const TTL_SECONDS = 24 * 3600;

    /** Changing this token on flush() makes every cached list unreachable at once. */
    private const GENERATION_KEY = 'moloni.catalog.generation';

    public function __construct(private readonly MoloniConnector $connector) {}

    /** @return Collection<int, array<string, mixed>> */
    public function companies(): Collection
    {
        return $this->global('companies/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function documentSets(): Collection
    {
        return $this->forCompany('documentSets/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function paymentMethods(): Collection
    {
        return $this->forCompany('paymentMethods/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function maturityDates(): Collection
    {
        return $this->forCompany('maturityDates/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function taxes(): Collection
    {
        return $this->forCompany('taxes/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function taxExemptions(): Collection
    {
        return $this->global('taxExemptions/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function countries(): Collection
    {
        return $this->global('countries/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function languages(): Collection
    {
        return $this->global('languages/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function currencies(): Collection
    {
        return $this->global('currencies/getAll');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function measurementUnits(): Collection
    {
        return $this->forCompany('measurementUnits/getAll');
    }

    /**
     * Top-level product categories only.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function productCategories(): Collection
    {
        return $this->forCompany('productCategories/getAll', ['parent_id' => 0]);
    }

    public function countryId(string $iso): int
    {
        $country = $this->countries()->first(fn (array $row) => $this->sameCode($row['iso_3166_1'] ?? null, $iso));

        if ($country === null || ! is_numeric($country['country_id'] ?? null)) {
            throw new MoloniException("Moloni has no country with the ISO code {$iso}.", FailureType::Validation);
        }

        return (int) $country['country_id'];
    }

    /**
     * Portuguese for Portugal, English for every other country.
     */
    public function languageIdFor(string $iso): int
    {
        $code = strtoupper($iso) === 'PT' ? 'PT' : 'EN';
        $language = $this->languages()->first(fn (array $row) => $this->sameCode($row['code'] ?? null, $code));

        if ($language === null || ! is_numeric($language['language_id'] ?? null)) {
            throw new MoloniException("Moloni has no language with the code {$code}.", FailureType::Validation);
        }

        return (int) $language['language_id'];
    }

    public function flush(): void
    {
        Cache::forever(self::GENERATION_KEY, (string) Str::uuid());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function global(string $endpoint): Collection
    {
        return $this->remember($endpoint, $endpoint, []);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, array<string, mixed>>
     */
    private function forCompany(string $endpoint, array $params = []): Collection
    {
        $companyId = app(MoloniSettings::class)->companyIdOrNull();

        if ($companyId === null) {
            throw new MoloniException("No Moloni company is configured, so {$endpoint} cannot be read.", FailureType::Validation);
        }

        // Sent explicitly so the request always matches the cache key.
        return $this->remember("{$companyId}.{$endpoint}", $endpoint, [...$params, 'company_id' => $companyId]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, array<string, mixed>>
     */
    private function remember(string $key, string $endpoint, array $params): Collection
    {
        $generation = Cache::rememberForever(self::GENERATION_KEY, fn () => (string) Str::uuid());

        // A failed read throws inside the callback, so nothing is cached for it.
        $rows = Cache::remember(
            "moloni.catalog.{$generation}.{$key}",
            self::TTL_SECONDS,
            fn () => array_values(array_filter($this->connector->readAll($endpoint, $params), is_array(...))),
        );

        /** @var list<array<string, mixed>> $rows */
        return collect($rows);
    }

    private function sameCode(mixed $value, string $code): bool
    {
        return is_string($value) && strcasecmp($value, $code) === 0;
    }
}
