<?php

namespace App\Services;

use App\Models\PnlItem;
use App\Models\PnlRecord;
use App\Models\IncomingEmail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HotelDateMatcherService
{
    public function matchHotelDates($pnlRecordId)
    {
        try {
            $pnlRecord = PnlRecord::with('items')->find($pnlRecordId);
            
            if (!$pnlRecord) {
                return ['success' => false, 'message' => 'PNL record not found'];
            }
            
            $tourEmail = $this->findTourConfirmationEmail($pnlRecord);
            
            if (!$tourEmail) {
                Log::info("No tour confirmation email found for: " . $pnlRecord->tour_ref);
                return ['success' => false, 'message' => 'No matching tour email found'];
            }
            
            $hotelData = $this->extractHotelDataFromEmail($tourEmail);
            
            if (empty($hotelData)) {
                Log::info("No hotel data found in email for: " . $pnlRecord->tour_ref);
                return ['success' => false, 'message' => 'No hotel data found in email'];
            }
            
            $hotelItems = PnlItem::where('pnl_record_id', $pnlRecord->id)
                ->where('type', 'HOTEL')
                ->get();
            
            if ($hotelItems->isEmpty()) {
                return ['success' => false, 'message' => 'No hotel items in PNL'];
            }
            
            $results = $this->matchHotelsWithDates($hotelItems, $hotelData);
            
            $updatedCount = 0;
            foreach ($results as $result) {
                if ($result['matched']) {
                    $pnlItem = PnlItem::find($result['pnl_item_id']);
                    if ($pnlItem) {
                        $pnlItem->start_date = $result['check_in'];
                        $pnlItem->end_date = $result['check_out'];
                        $pnlItem->matched_service_name = $result['matched_hotel_name'];
                        $pnlItem->match_confidence = $result['confidence'];
                        $pnlItem->matched_at = now();
                        $pnlItem->save();
                        $updatedCount++;
                        
                        Log::info("✅ Updated hotel: {$pnlItem->hotel_name} -> {$result['check_in']} to {$result['check_out']}");
                    }
                }
            }
            
            return [
                'success' => true,
                'updated_count' => $updatedCount,
                'total_hotels' => $hotelItems->count(),
                'results' => $results,
                'email_hotel_data' => $hotelData,
                'tour_email_id' => $tourEmail->id
            ];
            
        } catch (\Exception $e) {
            Log::error('Hotel date matching failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function findTourConfirmationEmail($pnlRecord)
    {
        $email = IncomingEmail::where('tour_ref', $pnlRecord->tour_ref)
            ->where('is_tour_confirmation', true)
            ->first();
        
        if ($email) return $email;
        
        $email = IncomingEmail::where('invoice_number', $pnlRecord->invoice_number)
            ->where('is_tour_confirmation', true)
            ->first();
        
        if ($email) return $email;
        
        if ($pnlRecord->tour_ref) {
            $email = IncomingEmail::where('subject', 'LIKE', '%' . $pnlRecord->tour_ref . '%')
                ->where('is_tour_confirmation', true)
                ->first();
        }
        
        return $email;
    }
    
    /**
     * Extract hotel data from email HTML
     */
    private function extractHotelDataFromEmail($tourEmail)
    {
        $hotels = [];
        $html = $tourEmail->body ?? '';
        
        Log::info("Extracting hotel data from email HTML...");
        
        // Use DOMDocument to parse HTML
        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            
            // Find all tables
            $tables = $dom->getElementsByTagName('table');
            
            foreach ($tables as $table) {
                // Check if this is the hotel table (has headers: City, Hotel, Nights, Room Type, Meal Type)
                $headers = [];
                $rows = $table->getElementsByTagName('tr');
                
                if ($rows->length < 2) continue;
                
                // Get header row
                $headerRow = $rows->item(0);
                $headerCells = $headerRow->getElementsByTagName('th');
                
                if ($headerCells->length < 3) continue;
                
                // Check if this is the hotel table
                $headerText = '';
                foreach ($headerCells as $cell) {
                    $headerText .= ' ' . strtolower(trim($cell->textContent));
                }
                
                if (strpos($headerText, 'city') === false || 
                    strpos($headerText, 'hotel') === false || 
                    strpos($headerText, 'nights') === false) {
                    continue;
                }
                
                Log::info("Found hotel table with headers: " . $headerText);
                
                // Process each row (skip header)
                for ($i = 1; $i < $rows->length; $i++) {
                    $row = $rows->item($i);
                    $cells = $row->getElementsByTagName('td');
                    
                    if ($cells->length < 3) continue;
                    
                    // Get cell contents
                    $cityCell = $cells->item(0);
                    $hotelCell = $cells->item(1);
                    $nightsCell = $cells->item(2);
                    
                    // Extract city and dates from city cell
                    $cityHtml = $dom->saveHTML($cityCell);
                    $cityText = trim($cityCell->textContent);
                    
                    // Extract date range from <i> tags in city cell
                    $checkIn = null;
                    $checkOut = null;
                    
                    // Find <i> tags for dates
                    $datePattern = '/<i[^>]*>([A-Za-z]+\s+\d{1,2},?\s*\d{4})\s*[-–]+\s*([A-Za-z]+\s+\d{1,2},?\s*\d{4})<\/i>/i';
                    if (preg_match($datePattern, $cityHtml, $dateMatch)) {
                        try {
                            $checkIn = Carbon::parse(trim($dateMatch[1]))->format('Y-m-d');
                            $checkOut = Carbon::parse(trim($dateMatch[2]))->format('Y-m-d');
                            Log::info("Found dates in city cell: {$checkIn} to {$checkOut}");
                        } catch (\Exception $e) {
                            Log::error("Date parsing error: " . $e->getMessage());
                        }
                    }
                    
                    // Extract hotel name
                    $hotelName = trim($hotelCell->textContent);
                    $nights = intval(trim($nightsCell->textContent));
                    
                    // Skip Own Arrangement
                    if (stripos($hotelName, 'Own Arrangement') !== false || $hotelName === '-') {
                        Log::info("Skipping Own Arrangement: " . $hotelName);
                        continue;
                    }
                    
                    // Clean hotel name
                    $cleanedName = $this->cleanHotelName($hotelName);
                    
                    // If dates not found in city cell, try to find them from other sources
                    if (!$checkIn || !$checkOut) {
                        Log::warning("No dates found for hotel: {$hotelName}, trying fallback...");
                        
                        // Try to find dates from the full email
                        $plainText = strip_tags($html);
                        $pattern = '/' . preg_quote($hotelName, '/') . '.*?([A-Za-z]+\s+\d{1,2},?\s*\d{4})\s*[-–]+\s*([A-Za-z]+\s+\d{1,2},?\s*\d{4})/is';
                        if (preg_match($pattern, $plainText, $match)) {
                            try {
                                $checkIn = Carbon::parse(trim($match[1]))->format('Y-m-d');
                                $checkOut = Carbon::parse(trim($match[2]))->format('Y-m-d');
                                Log::info("Found dates via fallback: {$checkIn} to {$checkOut}");
                            } catch (\Exception $e) {}
                        }
                    }
                    
                    if ($checkIn && $checkOut) {
                        $hotels[] = [
                            'hotel_name' => $cleanedName,
                            'original_name' => $hotelName,
                            'city' => trim($cityText),
                            'check_in_date' => $checkIn,
                            'check_out_date' => $checkOut,
                            'nights' => $nights
                        ];
                        Log::info("✅ Extracted hotel: {$cleanedName} - {$checkIn} to {$checkOut} ({$nights} nights)");
                    } else {
                        Log::warning("⚠️ Could not determine dates for: {$hotelName}");
                    }
                }
                
                // If we found hotels, break out of table loop
                if (!empty($hotels)) {
                    break;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('HTML parsing error: ' . $e->getMessage());
        }
        
        // If no hotels found, try plain text extraction as fallback
        if (empty($hotels)) {
            Log::info("No hotels found via HTML parsing, trying plain text...");
            $plainText = strip_tags($html);
            $hotels = $this->extractFromPlainText($plainText);
        }
        
        Log::info("Extracted " . count($hotels) . " hotels from email");
        return $hotels;
    }
    
    /**
     * Fallback: Extract from plain text
     */
    private function extractFromPlainText($text)
    {
        $hotels = [];
        
        // Look for hotel table pattern in plain text
        // City | Hotel | Nights | Room Type | Meal Type
        if (preg_match_all('/\|\s*([A-Za-z\s]+)\s*\|\s*([A-Za-z\s&]+)\s*\|\s*(\d+)\s*\|\s*([A-Za-z\s]+)\s*\|\s*([A-Za-z\s]+)\s*\|/i', $text, $matches, PREG_SET_ORDER)) {
            
            foreach ($matches as $match) {
                $city = trim($match[1]);
                $hotelName = trim($match[2]);
                $nights = intval($match[3]);
                
                if (stripos($hotelName, 'Own Arrangement') !== false || $hotelName === '-') {
                    continue;
                }
                
                // Try to find dates for this hotel
                $checkIn = null;
                $checkOut = null;
                
                $pos = strpos($text, $match[0]);
                if ($pos !== false) {
                    $remainingText = substr($text, $pos + strlen($match[0]), 500);
                    if (preg_match('/([A-Za-z]+\s+\d{1,2},?\s*\d{4})\s*[-–]+\s*([A-Za-z]+\s+\d{1,2},?\s*\d{4})/', $remainingText, $dateMatch)) {
                        try {
                            $checkIn = Carbon::parse(trim($dateMatch[1]))->format('Y-m-d');
                            $checkOut = Carbon::parse(trim($dateMatch[2]))->format('Y-m-d');
                        } catch (\Exception $e) {}
                    }
                }
                
                if ($checkIn && $checkOut) {
                    $cleanedName = $this->cleanHotelName($hotelName);
                    $hotels[] = [
                        'hotel_name' => $cleanedName,
                        'original_name' => $hotelName,
                        'city' => $city,
                        'check_in_date' => $checkIn,
                        'check_out_date' => $checkOut,
                        'nights' => $nights
                    ];
                    Log::info("✅ Extracted hotel from plain text: {$cleanedName} - {$checkIn} to {$checkOut}");
                }
            }
        }
        
        return $hotels;
    }
    
    private function cleanHotelName($name)
    {
        $name = preg_replace('/^\(\d+[sS]\)\s*/', '', $name);
        $name = preg_replace('/^\(\d+\)\s*/', '', $name);
        $name = preg_replace('/\s*-\s*\d+\s*Stars?/i', '', $name);
        $name = preg_replace('/\s*\d+\s*Stars?/i', '', $name);
        $name = preg_replace('/\s*\(?\d+\s*Stars?\)?/i', '', $name);
        return trim($name);
    }
    
    private function matchHotelsWithDates($pnlItems, $emailHotels)
    {
        $results = [];
        
        Log::info("Matching " . count($pnlItems) . " PNL hotels with " . count($emailHotels) . " email hotels");
        
        $usedEmailHotels = [];
        
        foreach ($pnlItems as $pnlItem) {
            $pnlName = strtolower($this->cleanHotelName($pnlItem->hotel_name));
            $bestMatch = null;
            $bestScore = 0;
            $bestIndex = -1;
            
            foreach ($emailHotels as $index => $emailHotel) {
                if (in_array($index, $usedEmailHotels)) {
                    continue;
                }
                
                $emailName = strtolower($emailHotel['hotel_name']);
                
                similar_text($pnlName, $emailName, $score);
                
                if (strpos($pnlName, $emailName) !== false || strpos($emailName, $pnlName) !== false) {
                    $score = max($score, 90);
                }
                
                if ($score > $bestScore && $score > 50) {
                    $bestScore = $score;
                    $bestMatch = $emailHotel;
                    $bestIndex = $index;
                }
            }
            
            if ($bestMatch && isset($bestMatch['check_in_date']) && isset($bestMatch['check_out_date'])) {
                $results[] = [
                    'pnl_item_id' => $pnlItem->id,
                    'pnl_hotel_name' => $pnlItem->hotel_name,
                    'matched_hotel_name' => $bestMatch['hotel_name'],
                    'check_in' => $bestMatch['check_in_date'],
                    'check_out' => $bestMatch['check_out_date'],
                    'nights' => $bestMatch['nights'] ?? null,
                    'confidence' => $bestScore / 100,
                    'matched' => true
                ];
                Log::info("✅ Matched: {$pnlItem->hotel_name} -> {$bestMatch['hotel_name']} ({$bestMatch['check_in_date']} to {$bestMatch['check_out_date']})");
                
                $usedEmailHotels[] = $bestIndex;
            } else {
                $results[] = [
                    'pnl_item_id' => $pnlItem->id,
                    'pnl_hotel_name' => $pnlItem->hotel_name,
                    'matched' => false,
                    'reason' => 'No match found'
                ];
                Log::warning("❌ No match for: {$pnlItem->hotel_name}");
            }
        }
        
        return $results;
    }
    
    public function matchAllPnLRecords()
    {
        $pnlRecords = PnlRecord::whereNotNull('tour_ref')
            ->where('tour_ref', '!=', 'NA')
            ->where('tour_ref', '!=', '')
            ->orderBy('created_at', 'desc')
            ->get();
        
        $results = [];
        foreach ($pnlRecords as $record) {
            $result = $this->matchHotelDates($record->id);
            $results[$record->id] = $result;
            usleep(200000);
        }
        
        return $results;
    }
    
    public function previewHotelMatch($pnlRecordId)
    {
        $pnlRecord = PnlRecord::with('items')->find($pnlRecordId);
        
        if (!$pnlRecord) {
            return ['success' => false, 'message' => 'PNL record not found'];
        }
        
        $tourEmail = $this->findTourConfirmationEmail($pnlRecord);
        
        if (!$tourEmail) {
            return ['success' => false, 'message' => 'No matching tour email found'];
        }
        
        $hotelData = $this->extractHotelDataFromEmail($tourEmail);
        $hotelItems = PnlItem::where('pnl_record_id', $pnlRecord->id)
            ->where('type', 'HOTEL')
            ->get();
        
        return [
            'success' => true,
            'pnl_record' => [
                'id' => $pnlRecord->id,
                'tour_ref' => $pnlRecord->tour_ref,
                'invoice_number' => $pnlRecord->invoice_number,
                'agent_name' => $pnlRecord->agent_name,
            ],
            'tour_email' => [
                'id' => $tourEmail->id,
                'subject' => $tourEmail->subject,
                'received_at' => $tourEmail->received_at,
            ],
            'pnl_hotels' => $hotelItems->map(function($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->hotel_name,
                    'current_start_date' => $item->start_date,
                    'current_end_date' => $item->end_date,
                ];
            }),
            'email_hotels' => $hotelData,
        ];
    }
}