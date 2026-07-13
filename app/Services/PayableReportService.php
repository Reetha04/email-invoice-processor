<?php
// app/Services/PayableReportService.php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use App\Models\HotelDetail;
use App\Models\DriverBankDetail;
use Illuminate\Support\Facades\Log;

class PayableReportService
{
    protected $exchangeRateService;
    
    public function __construct(ExchangeRateServiceLKR $exchangeRateService)
    {
        $this->exchangeRateService = $exchangeRateService;
    }
    
    /**
     * Generate payable report - HOTELS & TRANSPORT
     */
    public function generateReport($country = 'LK', $targetDate = null, $deadlineDays = 4)
    {
        try {
            $deadlineDays = (int) $deadlineDays;
            
            if (!$targetDate) {
                $targetDate = now()->format('Y-m-d');
            }
            
            // ✅ Calculate check-in date to look for = targetDate + deadlineDays
            $checkInDateToFind = date('Y-m-d', strtotime($targetDate . ' + ' . $deadlineDays . ' days'));
            
            Log::info("📅 Today (Target Date): {$targetDate}");
            Log::info("📅 Looking for check-in on (D-{$deadlineDays}): {$checkInDateToFind}");
            
            // Get exchange rate from CBSL
            $exchangeRate = $this->exchangeRateService->getUSDtoLKR();
            
            // ✅ Get PNL records with items - HOTELS & TRANSPORT
            $records = PnlRecord::where('country_code', $country)
                ->where('status', 'pending')
                ->with(['items' => function($query) {
                    $query->whereIn('type', ['HOTEL', 'TRANSPORT']);
                }])
                ->get();
            
            $hotels = [];
            $transportGroups = [];
            
            foreach ($records as $record) {
                foreach ($record->items as $item) {
                    $type = strtoupper($item->type ?? 'OTHER');
                    
                    if ($type === 'HOTEL') {
                        $hotel = $this->processHotelItem($item, $record, $exchangeRate, $checkInDateToFind);
                        if ($hotel) {
                            $hotels[] = $hotel;
                        }
                    } elseif ($type === 'TRANSPORT') {
                        // ✅ Group transport by tour_ref
                        $tourRef = $record->tour_ref ?? $record->invoice_number ?? 'N/A';
                        
                        // Get start date from item
                        $startDate = $item->start_date ?? $item->check_in_date ?? null;
                        
                        // Only include if start date matches target
                        if ($startDate) {
                            $startDateStr = date('Y-m-d', strtotime($startDate));
                            if ($startDateStr !== $checkInDateToFind) {
                                continue;
                            }
                        }
                        
                        if (!isset($transportGroups[$tourRef])) {
                            $transportGroups[$tourRef] = [
                                'tour_number' => $tourRef,
                                'invoice_number' => $record->invoice_number ?? 'N/A',
                                'agent_name' => $record->agent_name ?? 'N/A',
                                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                                'start_date' => $startDate,
                                'end_date' => $item->end_date ?? $item->check_out_date ?? null,
                                'items' => [],
                                'total_usd' => 0,
                                'total_lkr' => 0,
                                'record_id' => $record->id,
                                'country_code' => $record->country_code ?? 'LK',
                            ];
                        }
                        
                        $usdAmount = $item->amount_original ?? 0;
                        $lkrAmount = $usdAmount * $exchangeRate;
                        
                        $transportGroups[$tourRef]['items'][] = [
                            'service_name' => $item->service_name ?? 'N/A',
                            'usd_amount' => $usdAmount,
                            'lkr_amount' => $lkrAmount,
                            'details' => $item->item_details ?? [],
                        ];
                        
                        $transportGroups[$tourRef]['total_usd'] += $usdAmount;
                        $transportGroups[$tourRef]['total_lkr'] += $lkrAmount;
                    }
                }
            }
            
            // ✅ Convert transport groups to payable format
            $transportPayables = [];
            foreach ($transportGroups as $tourRef => $group) {
                $transportPayables[] = $this->processTransportGroup($group, $exchangeRate);
            }
            
            // ✅ Combine hotels and transport
            $payables = array_merge($hotels, $transportPayables);
            
            // Sort by check-in date
            usort($payables, function($a, $b) {
                return strtotime($a['check_in_date'] ?? '') - strtotime($b['check_in_date'] ?? '');
            });
            
            return [
                'success' => true,
                'payables' => $payables,
                'summary' => [
                    'hotels' => count($hotels),
                    'transport' => count($transportPayables),
                    'attraction' => 0,
                    'tour_transfers' => 0,
                    'meals' => 0,
                ],
                'exchange_rate' => $exchangeRate,
                'date' => now()->format('Y-m-d'),
                'target_date' => $targetDate,
                'check_in_date' => $checkInDateToFind,
                'deadline_days' => $deadlineDays,
                'total_count' => count($payables),
                'deadline' => "D-{$deadlineDays}",
            ];
            
        } catch (\Exception $e) {
            Log::error("Payable report generation failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
// app/Services/PayableReportService.php

/**
 * Process HOTEL items
 */
protected function processHotelItem($item, $record, $exchangeRate, $checkInDateToFind)
{
    try {
        $hotelName = $item->hotel_name ?? $item->service_name ?? null;
        
        if (!$hotelName) {
            return null;
        }
        
        $checkInDate = $item->check_in_date ?? $item->start_date ?? null;
        if (!$checkInDate) {
            return null;
        }
        
        // ✅ Check if check-in date matches target
        $checkInDateStr = date('Y-m-d', strtotime($checkInDate));
        if ($checkInDateStr !== $checkInDateToFind) {
            return null;
        }
        
        $usdAmount = $item->amount_original ?? 0;
        $budgetedLKR = $usdAmount * $exchangeRate;
        
        $hotelDetail = HotelDetail::where('hotel_name', 'LIKE', "%{$hotelName}%")
            ->where('country_code', $record->country_code ?? 'LK')
            ->first();
        
        $checkOutDate = $item->check_out_date ?? $item->end_date ?? null;
        
        return [
            'type' => 'HOTEL',
            'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
            'invoice_number' => $record->invoice_number ?? null,
            'vendor_name' => $hotelName,  // ✅ Hotel Name
            'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
            'agent_name' => $record->agent_name ?? 'N/A',
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'usd_amount' => $usdAmount,
            'budgeted_total' => $budgetedLKR,
            'exchange_rate' => $exchangeRate,
            'payable_lkr' => $budgetedLKR,
            'hold_process' => 'Process',
            
            // Hotel bank details
            'ac_name' => $hotelDetail->ac_name ?? 'N/A',
            'bank' => $hotelDetail->bank ?? 'N/A',
            'account_number' => $hotelDetail->account_number ?? 'N/A',
            'branch' => $hotelDetail->branch ?? 'N/A',
            'bank_and_branch' => $hotelDetail->bank_and_branch ?? 'N/A',
            'swift' => $hotelDetail->swift ?? 'N/A',
            
            // Transport fields (N/A for hotels)
            'advance_percent' => null,
            'fuel_advance' => null,
            'tour_advance' => null,
            'driver_name' => 'N/A',
            'driver_account' => 'N/A',
            'driver_bank' => 'N/A',
            'transport_details' => null,
            
            'item_id' => $item->id,
            'record_id' => $record->id,
        ];
        
    } catch (\Exception $e) {
        Log::error("Error processing hotel item: " . $e->getMessage());
        return null;
    }
}
    
    /**
     * Process TRANSPORT group - Grouped by Tour
     */
    protected function processTransportGroup($group, $exchangeRate)
    {
        try {
            // ✅ Get driver bank details
            $driverDetail = DriverBankDetail::where('country_code', $group['country_code'] ?? 'LK')
                ->first();
            
            // ✅ Calculate advance (30% of total)
            $totalUSD = $group['total_usd'];
            $totalLKR = $group['total_lkr'];
            $advancePercent = 30; // 30% advance
            $fuelAdvance = $totalLKR * ($advancePercent / 100);
            $tourAdvance = $totalLKR - $fuelAdvance;
            
            // ✅ Build transport details string
            $transportDetails = [];
            foreach ($group['items'] as $item) {
                $transportDetails[] = $item['service_name'] . ': $' . number_format($item['usd_amount'], 2);
            }
            $transportDetailsStr = implode(' | ', $transportDetails);
            
            return [
                'type' => 'TRANSPORT',
                'tour_number' => $group['tour_number'],
                'invoice_number' => $group['invoice_number'],
                'vendor_name' => 'Transport Package',
                'client_name' => $group['client_name'],
                'agent_name' => $group['agent_name'],
                'check_in_date' => $group['start_date'],
                'check_out_date' => $group['end_date'],
                'usd_amount' => $totalUSD,
                'budgeted_total' => $totalLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $totalLKR,
                'hold_process' => 'Process',
                
                // ✅ Transport specific fields
                'advance_percent' => $advancePercent,
                'fuel_advance' => $fuelAdvance,
                'tour_advance' => $tourAdvance,
                'driver_name' => $driverDetail->payee_name ?? 'N/A',
                'driver_account' => $driverDetail->account_number ?? 'N/A',
                'driver_bank' => $driverDetail->bank_branch ?? 'N/A',
                'transport_details' => $transportDetailsStr,
                
                // Hotel fields (N/A for transport)
                'ac_name' => 'N/A',
                'bank' => $driverDetail->bank_branch ?? 'N/A',
                'account_number' => $driverDetail->account_number ?? 'N/A',
                'branch' => $driverDetail->bank_branch ?? 'N/A',
                'bank_and_branch' => $driverDetail->bank_branch ?? 'N/A',
                'swift' => 'N/A',
                
                'record_id' => $group['record_id'],
            ];
            
        } catch (\Exception $e) {
            Log::error("Error processing transport group: " . $e->getMessage());
            return null;
        }
    }
}