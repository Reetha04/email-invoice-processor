<?php
// app/Console/Commands/OneDriveProcessStaging.php

namespace App\Console\Commands;

use App\Services\OneDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OneDriveProcessStaging extends Command
{
    protected $signature = 'onedrive:process';
    protected $description = 'Process pending staging records';

    public function handle()
    {
        $this->info("🔄 Processing pending staging records...");
        
        try {
            $service = new OneDriveService();
            $result = $service->processPendingStaging();
            
            $this->info("✅ Processing completed!");
            $this->newLine();
            $this->line("📊 Summary:");
            $this->line("   ✅ Processed: {$result['processed']}");
            $this->line("   ❌ Failed: {$result['failed']}");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            Log::error("Staging processing failed: " . $e->getMessage());
            return 1;
        }
    }
}