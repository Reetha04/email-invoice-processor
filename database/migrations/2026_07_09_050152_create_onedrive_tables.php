<?php
// database/migrations/2026_07_09_000000_create_onedrive_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. ONEDRIVE IMPORTS TABLE
        Schema::create('onedrive_imports', function (Blueprint $table) {
            $table->id();
            $table->string('folder_name', 255);
            $table->string('invoice_number', 50);
            $table->string('tour_ref', 50)->nullable();
            $table->string('country_code', 5);
            $table->string('month_folder', 50);
            $table->string('date_folder', 50);
            $table->longText('tc_file_content')->nullable();
            $table->string('tc_file_path', 500)->nullable();
            $table->string('pnl_file_path', 500)->nullable();
            $table->json('extracted_data')->nullable();
            $table->enum('status', ['pending', 'processing', 'processed', 'failed', 'skipped'])->default('pending');
            $table->string('skip_reason', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index('invoice_number');
            $table->index('tour_ref');
            $table->index('status');
            $table->index('country_code');
            $table->unique(['invoice_number', 'tour_ref'], 'unique_booking');
        });

        // 2. ONEDRIVE PROCESS LOG TABLE
        Schema::create('onedrive_process_log', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 100);
            $table->string('country_code', 5);
            $table->string('month_year', 20);
            $table->integer('total_found')->default(0);
            $table->integer('total_skipped')->default(0);
            $table->integer('total_processed')->default(0);
            $table->integer('total_failed')->default(0);
            $table->json('details')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('run_id');
            $table->index('country_code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('onedrive_process_log');
        Schema::dropIfExists('onedrive_imports');
    }
};