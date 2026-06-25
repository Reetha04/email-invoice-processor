<?php

namespace App\Http\Controllers;

use App\Services\HotelDateMatcherService;
use Illuminate\Http\Request;

class HotelMatchController extends Controller
{
    protected $matcher;
    
    public function __construct(HotelDateMatcherService $matcher)
    {
        $this->matcher = $matcher;
    }
    
    /**
     * Match hotel dates for a specific PNL record
     */
    public function matchHotel($pnlRecordId)
    {
        $result = $this->matcher->matchHotelDates($pnlRecordId);
        
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => "Matched {$result['updated_count']} hotels",
                'data' => $result
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => $result['message']
        ], 400);
    }
    
    /**
     * Preview hotel match without updating
     */
    public function preview($pnlRecordId)
    {
        $result = $this->matcher->previewHotelMatch($pnlRecordId);
        
        return response()->json($result);
    }
    
    /**
     * Match all PNL records
     */
    public function matchAll()
    {
        $results = $this->matcher->matchAllPnLRecords();
        
        $total = count($results);
        $successful = 0;
        $totalUpdated = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $successful++;
                $totalUpdated += $result['updated_count'];
            }
        }
        
        return response()->json([
            'success' => true,
            'total_records' => $total,
            'successful' => $successful,
            'total_hotels_updated' => $totalUpdated,
            'results' => $results
        ]);
    }
}