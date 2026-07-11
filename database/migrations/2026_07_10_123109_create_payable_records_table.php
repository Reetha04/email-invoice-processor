<?php
// database/migrations/2026_07_10_create_payable_records_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payable_records', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->unsignedBigInteger('pnl_record_id')->nullable();
            $table->unsignedBigInteger('pnl_item_id')->nullable();
            
            // Tour & Invoice
            $table->string('tour_number')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('tour_ref')->nullable();
            $table->string('agent_name')->nullable();
            
            // Financial
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('usd_amount', 15, 2)->default(0);
            $table->decimal('budgeted_total', 15, 2)->default(0);
            $table->decimal('exchange_rate', 10, 4)->default(1);
            $table->decimal('payable_lkr', 15, 2)->default(0);
            
            // Dates
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('check_out_date')->nullable();
            
            // Vendor Info
            $table->string('vendor_type')->nullable(); // HOTEL, TRANSPORT, ATTRACTION, TOUR TRANSFER, MEALS
            $table->string('vendor_name')->nullable();
            $table->string('client_name')->nullable();
            $table->string('hotel_name')->nullable();
            
            // Bank Details
            $table->string('ac_name')->nullable();
            $table->string('bank')->nullable();
            $table->string('account_number')->nullable();
            $table->string('branch')->nullable();
            $table->string('bank_and_branch')->nullable();
            $table->string('swift')->nullable();
            
            // Driver Details
            $table->string('driver_name')->nullable();
            $table->string('driver_ac_name')->nullable();
            $table->string('driver_account_number')->nullable();
            $table->string('driver_bank_branch')->nullable();
            
            // Payment Status
            $table->string('payment_status')->default('pending');
            $table->string('hold_reason')->nullable();
            $table->decimal('advance_percentage', 5, 2)->default(0);
            $table->decimal('fuel_advance', 15, 2)->default(0);
            $table->decimal('tour_advance', 15, 2)->default(0);
            
            // Country
            $table->string('country_code', 2)->default('LK');
            
            // Additional
            $table->json('item_details')->nullable();
            $table->json('transport_details')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('pnl_record_id');
            $table->index('pnl_item_id');
            $table->index('tour_number');
            $table->index('invoice_number');
            $table->index('vendor_type');
            $table->index('country_code');
            $table->index('payment_status');
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('payable_records');
    }
};