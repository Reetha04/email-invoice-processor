<?php
// database/migrations/2026_07_10_create_bank_swift_details_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bank_swift_details', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('swift_code')->unique();
            $table->string('bank')->nullable();
            $table->string('branch_as_per_me')->nullable();
            $table->string('branch_as_per_bank')->nullable();
            $table->string('bank_as_per_com_bank')->nullable();
            $table->string('country_code', 2)->default('LK');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('swift_code');
            $table->index('bank_name');
            $table->index('country_code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_swift_details');
    }
};