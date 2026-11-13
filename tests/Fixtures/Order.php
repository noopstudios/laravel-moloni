<?php

namespace Noop\Moloni\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;

/**
 * A host-app model that documents are issued for; its table comes from tests/database/migrations.
 *
 * @property int $id
 * @property string $customer_vat
 */
class Order extends Model implements Invoiceable
{
    protected $table = 'test_orders';

    protected $fillable = ['customer_vat'];

    /** @var (Closure(self): InvoiceData)|null Replaces the invoice data below; reset it in beforeEach(). */
    public static ?Closure $invoiceDataUsing = null;

    /** @var (Closure(self): string)|null Replaces the invoice reference below; reset in TestCase::setUp(). */
    public static ?Closure $referenceUsing = null;

    public function toInvoiceData(): InvoiceData
    {
        if (self::$invoiceDataUsing !== null) {
            return (self::$invoiceDataUsing)($this);
        }

        return new InvoiceData(
            new CustomerData('C'.$this->id, $this->customer_vat, 'Acme Lda', null, null, null, null),
            [new InvoiceLine('Consulting', 1, '100', 23)],
        );
    }

    public function invoiceReference(): string
    {
        if (self::$referenceUsing !== null) {
            return (self::$referenceUsing)($this);
        }

        return 'ORDER-'.$this->id;
    }

    public function customerKey(): string
    {
        return 'C'.$this->id;
    }
}
