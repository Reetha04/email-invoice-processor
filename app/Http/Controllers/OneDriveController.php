<?php

namespace App\Http\Controllers;

use App\Services\OneDriveService;
use Illuminate\Http\Request;

class OneDriveController extends Controller
{
    public function sync(Request $request)
    {
        $country = $request->get('country', 'MY');
        $month = $request->get('month', date('m'));
        
        $service = new OneDriveService();
        
        // ✅ Use the correct method name
        $result = $service->syncToStaging($country, $month);
        
        if ($result['success']) {
            $message = "✅ OneDrive Sync Complete!\n";
            $message .= "   📁 Run ID: {$result['run_id']}\n";
            $message .= "   ✅ Processed: {$result['results']['processed']}\n";
            $message .= "   ⏭️ Skipped: {$result['results']['skipped']}\n";
            $message .= "   ❌ Failed: {$result['results']['failed']}";
            
            return redirect()->back()->with('success', $message);
        }
        
        return redirect()->back()->with('error', '❌ Sync failed: ' . ($result['message'] ?? 'Unknown error'));
    }
    
    // ✅ Add method to process pending staging
    public function processStaging()
    {
        $service = new OneDriveService();
        $result = $service->processPendingStaging();
        
        return redirect()->back()->with('success', 
            "✅ Staging Processing Complete!\n" .
            "   ✅ Processed: {$result['processed']}\n" .
            "   ❌ Failed: {$result['failed']}"
        );
    }
}