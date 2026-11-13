<?php

namespace Noop\Moloni\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Http\MoloniConnector;
use Throwable;

/**
 * Checks EU VAT numbers against the European Commission's VIES service. Never throws and never
 * caches: any failure is reported as an unavailable result.
 */
class ViesClient
{
    /** Seconds; a larger moloni.vies.timeout is capped to this. */
    private const MAX_TIMEOUT = 10;

    /** VIES answers that mean "could not check", as opposed to a definitive VALID or INVALID. */
    private const UNAVAILABLE = [
        'MS_UNAVAILABLE',
        'MS_MAX_CONCURRENT_REQ',
        'TIMEOUT',
        'SERVICE_UNAVAILABLE',
        'GLOBAL_MAX_CONCURRENT_REQ',
    ];

    /**
     * @param  string  $countryCode  ISO 3166-1 alpha-2 (GR for Greece; it is sent as EL).
     * @param  string  $vatNumber  With or without the country prefix, spaces, dots or dashes.
     */
    public function check(string $countryCode, string $vatNumber): ViesResult
    {
        $country = strtoupper($countryCode);
        $viesCountry = $country === 'GR' ? 'EL' : $country;
        // Bounded as Moloni's: zero would mean no timeout at checkout.
        $timeout = MoloniConnector::seconds(config('moloni.vies.timeout', 5), self::MAX_TIMEOUT);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('moloni.vies.base_url'), '/').'/check-vat-number', [
                    'countryCode' => $viesCountry,
                    'vatNumber' => $this->withoutPrefix($vatNumber, [$country, $viesCountry]),
                ]);

            if (! $response->successful()) {
                return $this->unavailable();
            }

            $valid = $response->json('valid');
            $userError = $response->json('userError');
            $identifier = $response->json('requestIdentifier');

            if (! is_bool($valid) || (is_string($userError) && in_array(strtoupper($userError), self::UNAVAILABLE, true))) {
                return $this->unavailable();
            }

            return new ViesResult(true, $valid, is_string($identifier) && $identifier !== '' ? $identifier : null, CarbonImmutable::now());
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function withoutPrefix(string $vatNumber, array $prefixes): string
    {
        $vat = strtoupper(str_replace([' ', '.', '-'], '', $vatNumber));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($vat, $prefix)) {
                return substr($vat, strlen($prefix));
            }
        }

        return $vat;
    }

    private function unavailable(): ViesResult
    {
        return new ViesResult(false, null, null, CarbonImmutable::now());
    }
}
