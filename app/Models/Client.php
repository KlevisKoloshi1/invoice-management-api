<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name', 'email', 'address', 'phone', 'tin',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
