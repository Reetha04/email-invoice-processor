<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            // ✅ Add UNIQUE constraint on subject + body_hash combination
            $table->unique(['subject', 'body_hash'], 'unique_subject_body_hash');
        });
    }

    public function down()
    {
        Schema::table('pnl_records', function (Blueprint $table) {
            $table->dropUnique('unique_subject_body_hash');
        });
    }
};