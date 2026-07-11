<?php
// app/Http/Controllers/PayableReportController.php

namespace App\Http\Controllers;

use App\Services\PayableReportService;
use Illuminate\Http\Request;

class PayableReportController extends Controller
{
    protected $payableService;
    
    public function __construct(PayableReportService $payableService)
    {
        $this->payableService = $payableService;
    }
    
    public function index(Request $request)
    {
        $countries = [
            'LK' => '🇱🇰 Sri Lanka',
            'MY' => '🇲🇾 Malaysia',
            'SG' => '🇸🇬 Singapore',
            'VN' => '🇻🇳 Vietnam',
        ];
        
        $country = $request->get('country', 'LK');
        $targetDate = $request->get('date', now()->addDays(4)->format('Y-m-d'));
        $deadlineDays = $request->get('deadline', 4);
        
        $result = $this->payableService->generateReport($country, $targetDate, $deadlineDays);
        
        if (!$result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to generate report');
        }
        
        return view('payable-report.index', [
            'countries' => $countries,
            'selectedCountry' => $country,
            'payables' => $result['payables'] ?? [],
            'summary' => $result['summary'] ?? [],
            'exchangeRate' => $result['exchange_rate'] ?? 330,
            'date' => $result['date'] ?? now()->format('Y-m-d'),
            'targetDate' => $targetDate,
            'deadlineDays' => $deadlineDays,
            'totalCount' => $result['total_count'] ?? 0,
            'deadline' => $result['deadline'] ?? 'D-4',
            'countryName' => $countries[$country] ?? $country,
        ]);
    }
    
    public function export(Request $request)
    {
        $country = $request->get('country', 'LK');
        $targetDate = $request->get('date', now()->addDays(4)->format('Y-m-d'));
        $deadlineDays = $request->get('deadline', 4);
        
        $result = $this->payableService->generateReport($country, $targetDate, $deadlineDays);
        
        if (!$result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to generate report');
        }
        
        // Generate CSV export
        $filename = "payable_report_{$country}_{$targetDate}.csv";
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];
        
        $callback = function() use ($result) {
            $handle = fopen('php://output', 'w');
            
            // Headers
            fputcsv($handle, [
                'Tour', 'Invoice', 'Type', 'Vendor', 'Client', 
                'Check In', 'Check Out', 'USD', 'Budgeted LKR', 
                'Exchange Rate', 'Payable LKR', 'Status',
                'A/C Name', 'Bank', 'A/C No.', 'Branch', 'SWIFT'
            ]);
            
            // Data
            foreach ($result['payables'] as $payable) {
                fputcsv($handle, [
                    $payable['tour_number'] ?? 'N/A',
                    $payable['invoice_number'] ?? 'N/A',
                    $payable['vendor_type'] ?? 'Other',
                    $payable['vendor_name'] ?? 'N/A',
                    $payable['client_name'] ?? 'N/A',
                    $payable['check_in_date'] ?? $payable['start_date'] ?? '',
                    $payable['check_out_date'] ?? $payable['end_date'] ?? '',
                    number_format($payable['usd_amount'] ?? 0, 2),
                    number_format($payable['budgeted_total'] ?? 0, 2),
                    number_format($payable['exchange_rate'] ?? 1, 2),
                    number_format($payable['payable_lkr'] ?? 0, 2),
                    $payable['hold_process'] ?? 'Process',
                    $payable['ac_name'] ?? 'N/A',
                    $payable['bank'] ?? $payable['bank_branch'] ?? 'N/A',
                    $payable['account_number'] ?? 'N/A',
                    $payable['branch'] ?? $payable['bank_branch'] ?? 'N/A',
                    $payable['swift'] ?? 'N/A',
                ]);
            }
            
            fclose($handle);
        };
        
        return response()->stream($callback, 200, $headers);
    }
}