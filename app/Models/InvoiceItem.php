<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'item_name', 'quantity', 'price', 'total',
        'unit', 'vat_rate', 'vat_amount', 'currency',
        'item_vat_rate', 'item_total_before_vat', 'item_vat_amount',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
