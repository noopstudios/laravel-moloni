<?php

namespace Noop\Moloni\Enums;

enum DocumentType: string
{
    case Proforma = 'proforma';
    case Invoice = 'invoice';
    case InvoiceReceipt = 'invoice_receipt';
    case Receipt = 'receipt';
    case CreditNote = 'credit_note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proforma => __('Pro forma invoice'),
            self::Invoice => __('Invoice'),
            self::InvoiceReceipt => __('Invoice receipt'),
            self::Receipt => __('Receipt'),
            self::CreditNote => __('Credit note'),
        };
    }

    /**
     * Whether a link from a document of this type reduces what the linked invoice still owes.
     */
    public function settlesDebt(): bool
    {
        return in_array($this, [self::Receipt, self::CreditNote], true);
    }
}
