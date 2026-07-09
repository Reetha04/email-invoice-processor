<?php
// app/Models/OneDriveProcessLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneDriveProcessLog extends Model
{
    protected $table = 'onedrive_process_log';
    
    protected $fillable = [
        'run_id',
        'country_code',
        'month_year',
        'total_found',
        'total_skipped',
        'total_processed',
        'total_failed',
        'details',
        'started_at',
        'completed_at',
    ];
    
    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}