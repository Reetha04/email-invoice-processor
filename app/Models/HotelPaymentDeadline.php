<?php
// app/Models/HotelPaymentDeadline.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelPaymentDeadline extends Model
{
    protected $fillable = [
        'hotel_name', 'payment_type', 'days_before', 'country_code', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'days_before' => 'integer',
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