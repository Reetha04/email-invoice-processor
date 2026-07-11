<?php
// database/migrations/2026_07_10_create_driver_bank_details_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('driver_bank_details', function (Blueprint $table) {
            $table->id();
            $table->string('payee_name');
            $table->string('account_number')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('country_code', 2)->default('LK');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('payee_name');
            $table->index('country_code');
            $table->index(['country_code', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('driver_bank_details');
    }
};