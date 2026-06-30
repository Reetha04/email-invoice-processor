<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdultChildCountToPnlRecords extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->integer('adult_count')->default(0)->after('total_pax');
            $table->integer('child_count')->default(0)->after('adult_count');
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropColumn(['adult_count', 'child_count']);
        });
    }
}