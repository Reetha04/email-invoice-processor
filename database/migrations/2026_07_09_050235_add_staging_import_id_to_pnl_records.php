<?php
// database/migrations/2026_07_09_000001_add_staging_import_id_to_pnl_records.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->string('source')->nullable()->default('email');
            $table->string('folder_name')->nullable();
            $table->string('hotel_name')->nullable();
            $table->string('meal_plan')->nullable();
            $table->integer('nights')->nullable();
            $table->foreignId('staging_import_id')->nullable()->constrained('onedrive_imports')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropForeign(['staging_import_id']);
            $table->dropColumn(['source', 'folder_name', 'hotel_name', 'meal_plan', 'nights', 'staging_import_id']);
        });
    }
};