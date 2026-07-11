<?php
// app/Models/DriverBankDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverBankDetail extends Model
{
    use HasFactory;

    protected $table = 'driver_bank_details';

    protected $fillable = [
        'payee_name',
        'account_number',
        'bank_branch',
        'country_code',
        'is_active'
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