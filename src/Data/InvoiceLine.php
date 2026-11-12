<?php

namespace Noop\Moloni\Data;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * One line of a document to issue. Moloni ids are resolved from it just before issuing.
 */
final readonly class InvoiceLine
{
    /** Two VAT rates closer than this are the same rate; a rate below it is zero. */
    public const RATE_TOLERANCE = 0.001;

    /**
     * @param  string  $unitNetPrice  Unit price without VAT, with at most 5 decimals (what Moloni stores).
     * @param  float  $taxRate  VAT percentage, e.g. 23; 0 requires an exemption reason.
     * @param  string|null  $exemptionReason  AT exemption code (e.g. M07) for a zero-rate line.
     * @param  string|null  $reference  Moloni product reference; created as a service when missing.
     * @param  int|null  $productId  Moloni product id, used as given.
     */
    public function __construct(
        public string $name,
        public float $quantity,
        public string $unitNetPrice,
        public float $taxRate,
        public ?string $exemptionReason = null,
        public ?string $reference = null,
        public ?int $productId = null,
        public string $summary = '',
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('An invoice line needs a name.');
        }

        if (! is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException("The quantity of \"{$name}\" must be greater than zero.");
        }

        if (preg_match('/^\d+(\.\d{1,5})?$/', $unitNetPrice) !== 1) {
            throw new InvalidArgumentException("The unit price of \"{$name}\" must be a non-negative number with at most 5 decimals.");
        }

        if (! is_finite($taxRate) || $taxRate < 0) {
            throw new InvalidArgumentException("The tax rate of \"{$name}\" must be zero or more.");
        }
    }

    /**
     * quantity × unit price, rounded half up to cents, as Moloni rounds each line base.
     */
    public function lineNetCents(): int
    {
        return $this->toCents(self::decimal($this->quantity)->multipliedBy($this->unitNetPrice));
    }

    /**
     * quantity × unit price × rate, rounded half up to cents: Moloni taxes each line on its
     * unrounded base and rounds the tax per line.
     */
    public function lineTaxCents(): int
    {
        if ($this->isZeroRated()) {
            return 0;
        }

        $base = self::decimal($this->quantity)->multipliedBy($this->unitNetPrice);

        return $this->toCents($base->multipliedBy(self::decimal($this->taxRate))->dividedByExact(100));
    }

    /**
     * @return array{name: string, quantity: float, unitNetPrice: string, taxRate: float, exemptionReason: ?string, reference: ?string, productId: ?int, summary: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unitNetPrice' => $this->unitNetPrice,
            'taxRate' => $this->taxRate,
            'exemptionReason' => $this->exemptionReason,
            'reference' => $this->reference,
            'productId' => $this->productId,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  As returned by toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            quantity: (float) $data['quantity'],
            unitNetPrice: (string) $data['unitNetPrice'],
            taxRate: (float) $data['taxRate'],
            exemptionReason: isset($data['exemptionReason']) ? (string) $data['exemptionReason'] : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            productId: isset($data['productId']) ? (int) $data['productId'] : null,
            summary: (string) ($data['summary'] ?? ''),
        );
    }

    public function isZeroRated(): bool
    {
        return self::isZeroRate($this->taxRate);
    }

    public static function isZeroRate(float $rate): bool
    {
        return abs($rate) < self::RATE_TOLERANCE;
    }

    private function toCents(BigDecimal $amount): int
    {
        return $amount->toScale(2, RoundingMode::HalfUp)->getUnscaledValue()->toInt();
    }

    /**
     * The float's shortest exact-round-trip form, so 0.1 is 0.1 and not 0.1000000000000000055….
     */
    private static function decimal(float $value): BigDecimal
    {
        return BigDecimal::of(var_export($value, true));
    }
}
