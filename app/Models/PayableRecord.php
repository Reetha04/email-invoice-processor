<?php
// app/Models/PayableRecord.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayableRecord extends Model
{
    protected $fillable = [
        'pnl_record_id',
        'pnl_item_id',
        'tour_number',
        'invoice_number',
        'tour_ref',
        'agent_name',
        'paid_amount',
        'balance',
        'usd_amount',
        'budgeted_total',
        'exchange_rate',
        'payable_lkr',
        'start_date',
        'end_date',
        'check_out_date',
        'vendor_type',
        'vendor_name',
        'client_name',
        'hotel_name',
        'ac_name',
        'bank',
        'account_number',
        'branch',
        'bank_and_branch',
        'swift',
        'driver_name',
        'driver_ac_name',
        'driver_account_number',
        'driver_bank_branch',
        'payment_status',
        'hold_reason',
        'advance_percentage',
        'fuel_advance',
        'tour_advance',
        'country_code',
        'item_details',
        'transport_details',
    ];

    protected $casts = [
        'item_details' => 'array',
        'transport_details' => 'array',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'usd_amount' => 'decimal:2',
        'budgeted_total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'payable_lkr' => 'decimal:2',
        'fuel_advance' => 'decimal:2',
        'tour_advance' => 'decimal:2',
        'advance_percentage' => 'decimal:2',
    ];

    public function pnlRecord()
    {
        return $this->belongsTo(PnlRecord::class, 'pnl_record_id');
    }

    public function pnlItem()
    {
        return $this->belongsTo(PnlItem::class, 'pnl_item_id');
    }
}