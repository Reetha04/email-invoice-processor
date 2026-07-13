<?php
// app/Models/SingaporeAttraction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SingaporeAttraction extends Model
{
    protected $fillable = [
        'name',
        'category',
        'peak_type',
        'ticket_type',
        'description',
        'supplier',
        'uen',
        'price_adult',
        'price_child',
        'is_active'
    ];
}