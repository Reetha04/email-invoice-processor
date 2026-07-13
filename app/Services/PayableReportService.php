<?php
// app/Services/PayableReportService.php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use App\Models\HotelDetail;
use App\Models\DriverBankDetail;
use App\Models\RestaurantDetail;
use App\Models\HotelBankDetail;
use App\Models\MalaysiaHotelDeadline;
use Illuminate\Support\Facades\Log;

class PayableReportService
{
    protected $exchangeRateService;
    
    // Country-specific exchange rates
    protected $exchangeRates = [
        'LK' => null, // Dynamic from CBSL
        'VN' => 25500,
        'SG' => 1,
        'MY' => 1,
    ];
    
    public function __construct(ExchangeRateServiceLKR $exchangeRateService)
    {
        $this->exchangeRateService = $exchangeRateService;
    }
    
    /**
     * Generate payable report - HOTELS, TRANSPORT, ATTRACTION, TOUR TRANSFERS, MEALS
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
            
            // Get exchange rate based on country
            $exchangeRate = $this->getExchangeRate($country);
            
            // ✅ Get PNL records with items - ALL TYPES
            $records = $this->getLatestPendingRecords($country);
            
            $hotels = [];
            $transportGroups = [];
            $attractions = [];
            $tourTransfers = [];
            $meals = [];
            $otherRates = [];
            
            foreach ($records as $record) {
                // ✅ Get INVOICE dates first
                $invoiceStartDate = null;
                $invoiceEndDate = null;
                foreach ($record->items as $ri) {
                    if (strtoupper($ri->type) === 'INVOICE') {
                        $invoiceStartDate = $ri->start_date;
                        $invoiceEndDate = $ri->end_date;
                        break;
                    }
                }
                
                foreach ($record->items as $item) {
                    $type = strtoupper($item->type ?? 'OTHER');
                    
                    if ($type === 'HOTEL') {
                        $hotel = $this->processHotelItem($item, $record, $exchangeRate, $checkInDateToFind, $country);
                        if ($hotel) {
                            $hotels[] = $hotel;
                        }
                    } elseif ($type === 'TRANSPORT') {
                        $tourRef = $record->tour_ref ?? $record->invoice_number ?? 'N/A';
                        
                        $tourStartDate = $invoiceStartDate ?? $record->start_date ?? null;
                        $tourEndDate = $invoiceEndDate ?? $record->end_date ?? null;
                        
                        if ($tourStartDate) {
                            $startDateStr = date('Y-m-d', strtotime($tourStartDate));
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
                                'start_date' => $tourStartDate,
                                'end_date' => $tourEndDate,
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
                        $attraction = $this->processAttractionItem($item, $record, $exchangeRate, $checkInDateToFind, $country);
                        if ($attraction) {
                            $attractions[] = $attraction;
                        }
                    } elseif ($type === 'TOUR TRANSFER') {
                        $tourTransfer = $this->processTourTransferItem($item, $record, $exchangeRate, $checkInDateToFind, $country);
                        if ($tourTransfer) {
                            $tourTransfers[] = $tourTransfer;
                        }
                    } elseif ($type === 'MEALS') {
                        $meal = $this->processMealItem($item, $record, $exchangeRate, $checkInDateToFind, $country);
                        if ($meal) {
                            $meals[] = $meal;
                        }
                    } elseif ($type === 'OTHER RATES') {
                        $otherRate = $this->processOtherRateItem($item, $record, $exchangeRate, $checkInDateToFind, $country);
                        if ($otherRate) {
                            $otherRates[] = $otherRate;
                        }
                    }
                }
            }
            
            // ✅ Convert transport groups to payable format
            $transportPayables = [];
            foreach ($transportGroups as $tourRef => $group) {
                $transportPayables[] = $this->processTransportGroup($group, $exchangeRate, $country);
            }
            
            // ✅ For Malaysia/Singapore: Hotels separate, everything else as Tickets
            if ($country === 'SG' || $country === 'MY') {
                // Combine all non-hotel items as tickets
                $tickets = array_merge($transportPayables, $attractions, $tourTransfers, $meals, $otherRates);
                
                // Sort tickets by start_date
                usort($tickets, function($a, $b) {
                    return strtotime($a['start_date'] ?? '') - strtotime($b['start_date'] ?? '');
                });
                
                // Sort hotels by start_date
                usort($hotels, function($a, $b) {
                    return strtotime($a['start_date'] ?? '') - strtotime($b['start_date'] ?? '');
                });
                
                return [
                    'success' => true,
                    'hotels' => $hotels,
                    'tickets' => $tickets,
                    'summary' => [
                        'hotels' => count($hotels),
                        'tickets' => count($tickets),
                    ],
                    'exchange_rate' => $exchangeRate,
                    'date' => now()->format('Y-m-d'),
                    'target_date' => $targetDate,
                    'check_in_date' => $checkInDateToFind,
                    'deadline_days' => $deadlineDays,
                    'total_count' => count($hotels) + count($tickets),
                    'deadline' => "D-{$deadlineDays}",
                    'country' => $country,
                    'currency' => $country === 'MY' ? 'MYR' : 'SGD',
                ];
            }
            
            // For Sri Lanka and Vietnam - separate sections
            $payables = array_merge($hotels, $transportPayables, $attractions, $tourTransfers, $meals, $otherRates);
            
            usort($payables, function($a, $b) {
                return strtotime($a['start_date'] ?? '') - strtotime($b['start_date'] ?? '');
            });
            
            return [
                'success' => true,
                'payables' => $payables,
                'summary' => [
                    'hotels' => count($hotels),
                    'transport' => count($transportPayables),
                    'attraction' => count($attractions),
                    'tour_transfers' => count($tourTransfers),
                    'meals' => count($meals),
                    'other_rates' => count($otherRates),
                ],
                'exchange_rate' => $exchangeRate,
                'date' => now()->format('Y-m-d'),
                'target_date' => $targetDate,
                'check_in_date' => $checkInDateToFind,
                'deadline_days' => $deadlineDays,
                'total_count' => count($payables),
                'deadline' => "D-{$deadlineDays}",
                'country' => $country,
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
     * Get exchange rate based on country
     */
    protected function getExchangeRate($country)
    {
        if ($country === 'LK') {
            return $this->exchangeRateService->getUSDtoLKR();
        }
        return $this->exchangeRates[$country] ?? 1;
    }
    
    /**
     * Get hotel deadline based on country and hotel name
     */
    protected function getHotelDeadline($country, $hotelName, $defaultDeadline)
    {
        if ($country === 'MY') {
            // Check Malaysia hotel deadlines from database
            $deadline = MalaysiaHotelDeadline::where('hotel_name', 'LIKE', "%{$hotelName}%")
                ->where('is_active', true)
                ->first();
            
            if ($deadline) {
                return $deadline->deadline_days;
            }
        }
        return $defaultDeadline;
    }
    
    /**
     * Process HOTEL items - For ALL countries
     */
    protected function processHotelItem($item, $record, $exchangeRate, $checkInDateToFind, $country)
    {
        try {
            $hotelName = $item->hotel_name ?? $item->service_name ?? null;
            
            if (!$hotelName) {
                return null;
            }
            
            // ✅ Get hotel-specific deadline for Malaysia
            $hotelDeadline = $this->getHotelDeadline($country, $hotelName, 4);
            
            // Recalculate check-in date using hotel-specific deadline
            $targetDate = now()->format('Y-m-d');
            $checkInDateToFind = date('Y-m-d', strtotime($targetDate . ' + ' . $hotelDeadline . ' days'));
            
            $checkInDate = $item->start_date ?? null;
            if (!$checkInDate) {
                return null;
            }
            
            $checkInDateStr = date('Y-m-d', strtotime($checkInDate));
            if ($checkInDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            // ✅ Get hotel bank details based on country
            $hotelDetail = null;
            if ($country === 'LK') {
                $hotelDetail = HotelDetail::where('hotel_name', 'LIKE', "%{$hotelName}%")
                    ->where('country_code', 'LK')
                    ->first();
            } elseif ($country === 'SG' || $country === 'MY') {
                $hotelDetail = HotelBankDetail::where('hotel_name', 'LIKE', "%{$hotelName}%")
                    ->where('country_code', $country)
                    ->first();
            }
            
            $checkOutDate = $item->end_date ?? null;
            
            $result = [
                'type' => 'HOTEL',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $hotelName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'agent_type' => $record->credit_type ?? 'Credit',
                'start_date' => $checkInDate,
                'end_date' => $checkOutDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                'ac_name' => $hotelDetail->ac_name ?? 'N/A',
                'bank' => $hotelDetail->bank ?? 'N/A',
                'account_number' => $hotelDetail->account_number ?? 'N/A',
                'branch' => $hotelDetail->branch ?? 'N/A',
                'swift' => $hotelDetail->swift_code ?? 'N/A',
                'item_id' => $item->id,
                'record_id' => $record->id,
            ];
            
            // ✅ For Malaysia/Singapore, include UEN
            if ($country === 'SG' || $country === 'MY') {
                $result['uen'] = $hotelDetail->uen ?? 'N/A';
                $result['currency'] = $country === 'MY' ? 'MYR' : 'SGD';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error processing hotel item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process TRANSPORT group
     */
    protected function processTransportGroup($group, $exchangeRate, $country)
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
            
            $result = [
                'type' => 'TRANSPORT',
                'tour_number' => $group['tour_number'],
                'invoice_number' => $group['invoice_number'],
                'client_name' => $group['client_name'],
                'agent_name' => $group['agent_name'],
                'start_date' => $group['start_date'],
                'end_date' => $group['end_date'],
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
                'record_id' => $group['record_id'],
                'description' => 'Transport Package',
            ];
            
            if ($country === 'SG' || $country === 'MY') {
                $result['currency'] = $country === 'MY' ? 'MYR' : 'SGD';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error processing transport group: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process ATTRACTION items
     */
    protected function processAttractionItem($item, $record, $exchangeRate, $checkInDateToFind, $country)
    {
        try {
            $attractionName = $item->service_name ?? $item->attraction_name ?? null;
            
            if (!$attractionName) {
                return null;
            }
            
            $startDate = $item->start_date ?? null;
            if (!$startDate) {
                return null;
            }
            
            $startDateStr = date('Y-m-d', strtotime($startDate));
            if ($startDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            $endDate = $item->end_date ?? null;
            
            $details = $item->item_details ?? [];
            $attractionDetails = '';
            if (isset($details['remarks'])) {
                $attractionDetails = $details['remarks'];
            }
            
            $result = [
                'type' => 'ATTRACTION',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $attractionName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                'attraction_details' => $attractionDetails,
                'item_id' => $item->id,
                'record_id' => $record->id,
                'description' => $attractionName,
            ];
            
            if ($country === 'SG' || $country === 'MY') {
                $result['currency'] = $country === 'MY' ? 'MYR' : 'SGD';
                // Extract peak/off-peak info
                if (stripos($attractionName, 'Peak') !== false) {
                    $result['peak_type'] = 'Peak';
                } elseif (stripos($attractionName, 'Off Peak') !== false || stripos($attractionName, 'Non-Peak') !== false) {
                    $result['peak_type'] = 'Off Peak';
                } else {
                    $result['peak_type'] = 'Standard';
                }
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error processing attraction item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process TOUR TRANSFER items
     */
    protected function processTourTransferItem($item, $record, $exchangeRate, $checkInDateToFind, $country)
    {
        try {
            $transferName = $item->service_name ?? $item->transfer_name ?? null;
            
            if (!$transferName) {
                return null;
            }
            
            $startDate = $item->start_date ?? null;
            if (!$startDate) {
                return null;
            }
            
            $startDateStr = date('Y-m-d', strtotime($startDate));
            if ($startDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            $endDate = $item->end_date ?? null;
            
            $details = $item->item_details ?? [];
            $transferDetails = '';
            if (isset($details['remarks'])) {
                $transferDetails = $details['remarks'];
            }
            
            $result = [
                'type' => 'TOUR TRANSFER',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $transferName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                'tour_transfer_details' => $transferDetails,
                'item_id' => $item->id,
                'record_id' => $record->id,
                'description' => $transferName,
            ];
            
            if ($country === 'SG' || $country === 'MY') {
                $result['currency'] = $country === 'MY' ? 'MYR' : 'SGD';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error processing tour transfer item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process MEALS items
     */
    protected function processMealItem($item, $record, $exchangeRate, $checkInDateToFind, $country)
    {
        try {
            $mealName = $item->service_name ?? null;
            
            if (!$mealName) {
                return null;
            }
            
            $startDate = $item->start_date ?? null;
            if (!$startDate) {
                return null;
            }
            
            $startDateStr = date('Y-m-d', strtotime($startDate));
            if ($startDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            $endDate = $item->end_date ?? null;
            
            $result = [
                'type' => 'MEALS',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $mealName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                'item_id' => $item->id,
                'record_id' => $record->id,
                'description' => $mealName,
            ];
            
            if ($country === 'SG' || $country === 'MY') {
                $result['currency'] = $country === 'MY' ? 'MYR' : 'SGD';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error processing meal item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process OTHER RATES items
     */
    protected function processOtherRateItem($item, $record, $exchangeRate, $checkInDateToFind, $country)
    {
        try {
            $otherName = $item->service_name ?? null;
            
            if (!$otherName) {
                return null;
            }
            
            $startDate = $item->start_date ?? null;
            if (!$startDate) {
                return null;
            }
            
            $startDateStr = date('Y-m-d', strtotime($startDate));
            if ($startDateStr !== $checkInDateToFind) {
                return null;
            }
            
            $usdAmount = $item->amount_original ?? 0;
            $budgetedLKR = $usdAmount * $exchangeRate;
            
            $endDate = $item->end_date ?? null;
            
            $details = $item->item_details ?? [];
            $otherDetails = '';
            if (isset($details['remarks'])) {
                $otherDetails = $details['remarks'];
            }
            
            $result = [
                'type' => 'OTHER RATES',
                'tour_number' => $record->tour_ref ?? $record->invoice_number ?? null,
                'invoice_number' => $record->invoice_number ?? null,
                'vendor_name' => $otherName,
                'client_name' => $record->guest_name ?? $record->from_name ?? 'N/A',
                'agent_name' => $record->agent_name ?? 'N/A',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'usd_amount' => $usdAmount,
                'budgeted_total' => $budgetedLKR,
                'exchange_rate' => $exchangeRate,
                'payable_lkr' => $budgetedLKR,
                'hold_process' => 'Process',
                'other_details' => $otherDetails,
                'item_id' => $item->id,
                'record_id' => $record->id,
                'description' => $otherName,
            ];
            
            if ($country === 'SG' || $country === 'MY') {
                $result['currency'] = $country === 'MY' ? 'MYR' : 'SGD';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error processing other rate item: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get only the latest pending record for each base IS number
     */
    protected function getLatestPendingRecords($country)
    {
        $records = PnlRecord::where('country_code', $country)
            ->where('status', 'pending')
            ->with(['items' => function($query) {
                $query->whereIn('type', ['HOTEL', 'TRANSPORT', 'ATTRACTION', 'TOUR TRANSFER', 'MEALS', 'OTHER RATES']);
            }])
            ->get();

        $grouped = $records->groupBy(function($record) {
            return $this->getBaseIsNumber($record);
        });

        $latest = [];
        foreach ($grouped as $base => $group) {
            $latestRecord = $group->sortByDesc('revision_number')->sortByDesc('id')->first();
            if ($latestRecord) {
                $latest[] = $latestRecord;
            }
        }

        return collect($latest);
    }

    private function getBaseIsNumber($record)
    {
        if (!empty($record->original_is_number)) {
            return $record->original_is_number;
        }
        $isNumber = $record->is_number;
        $base = preg_replace('/_R\d+\/R\d+$/', '', $isNumber);
        return $base;
    }
}