<?php
// database/migrations/2026_01_01_000002_create_supplier_bank_details_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupplierBankDetailsTable extends Migration
{
    public function up()
    {
        Schema::create('supplier_bank_details', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name');
            $table->string('uen_number')->nullable();
            $table->string('ac_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank')->nullable();
            $table->string('branch')->nullable();
            $table->string('swift_code')->nullable();
            $table->string('country_code', 2)->default('SG');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('supplier_bank_details');
    }
}