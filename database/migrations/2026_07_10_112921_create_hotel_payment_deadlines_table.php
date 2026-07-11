<?php
// database/migrations/2026_07_10_create_hotel_payment_deadlines_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('hotel_payment_deadlines', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->enum('payment_type', ['Check In', 'Check Out', '1 Days Before From Check In Date', '2 Days Before From Check In Date']);
            $table->integer('days_before')->default(0); // 0 = Check In/Out, 1 = 1 day before, 2 = 2 days before
            $table->string('country_code', 2)->default('LK');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('hotel_name');
            $table->index('country_code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('hotel_payment_deadlines');
    }
};