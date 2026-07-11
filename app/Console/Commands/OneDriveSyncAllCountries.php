<?php
// app/Console/Commands/OneDriveSyncAllCountries.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OneDriveSyncAllCountries extends Command
{
    protected $signature = 'onedrive:sync-all 
                            {--skip-process : Skip processing staging after sync}';

    protected $description = 'Sync OneDrive P&L files for ALL countries (MY, SG, VN, LK)';

    public function handle()
    {
        $skipProcess = $this->option('skip-process');

        $countries = ['MY', 'SG', 'VN', 'LK'];
        $totalFound = 0;
        $totalProcessed = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        $this->info("🌍 ==========================================");
        $this->info("🌍 SYNCING ALL COUNTRIES - OneDrive P&L");
        $this->info("🌍 ==========================================");
        $this->line("");
        $this->line("🕐 Started: " . now()->format('Y-m-d H:i:s'));
        $this->line("📁 Countries: " . implode(', ', $countries));
        $this->line("📅 Processing: July 11 onwards only");
        $this->line("");

        foreach ($countries as $country) {
            $this->line("─────────────────────────────────────────");
            $this->info("📁 Processing: {$country}");
            $this->line("");

            try {
                // ✅ Call the existing sync command
                $this->line("   ▶️ Running: php artisan onedrive:sync --country={$country}");

                // ✅ Execute the command
                $exitCode = $this->call('onedrive:sync', [
                    '--country' => $country
                ]);

                if ($exitCode === 0) {
                    $this->line("   ✅ {$country} sync completed successfully");
                    
                    // ✅ Get the results from the service directly
                    $service = new \App\Services\OneDriveService();
                    $result = $service->syncToStaging($country);
                    
                    if ($result['success']) {
                        $totalFound += $result['results']['found'];
                        $totalProcessed += $result['results']['processed'];
                        $totalSkipped += $result['results']['skipped'];
                        $totalFailed += $result['results']['failed'];
                        
                        $this->line("");
                        $this->line("   📊 {$country} Results:");
                        $this->line("      📁 Found: {$result['results']['found']}");
                        $this->line("      ✅ Processed: {$result['results']['processed']}");
                        $this->line("      ⏭️ Skipped: {$result['results']['skipped']}");
                        $this->line("      ❌ Failed: {$result['results']['failed']}");
                    }
                } else {
                    $this->error("   ❌ {$country} sync failed with exit code: {$exitCode}");
                }
                
            } catch (\Exception $e) {
                $this->error("   ❌ {$country} error: " . $e->getMessage());
                Log::error("OneDrive sync error for {$country}: " . $e->getMessage());
            }
            
            $this->line("");
        }

        // ✅ FINAL SUMMARY
        $this->line("=========================================");
        $this->info("📊 FINAL SUMMARY - ALL COUNTRIES");
        $this->line("");
        $this->line("   📁 Total Found: {$totalFound}");
        $this->line("   ✅ Total Processed: {$totalProcessed}");
        $this->line("   ⏭️ Total Skipped: {$totalSkipped}");
        $this->line("   ❌ Total Failed: {$totalFailed}");
        $this->line("");
        $this->line("🕐 Completed: " . now()->format('Y-m-d H:i:s'));
        $this->line("=========================================");

        // ✅ Process pending staging records (unless skipped)
        if (!$skipProcess && $totalProcessed > 0) {
            $this->line("");
            $this->info("🔄 Processing pending staging records...");
            $this->call('onedrive:process');
        } elseif (!$skipProcess && $totalProcessed == 0) {
            $this->line("");
            $this->info("ℹ️ No new records to process, skipping staging processing");
        }

        return 0;
    }
}