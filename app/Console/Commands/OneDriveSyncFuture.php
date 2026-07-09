<?php
// app/Console/Commands/OneDriveSyncFuture.php

namespace App\Console\Commands;

use App\Services\OneDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OneDriveSyncFuture extends Command
{
    protected $signature = 'onedrive:sync 
                            {--country=MY : Country code (MY, SG, VN, LK)}';
    
    protected $description = 'Sync P&L files from OneDrive (July 11 onwards only)';

    public function handle()
    {
        $country = strtoupper($this->option('country') ?? 'MY');
        
        $this->info("🔄 Syncing OneDrive P&L for {$country} (July 11 onwards)");
        $this->newLine();
        
        $service = new OneDriveService();
        $result = $service->syncToStaging($country);
        
        if ($result['success']) {
            $this->info("✅ Sync completed!");
            $this->newLine();
            $this->line("📊 Summary:");
            $this->line("   ✅ Processed: {$result['results']['processed']}");
            $this->line("   ⏭️ Skipped: {$result['results']['skipped']}");
            $this->line("   ❌ Failed: {$result['results']['failed']}");
            
            if (!empty($result['results']['details'])) {
                $this->newLine();
                $this->info("📋 Details:");
                $rows = [];
                foreach ($result['results']['details'] as $item) {
                    $rows[] = [
                        $item['folder'] ?? '-',
                        $item['invoice_number'] ?? '-',
                        $item['status'] ?? '-',
                        $item['reason'] ?? '-',
                    ];
                }
                $this->table(['Folder', 'Invoice', 'Status', 'Reason'], $rows);
            }
            
            return 0;
        } else {
            $this->error("❌ Sync failed: " . ($result['message'] ?? 'Unknown error'));
            return 1;
        }
    }
}