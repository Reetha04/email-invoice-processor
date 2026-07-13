<?php
// app/Models/SupplierBankDetail.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierBankDetail extends Model
{
    protected $fillable = [
        'supplier_name',
        'uen_number',
        'ac_name',
        'account_number',
        'bank',
        'branch',
        'swift_code',
        'country_code',
        'is_active'
    ];
}