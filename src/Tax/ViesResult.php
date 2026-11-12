<?php

namespace Noop\Moloni\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Noop\Moloni\Support\Payload;

/**
 * The outcome of a VIES check. Stored in the document's frozen payload as evidence of the exemption.
 */
final readonly class ViesResult
{
    /** ISO-8601 with microseconds and offset, so a date survives the round trip unchanged. */
    public const DATE_FORMAT = 'Y-m-d\\TH:i:s.uP';

    /**
     * @param  bool  $available  False when VIES could not give an answer; then $valid is null.
     * @param  bool|null  $valid  Whether VIES knows the number as valid; null when unavailable.
     * @param  string|null  $requestIdentifier  VIES' proof of the consultation, when it returned one.
     */
    public function __construct(
        public bool $available,
        public ?bool $valid,
        public ?string $requestIdentifier,
        public CarbonInterface $checkedAt,
    ) {}

    /**
     * @return array{available: bool, valid: ?bool, requestIdentifier: ?string, checkedAt: string}
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'valid' => $this->valid,
            'requestIdentifier' => $this->requestIdentifier,
            'checkedAt' => $this->checkedAt->format(self::DATE_FORMAT),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data  As returned by toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            available: (bool) ($data['available'] ?? false),
            valid: isset($data['valid']) ? (bool) $data['valid'] : null,
            requestIdentifier: isset($data['requestIdentifier']) ? (string) $data['requestIdentifier'] : null,
            checkedAt: CarbonImmutable::parse((string) Payload::required($data, 'checkedAt', 'VIES result')),
        );
    }
}
