<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'description', 'item_name', 'quantity', 'price', 'total', 'unit',
        'vat_rate', 'vat_amount', 'currency', 'item_vat_rate', 'item_total_before_vat',
        'item_vat_amount', 'item_unit_id', 'tax_rate_id', 'item_type_id', 'item_code',
        'warehouse_id', 'item_id',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
