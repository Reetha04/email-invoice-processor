<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_items', function (Blueprint $table) {
            $table->string('matched_service_name')->nullable()->after('service_name');
            $table->decimal('match_confidence', 5, 4)->nullable()->after('matched_service_name');
            $table->timestamp('matched_at')->nullable()->after('match_confidence');
        });
    }

    public function down()
    {
        Schema::table('pnl_items', function (Blueprint $table) {
            $table->dropColumn(['matched_service_name', 'match_confidence', 'matched_at']);
        });
    }
};