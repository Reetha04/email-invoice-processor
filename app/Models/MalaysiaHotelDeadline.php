<?php
// app/Models/MalaysiaHotelDeadline.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MalaysiaHotelDeadline extends Model
{
    protected $fillable = [
        'hotel_name',
        'deadline_days',
        'deadline_description',
        'is_active'
    ];
}