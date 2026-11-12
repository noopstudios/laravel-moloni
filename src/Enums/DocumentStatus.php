<?php

namespace Noop\Moloni\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasColor, HasLabel
{
    /** Recorded; not yet sent to Moloni. */
    case Pending = 'pending';

    /** Being sent to Moloni. */
    case Issuing = 'issuing';

    /** Inserted and closed in Moloni. */
    case Issued = 'issued';

    /** Moloni rejected it, or it was never sent; nothing exists in Moloni. */
    case Failed = 'failed';

    /** A write whose response was lost; it may exist in Moloni and must be reconciled before any retry. */
    case Unknown = 'unknown';

    /** Replaced by another document; no longer counts. */
    case Superseded = 'superseded';

    /** Annulled; no longer counts. */
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Issuing => __('Issuing'),
            self::Issued => __('Issued'),
            self::Failed => __('Failed'),
            self::Unknown => __('Awaiting confirmation'),
            self::Superseded => __('Superseded'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Superseded, self::Voided => 'gray',
            self::Issuing => 'info',
            self::Issued => 'success',
            self::Failed => 'danger',
            self::Unknown => 'warning',
        };
    }

    /**
     * Exists in Moloni or may still come to: it holds its invoiceable's active key and counts
     * against the invoices it settles.
     */
    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    /**
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Pending, self::Issuing, self::Issued, self::Unknown];
    }
}
