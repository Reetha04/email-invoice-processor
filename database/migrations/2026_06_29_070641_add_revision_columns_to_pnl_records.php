<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->integer('revision_number')->default(0)->after('is_number');
            $table->integer('version_count')->default(0)->after('revision_number');
            $table->string('original_is_number')->nullable()->after('is_number');
            $table->boolean('is_revised')->default(false)->after('version_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn(['revision_number', 'version_count', 'original_is_number', 'is_revised']);
        });
    }
};