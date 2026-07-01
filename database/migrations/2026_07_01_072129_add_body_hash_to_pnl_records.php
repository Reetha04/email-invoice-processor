<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->string('body_hash', 32)->nullable()->after('body');
            $table->index('body_hash');
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropIndex(['body_hash']);
            $table->dropColumn('body_hash');
        });
    }
};