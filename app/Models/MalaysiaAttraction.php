<?php
// app/Models/MalaysiaAttraction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MalaysiaAttraction extends Model
{
    protected $fillable = [
        'name',
        'category',
        'portal',
        'supplier',
        'uen',
        'price_adult',
        'price_child',
        'is_active'
    ];
}