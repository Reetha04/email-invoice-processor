<?php
// app/Models/OneDriveImport.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OneDriveImport extends Model
{
    protected $table = 'onedrive_imports';
    
    protected $fillable = [
        'folder_name',
        'invoice_number',
        'tour_ref',
        'country_code',
        'month_folder',
        'date_folder',
        'tc_file_content',
        'tc_file_path',
        'pnl_file_path',
        'extracted_data',
        'status',
        'skip_reason',
        'error_message',
        'processed_at',
    ];
    
    protected $casts = [
        'extracted_data' => 'array',
        'processed_at' => 'datetime',
    ];
}