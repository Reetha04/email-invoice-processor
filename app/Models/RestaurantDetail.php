<?php
// app/Models/RestaurantDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantDetail extends Model
{
    use HasFactory;

    protected $table = 'restaurant_details';

    protected $fillable = [
        'restaurant_name',
        'ac_name',
        'account_number',
        'bank',
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