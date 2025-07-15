<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'client_id', 'total', 'status', 'fiscalized', 'fiscalization_response', 'fiscalized_at',
        'iic', 'fic', 'tin', 'crtd', 'ord', 'bu', 'cr', 'sw', 'prc', 'fiscalization_status', 'fiscalization_url',
        'created_by',
        'number',
        'invoice_date',
        'business_unit',
        'issuer_tin',
        'invoice_type',
        'is_e_invoice',
        'operator_code',
        'software_code',
        'payment_method',
        'total_before_vat',
        'vat_amount',
        'vat_rate',
        'buyer_name',
        'buyer_address',
        'buyer_tax_number',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
