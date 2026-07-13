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
     * Generate payable report - HOTELS, TRANSPORT, ATTRACTION, TOUR TRANSFERS
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
            
            // ✅ Get PNL records with items - ALL TYPES
            $records = PnlRecord::where('country_code', $country)
                ->where('status', 'pending')
                ->with(['items' => function($query) {
                    $query->whereIn('type', ['HOTEL', 'TRANSPORT', 'ATTRACTION', 'TOUR TRANSFER']);
                }])
                ->get();
            
            $hotels = [];
            $transportGroups = [];
            $attractions = [];
            $tourTransfers = [];
            
            foreach ($records as $record) {
                foreach ($record->items as $item) {
                    $type = strtoupper($item->type ?? 'OTHER');
                    
                    if ($type === 'HOTEL') {
                        $hotel = $this->processHotelItem($item, $record, $exchangeRate, $checkInDateToFind);
                        if ($hotel) {
                            $hotels[] = $hotel;
                        }
                    } elseif ($type === 'TRANSPORT') {
                        // Group transport by tour_ref
                        $tourRef = $record->tour_ref ?? $record->invoice_number ?? 'N/A';
                        $startDate = $item->start_date ?? $item->check_in_date ?? null;
                        
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
                        
                    } elseif ($type === 'ATTRACTION') {
                        $attraction = $this->processAttractionItem($item, $record, $exchangeRate, $checkInDateToFind);
                        if ($attraction) {
                            $attractions[] = $attraction;
                        }
                    } elseif ($type === 'TOUR TRANSFER') {
                        $tourTransfer = $this->processTourTransferItem($item, $record, $exchangeRate, $checkInDateToFind);
                        if ($tourTransfer) {
                            $tourTransfers[] = $tourTransfer;
                        }
                    }
                }
            }
            
            // ✅ Convert transport groups to payable format
            $transportPayables = [];
            foreach ($transportGroups as $tourRef => $group) {
                $transportPayables[] = $this->processTransportGroup($group, $exchangeRate);
            }
            
            // ✅ Combine all payables
            $payables = array_merge($hotels, $transportPayables, $attractions, $tourTransfers);
            
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
                    'attraction' => count($attractions),
                    'tour_transfers' => count($tourTransfers),
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
                'vendor_name' => $hotelName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                'ac_name' => $hotelDetail->ac_name ?? 'N/A',
                'bank' => $hotelDetail->bank ?? 'N/A',
                'account_number' => $hotelDetail->account_number ?? 'N/A',
                'branch' => $hotelDetail->branch ?? 'N/A',
                'bank_and_branch' => $hotelDetail->bank_and_branch ?? 'N/A',
                'swift' => $hotelDetail->swift ?? 'N/A',
                'advance_percent' => null,
                'fuel_advance' => null,
                'tour_advance' => null,
                'driver_name' => 'N/A',
                'driver_account' => 'N/A',
                'driver_bank' => 'N/A',
                'transport_details' => null,
                'attraction_details' => null,
                'tour_transfer_details' => null,
                'item_id' => $item->id,
                'record_id' => $record->id,
            ];
            
        } catch (\Exception $e) {
            Log::error("Error processing hotel item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process ATTRACTION items
     */
    protected function processAttractionItem($item, $record, $exchangeRate, $checkInDateToFind)
    {
        try {
            $attractionName = $item->service_name ?? $item->attraction_name ?? null;
            
            if (!$attractionName) {
                return null;
            }
            
            $checkInDate = $item->check_in_date ?? $item->start_date ?? null;
            if (!$checkInDate) {
                return null;
            }
            
            $checkInDateStr = date('Y-m-d', strtotime($checkInDate));
            if ($checkInDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            // ✅ Attraction: Deduct LKR 5,000 from total and collect remaining as advance
            $advanceDeduction = 5000; // LKR 5,000 deduction
            $totalLKR = $budgetedLKR;
            $advanceAmount = $totalLKR - $advanceDeduction;
            
            // If total is less than 5000, advance is 0
            if ($advanceAmount < 0) {
                $advanceAmount = 0;
            }
            
            $checkOutDate = $item->check_out_date ?? $item->end_date ?? null;
            
            // Get attraction details from item_details
            $details = $item->item_details ?? [];
            $attractionDetails = '';
            if (isset($details['remarks'])) {
                $attractionDetails = $details['remarks'];
            }
            
            return [
                'type' => 'ATTRACTION',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $attractionName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $totalLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $totalLKR,
                'hold_process' => 'Process',
                
                // ✅ Attraction specific fields
                'advance_percent' => null,
                'fuel_advance' => null,
                'tour_advance' => null,
                'advance_deduction' => $advanceDeduction,
                'advance_amount' => $advanceAmount,
                'attraction_details' => $attractionDetails,
                
                // Other fields (N/A)
                'ac_name' => 'N/A',
                'bank' => 'N/A',
                'account_number' => 'N/A',
                'branch' => 'N/A',
                'bank_and_branch' => 'N/A',
                'swift' => 'N/A',
                'driver_name' => 'N/A',
                'driver_account' => 'N/A',
                'driver_bank' => 'N/A',
                'transport_details' => null,
                'tour_transfer_details' => null,
                
                'item_id' => $item->id,
                'record_id' => $record->id,
            ];
            
        } catch (\Exception $e) {
            Log::error("Error processing attraction item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process TOUR TRANSFER items
     */
    protected function processTourTransferItem($item, $record, $exchangeRate, $checkInDateToFind)
    {
        try {
            $transferName = $item->service_name ?? $item->transfer_name ?? null;
            
            if (!$transferName) {
                return null;
            }
            
            $checkInDate = $item->check_in_date ?? $item->start_date ?? null;
            if (!$checkInDate) {
                return null;
            }
            
            $checkInDateStr = date('Y-m-d', strtotime($checkInDate));
            if ($checkInDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            $checkOutDate = $item->check_out_date ?? $item->end_date ?? null;
            
            // Get transfer details
            $details = $item->item_details ?? [];
            $transferDetails = '';
            if (isset($details['remarks'])) {
                $transferDetails = $details['remarks'];
            }
            
            return [
                'type' => 'TOUR TRANSFER',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $transferName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                
                // Tour Transfer specific
                'tour_transfer_details' => $transferDetails,
                
                // Other fields (N/A)
                'ac_name' => 'N/A',
                'bank' => 'N/A',
                'account_number' => 'N/A',
                'branch' => 'N/A',
                'bank_and_branch' => 'N/A',
                'swift' => 'N/A',
                'driver_name' => 'N/A',
                'driver_account' => 'N/A',
                'driver_bank' => 'N/A',
                'advance_percent' => null,
                'fuel_advance' => null,
                'tour_advance' => null,
                'transport_details' => null,
                'attraction_details' => null,
                
                'item_id' => $item->id,
                'record_id' => $record->id,
            ];
            
        } catch (\Exception $e) {
            Log::error("Error processing tour transfer item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process TRANSPORT group - Grouped by Tour
     */
    protected function processTransportGroup($group, $exchangeRate)
    {
        try {
            $driverDetail = DriverBankDetail::where('country_code', $group['country_code'] ?? 'LK')
                ->first();
            
            $totalUSD = $group['total_usd'];
            $totalLKR = $group['total_lkr'];
            $advancePercent = 30;
            $fuelAdvance = $totalLKR * ($advancePercent / 100);
            $tourAdvance = $totalLKR - $fuelAdvance;
            
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
                
                'advance_percent' => $advancePercent,
                'fuel_advance' => $fuelAdvance,
                'tour_advance' => $tourAdvance,
                'driver_name' => $driverDetail->payee_name ?? 'N/A',
                'driver_account' => $driverDetail->account_number ?? 'N/A',
                'driver_bank' => $driverDetail->bank_branch ?? 'N/A',
                'transport_details' => $transportDetailsStr,
                
                'ac_name' => 'N/A',
                'bank' => $driverDetail->bank_branch ?? 'N/A',
                'account_number' => $driverDetail->account_number ?? 'N/A',
                'branch' => $driverDetail->bank_branch ?? 'N/A',
                'bank_and_branch' => $driverDetail->bank_branch ?? 'N/A',
                'swift' => 'N/A',
                'attraction_details' => null,
                'tour_transfer_details' => null,
                
                'record_id' => $group['record_id'],
            ];
            
        } catch (\Exception $e) {
            Log::error("Error processing transport group: " . $e->getMessage());
            return null;
        }
    }
}