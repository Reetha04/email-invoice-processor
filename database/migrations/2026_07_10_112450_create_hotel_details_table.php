<?php
// database/migrations/2026_07_10_create_hotel_details_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('hotel_details', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->string('ac_name')->nullable();
            $table->string('bank')->nullable();
            $table->string('account_number')->nullable();
            $table->string('branch')->nullable();
            $table->string('bank_and_branch')->nullable();
            $table->string('swift')->nullable();
            $table->string('country_code', 2)->default('LK');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes for faster queries
            $table->index('hotel_name');
            $table->index('country_code');
            $table->index(['country_code', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('hotel_details');
    }
};