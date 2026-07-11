<?php
// app/Services/PayableReportService.php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use App\Models\PayableRecord;
use App\Models\HotelDetail;
use App\Models\DriverBankDetail;
use App\Models\RestaurantDetail;
use App\Models\HotelPaymentDeadline;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class PayableReportService
{
    protected $exchangeRate = 330;
    
    /**
     * Generate Payable Report for any country
     */
    public function generateReport($countryCode = 'LK', $targetDate = null, $deadlineDays = 4)
    {
        try {
            if (!$targetDate) {
                $targetDate = Carbon::now()->addDays($deadlineDays)->format('Y-m-d');
            }
            
            $date = Carbon::parse($targetDate);
            Log::info("📊 Generating Payable Report for {$countryCode} on: {$date->format('Y-m-d')}");
            
            // Get exchange rate
            $this->exchangeRate = $this->fetchExchangeRate();
            
            // ✅ FIX: Use correct column name 'start_date'
            $pnlRecords = PnlRecord::where('country_code', $countryCode)
                ->where('start_date', '<=', $date->format('Y-m-d'))
                ->where('status', 'pending')
                ->get();
            
            Log::info("📊 Found " . $pnlRecords->count() . " PNL records");
            
            $allPayables = [];
            
            foreach ($pnlRecords as $record) {
                $items = PnlItem::where('pnl_record_id', $record->id)->get();
                
                foreach ($items as $item) {
                    $payable = $this->processItem($record, $item, $countryCode);
                    if ($payable) {
                        $allPayables[] = $payable;
                        $this->savePayableRecord($payable);
                    }
                }
            }
            
            $summary = $this->getSummary($allPayables);
            
            return [
                'success' => true,
                'country' => $countryCode,
                'date' => $date->format('Y-m-d'),
                'deadline' => "D-{$deadlineDays}",
                'exchange_rate' => $this->exchangeRate,
                'total_count' => count($allPayables),
                'payables' => $allPayables,
                'summary' => $summary,
            ];
            
        } catch (\Exception $e) {
            Log::error("Error generating payable report: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process individual PNL item
     */
    protected function processItem($record, $item, $countryCode)
    {
        $vendorType = $item->type;
        $amount = $item->amount_original ?? 0;
        $itemDetails = json_decode($item->item_details, true);
        
        if ($amount <= 0) {
            return null;
        }
        
        $payable = [
            'pnl_record_id' => $record->id,
            'pnl_item_id' => $item->id,
            'tour_number' => $record->tour_ref ?? 'N/A',
            'invoice_number' => $record->invoice_number ?? 'N/A',
            'tour_ref' => $record->tour_ref,
            'agent_name' => $record->agent_name,
            'client_name' => $record->vendor_name ?? $record->guest_name,
            'vendor_type' => $vendorType,
            'vendor_name' => $item->service_name,
            'usd_amount' => $amount,
            'budgeted_total' => $amount * $this->exchangeRate,
            'exchange_rate' => $this->exchangeRate,
            'payable_lkr' => $amount * $this->exchangeRate,
            'paid_amount' => 0,
            'balance' => $amount * $this->exchangeRate,
            // ✅ FIX: Use correct column names
            'start_date' => $record->start_date ?? $record->travel_start_date,
            'end_date' => $record->end_date ?? $record->travel_end_date,
            'country_code' => $countryCode,
            'payment_status' => 'pending',
            'hold_process' => 'Process',
            'item_details' => $itemDetails,
        ];
        
        switch ($vendorType) {
            case 'HOTEL':
                return $this->processHotelPayable($record, $item, $payable, $countryCode);
            case 'TRANSPORT':
                return $this->processTransportPayable($record, $item, $payable, $countryCode);
            case 'ATTRACTION':
                return $this->processAttractionPayable($record, $item, $payable);
            case 'TOUR TRANSFER':
                return $this->processTourTransferPayable($record, $item, $payable);
            case 'MEALS':
                return $this->processMealsPayable($record, $item, $payable, $countryCode);
            default:
                return $payable;
        }
    }
    
    /**
     * Process Hotel Payable
     */
    protected function processHotelPayable($record, $item, $payable, $countryCode)
    {
        $hotelName = $item->hotel_name ?? $item->service_name;
        
        // Get hotel bank details
        $hotelDetail = HotelDetail::where('hotel_name', 'LIKE', "%{$hotelName}%")
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
            
        // Get hotel payment deadline
        $deadline = HotelPaymentDeadline::where('hotel_name', 'LIKE', "%{$hotelName}%")
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
            
        // ✅ FIX: Use correct date columns
        $checkInDate = $record->start_date ?? $record->travel_start_date;
        $checkOutDate = $record->end_date ?? $record->travel_end_date;
        $nights = $record->total_nights ?? 1;
        
        if (!$checkOutDate && $checkInDate && $nights) {
            $checkOutDate = Carbon::parse($checkInDate)->addDays($nights)->format('Y-m-d');
        }
        
        $payable['hotel_name'] = $hotelName;
        $payable['check_in_date'] = $checkInDate;
        $payable['check_out_date'] = $checkOutDate;
        
        if ($hotelDetail) {
            $payable['ac_name'] = $hotelDetail->ac_name;
            $payable['bank'] = $hotelDetail->bank;
            $payable['account_number'] = $hotelDetail->account_number;
            $payable['branch'] = $hotelDetail->branch;
            $payable['bank_and_branch'] = $hotelDetail->bank_and_branch;
            $payable['swift'] = $hotelDetail->swift;
        } else {
            $payable['ac_name'] = $hotelName;
            $payable['bank'] = 'N/A';
            $payable['account_number'] = 'N/A';
            $payable['branch'] = 'N/A';
            $payable['bank_and_branch'] = 'N/A';
            $payable['swift'] = 'N/A';
        }
        
        if ($deadline) {
            $paymentType = $deadline->payment_type;
            $daysBefore = $deadline->days_before;
            
            $today = Carbon::now();
            $dueDate = $this->calculateDueDate($checkInDate, $checkOutDate, $paymentType, $daysBefore);
            
            if ($dueDate && $today->greaterThan($dueDate)) {
                $payable['hold_process'] = 'Hold';
                $payable['hold_reason'] = "Payment overdue (due: {$dueDate->format('Y-m-d')})";
            }
        }
        
        return $payable;
    }
    
    /**
     * Process Transport Payable
     */
    protected function processTransportPayable($record, $item, $payable, $countryCode)
    {
        $serviceName = $item->service_name;
        $itemDetails = json_decode($item->item_details, true);
        
        $transportDetails = $this->extractTransportDetails($serviceName, $itemDetails);
        $payable['transport_details'] = $transportDetails;
        
        $driver = null;
        $driverName = $itemDetails['driver_name'] ?? $itemDetails['remarks'] ?? null;
        
        if ($driverName) {
            $driver = DriverBankDetail::where('payee_name', 'LIKE', "%{$driverName}%")
                ->where('country_code', $countryCode)
                ->where('is_active', true)
                ->first();
        }
        
        if ($driver) {
            $payable['driver_name'] = $driver->payee_name;
            $payable['driver_ac_name'] = $driver->payee_name;
            $payable['driver_account_number'] = $driver->account_number;
            $payable['driver_bank_branch'] = $driver->bank_branch;
            $payable['ac_name'] = $driver->payee_name;
            $payable['account_number'] = $driver->account_number;
            $payable['bank_branch'] = $driver->bank_branch;
        } else {
            $payable['driver_name'] = $serviceName;
            $payable['driver_ac_name'] = 'N/A';
            $payable['driver_account_number'] = 'N/A';
            $payable['driver_bank_branch'] = 'N/A';
            $payable['ac_name'] = 'N/A';
            $payable['account_number'] = 'N/A';
            $payable['bank_branch'] = 'N/A';
        }
        
        $payable['fuel_advance'] = $payable['usd_amount'] * 0.10;
        $payable['tour_advance'] = $payable['usd_amount'] * 0.10;
        $payable['advance_percentage'] = 20;
        
        return $payable;
    }
    
    /**
     * Process Attraction Payable
     */
    protected function processAttractionPayable($record, $item, $payable)
    {
        $payable['ac_name'] = 'N/A';
        $payable['account_number'] = 'N/A';
        $payable['bank_branch'] = 'N/A';
        return $payable;
    }
    
    /**
     * Process Tour Transfer Payable
     */
    protected function processTourTransferPayable($record, $item, $payable)
    {
        $payable['ac_name'] = 'N/A';
        $payable['account_number'] = 'N/A';
        $payable['bank_branch'] = 'N/A';
        return $payable;
    }
    
    /**
     * Process Meals Payable
     */
    protected function processMealsPayable($record, $item, $payable, $countryCode)
    {
        $restaurantName = $item->service_name;
        
        $restaurant = RestaurantDetail::where('restaurant_name', 'LIKE', "%{$restaurantName}%")
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
            
        if ($restaurant) {
            $payable['ac_name'] = $restaurant->ac_name;
            $payable['bank'] = $restaurant->bank;
            $payable['account_number'] = $restaurant->account_number;
        } else {
            $payable['ac_name'] = 'N/A';
            $payable['bank'] = 'N/A';
            $payable['account_number'] = 'N/A';
        }
        
        return $payable;
    }
    
    /**
     * Extract transport details
     */
    protected function extractTransportDetails($serviceName, $itemDetails)
    {
        $details = [
            'bata' => 0,
            'paging' => 0,
            'highway_charges' => 0,
            'driver_accommodation' => 0,
            'guide_fee' => 0,
            'water_bottles' => 0,
            'service_name' => $serviceName,
        ];
        
        if (isset($itemDetails['remarks'])) {
            $remarks = $itemDetails['remarks'];
            
            if (preg_match('/Bata\s*[:]?\s*([\d.]+)/i', $remarks, $match)) {
                $details['bata'] = floatval($match[1]);
            }
            if (preg_match('/Paging\s*[:]?\s*([\d.]+)/i', $remarks, $match)) {
                $details['paging'] = floatval($match[1]);
            }
            if (preg_match('/Highway\s*[:]?\s*([\d.]+)/i', $remarks, $match)) {
                $details['highway_charges'] = floatval($match[1]);
            }
            if (preg_match('/Water\s*Bottles\s*[:]?\s*([\d.]+)/i', $remarks, $match)) {
                $details['water_bottles'] = floatval($match[1]);
            }
        }
        
        return $details;
    }
    
    /**
     * Calculate due date
     */
    protected function calculateDueDate($checkInDate, $checkOutDate, $paymentType, $daysBefore)
    {
        if (!$checkInDate && !$checkOutDate) {
            return null;
        }
        
        switch ($paymentType) {
            case 'Check In':
                if ($checkInDate) {
                    return Carbon::parse($checkInDate)->subDays($daysBefore);
                }
                break;
            case 'Check Out':
                if ($checkOutDate) {
                    return Carbon::parse($checkOutDate);
                }
                break;
            case '1 Days Before From Check In Date':
                if ($checkInDate) {
                    return Carbon::parse($checkInDate)->subDay();
                }
                break;
            case '2 Days Before From Check In Date':
                if ($checkInDate) {
                    return Carbon::parse($checkInDate)->subDays(2);
                }
                break;
            default:
                if ($checkOutDate) {
                    return Carbon::parse($checkOutDate);
                }
                break;
        }
        
        return $checkOutDate ? Carbon::parse($checkOutDate) : null;
    }
    
    /**
     * Fetch exchange rate
     */
    protected function fetchExchangeRate()
    {
        try {
            $response = Http::timeout(10)->get('https://www.cbsl.gov.lk/en/rates-and-indicators/exchange-rates/daily-buy-and-sell-exchange-rates');
            
            if ($response->ok()) {
                $html = $response->body();
                if (preg_match('/USD\s*.*?(\d+\.\d+)/i', $html, $match)) {
                    $rate = floatval($match[1]);
                    if ($rate > 0) {
                        return $rate;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Could not fetch exchange rate: " . $e->getMessage());
        }
        
        return 330.00;
    }
    
    /**
     * Get summary
     */
    protected function getSummary($payables)
    {
        $summary = [
            'hotels' => 0,
            'transport' => 0,
            'attraction' => 0,
            'tour_transfers' => 0,
            'meals' => 0,
            'other' => 0,
        ];
        
        foreach ($payables as $payable) {
            $type = $payable['vendor_type'] ?? 'other';
            $key = strtolower($type);
            
            if ($key == 'hotel') $summary['hotels']++;
            elseif ($key == 'transport') $summary['transport']++;
            elseif ($key == 'attraction') $summary['attraction']++;
            elseif ($key == 'tour transfer') $summary['tour_transfers']++;
            elseif ($key == 'meals') $summary['meals']++;
            else $summary['other']++;
        }
        
        return $summary;
    }
    
    /**
     * Save payable record
     */
    protected function savePayableRecord($payable)
    {
        try {
            $record = PayableRecord::updateOrCreate(
                [
                    'pnl_item_id' => $payable['pnl_item_id'],
                    'vendor_type' => $payable['vendor_type'],
                ],
                [
                    'pnl_record_id' => $payable['pnl_record_id'],
                    'tour_number' => $payable['tour_number'],
                    'invoice_number' => $payable['invoice_number'],
                    'tour_ref' => $payable['tour_ref'],
                    'agent_name' => $payable['agent_name'],
                    'paid_amount' => $payable['paid_amount'] ?? 0,
                    'balance' => $payable['balance'] ?? 0,
                    'usd_amount' => $payable['usd_amount'] ?? 0,
                    'budgeted_total' => $payable['budgeted_total'] ?? 0,
                    'exchange_rate' => $payable['exchange_rate'] ?? 1,
                    'payable_lkr' => $payable['payable_lkr'] ?? 0,
                    'start_date' => $payable['start_date'] ?? null,
                    'end_date' => $payable['end_date'] ?? null,
                    'check_out_date' => $payable['check_out_date'] ?? null,
                    'vendor_type' => $payable['vendor_type'],
                    'vendor_name' => $payable['vendor_name'] ?? null,
                    'client_name' => $payable['client_name'] ?? null,
                    'hotel_name' => $payable['hotel_name'] ?? null,
                    'ac_name' => $payable['ac_name'] ?? null,
                    'bank' => $payable['bank'] ?? null,
                    'account_number' => $payable['account_number'] ?? null,
                    'branch' => $payable['branch'] ?? null,
                    'bank_and_branch' => $payable['bank_and_branch'] ?? null,
                    'swift' => $payable['swift'] ?? null,
                    'driver_name' => $payable['driver_name'] ?? null,
                    'driver_ac_name' => $payable['driver_ac_name'] ?? null,
                    'driver_account_number' => $payable['driver_account_number'] ?? null,
                    'driver_bank_branch' => $payable['driver_bank_branch'] ?? null,
                    'payment_status' => $payable['payment_status'] ?? 'pending',
                    'hold_reason' => $payable['hold_reason'] ?? null,
                    'fuel_advance' => $payable['fuel_advance'] ?? 0,
                    'tour_advance' => $payable['tour_advance'] ?? 0,
                    'advance_percentage' => $payable['advance_percentage'] ?? 0,
                    'country_code' => $payable['country_code'],
                    'item_details' => json_encode($payable['item_details'] ?? []),
                    'transport_details' => json_encode($payable['transport_details'] ?? []),
                ]
            );
            
            return $record;
            
        } catch (\Exception $e) {
            Log::error("Error saving payable record: " . $e->getMessage());
            return null;
        }
    }
}