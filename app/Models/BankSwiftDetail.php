<?php
// app/Models/BankSwiftDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankSwiftDetail extends Model
{
    protected $fillable = [
        'bank_name', 'swift_code', 'bank', 'branch_as_per_me',
        'branch_as_per_bank', 'bank_as_per_com_bank', 'country_code', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }
}