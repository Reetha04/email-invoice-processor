<?php
// app/Models/HotelDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelDetail extends Model
{
    use HasFactory;

    protected $table = 'hotel_details';

    protected $fillable = [
        'hotel_name',
        'ac_name',
        'bank',
        'account_number',
        'branch',
        'bank_and_branch',
        'swift',
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