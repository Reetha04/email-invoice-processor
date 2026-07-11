<?php

namespace App\Services;

use App\Models\PnlItem;
use App\Models\PnlRecord;
use Illuminate\Support\Facades\Log;
use App\Models\IncomingEmail;

class SriLankaPnLParser
{
   public function parseAndSaveItems($record)
    {
        $tcContent = $record->body ?? '';
        $currency = $record->currency ?? 'USD';
        $totalAmount = $record->amount ?? 0;

        // Try OpenAI extraction first
        $openAI = app(\App\Services\OpenAIService::class);
        $result = $openAI->extractPNLData($tcContent, $record->invoice_number);

        if ($result['success'] && !empty($result['data'])) {
            Log::info("✅ Using OpenAI extraction for: {$record->invoice_number}");
            $data = $result['data'];
            
            // Update total amount
            if (isset($data['total_tour_cost']) && $data['total_tour_cost'] > 0) {
                $totalAmount = $data['total_tour_cost'];
                $record->amount = $totalAmount;
                $record->save();
            }
            
            // Update profit/loss
            if (isset($data['profit_loss'])) {
                $record->profit_loss = $data['profit_loss'];
                $record->save();
                Log::info("✅ Profit/Loss from OpenAI: {$data['profit_loss']} USD");
            }

            // Delete existing items
            PnlItem::where('pnl_record_id', $record->id)->delete();

            // Create INVOICE item
            $this->createInvoiceItem($record, $totalAmount, $currency);

            // Hotels
            $hotels = [];
            if (isset($data['hotels']) && is_array($data['hotels'])) {
                foreach ($data['hotels'] as $hotel) {
                    $hotels[] = [
                        'service_name' => $hotel['name'] ?? 'Unknown Hotel',
                        'amount' => floatval($hotel['amount'] ?? 0),
                        'details' => [
                            'nights' => $hotel['nights'] ?? null,
                            'remarks' => ($hotel['name'] ?? '') . (isset($hotel['nights']) ? " ({$hotel['nights']} nights)" : '')
                        ]
                    ];
                }
            }

            // Transport
            $transport = [];
            if (isset($data['transport_items']) && is_array($data['transport_items'])) {
                foreach ($data['transport_items'] as $item) {
                    $transport[] = [
                        'service_name' => $item['service_name'] ?? 'Unknown Transport',
                        'amount' => floatval($item['amount'] ?? 0),
                        'details' => [
                            'remarks' => ($item['service_name'] ?? '') . 
                                        (isset($item['distance']) ? " - {$item['distance']} KM" : '') .
                                        (isset($item['rate']) ? ", Rate: {$item['rate']}" : ''),
                            'distance_days' => $item['distance'] ?? null,
                            'rate' => $item['rate'] ?? null,
                        ]
                    ];
                }
            }

            // Meals (if any)
            $meals = [];
            if (isset($data['meals']) && is_array($data['meals'])) {
                foreach ($data['meals'] as $meal) {
                    $meals[] = [
                        'service_name' => $meal['service_name'] ?? 'Meals',
                        'amount' => floatval($meal['amount'] ?? 0),
                        'details' => ['remarks' => $meal['service_name'] ?? 'Meals']
                    ];
                }
            }

            // Tickets (if any)
            $tickets = [];
            if (isset($data['tickets']) && is_array($data['tickets'])) {
                foreach ($data['tickets'] as $ticket) {
                    $tickets[] = [
                        'service_name' => $ticket['service_name'] ?? 'Tickets',
                        'amount' => floatval($ticket['amount'] ?? 0),
                        'details' => ['remarks' => $ticket['service_name'] ?? 'Tickets']
                    ];
                }
            }

            // Other Rates (if any)
            $otherRates = [];
            if (isset($data['other_rates']) && is_array($data['other_rates'])) {
                foreach ($data['other_rates'] as $rate) {
                    $otherRates[] = [
                        'service_name' => $rate['service_name'] ?? 'Other Rates',
                        'amount' => floatval($rate['amount'] ?? 0),
                        'details' => ['remarks' => $rate['service_name'] ?? 'Other Rates']
                    ];
                }
            }

            // Save all items
            $this->saveItems($record, 'HOTEL', $hotels, $currency);
            $this->saveItems($record, 'TRANSPORT', $transport, $currency);
            $this->saveItems($record, 'MEALS', $meals, $currency);
            $this->saveItems($record, 'TICKETS', $tickets, $currency);
            $this->saveItems($record, 'OTHER RATES', $otherRates, $currency);

            Log::info("✅ LK items saved using OpenAI: Hotels=" . count($hotels) . ", Transport=" . count($transport) . 
                       ", Meals=" . count($meals) . ", Tickets=" . count($tickets) . ", Other=" . count($otherRates));

            // ✅ Update dates from matching invoice
            $this->updateItemDatesFromInvoice($record);

            return;
        }

        // Fallback: Use regex if OpenAI fails
        Log::warning("⚠️ OpenAI extraction failed for: {$record->invoice_number}, falling back to regex");
        $this->parseWithRegex($record);
        // Also update dates after regex
        $this->updateItemDatesFromInvoice($record);
    }

private function updateItemDatesFromInvoice($record)
{
    try {
        // Find matching IncomingEmail using invoice_number or tour_ref
        $email = IncomingEmail::where('invoice_number', $record->invoice_number)
            ->orWhere('tour_ref', $record->tour_ref)
            ->first();

        if (!$email) {
            Log::info("No matching invoice email found for record: {$record->id}");
            return;
        }

        $startDate = $email->travel_start_date;
        $endDate = $email->travel_end_date;

        if (!$startDate && !$endDate) {
            Log::info("No travel dates in invoice email for: {$record->invoice_number}");
            return;
        }

        Log::info("✅ Updating item dates from invoice: start={$startDate}, end={$endDate}");

        // ✅ Update non-hotel items with overall travel dates
        $items = PnlItem::where('pnl_record_id', $record->id)
            ->where('type', '!=', 'HOTEL')
            ->get();

        foreach ($items as $item) {
            if ($startDate) {
                $item->start_date = $startDate;
            }
            if ($endDate) {
                $item->end_date = $endDate;
            }
            $item->save();
        }

        Log::info("✅ Updated " . $items->count() . " non-hotel items with invoice dates");

        // ✅ HOTELS: Get individual dates from TC body
        $hotelItems = PnlItem::where('pnl_record_id', $record->id)
            ->where('type', 'HOTEL')
            ->get();

        if ($hotelItems->isEmpty()) {
            return;
        }

        // ✅ Extract hotel dates from the TC content body
        $tcContent = $record->body ?? '';
        $hotelDates = $this->extractHotelDatesFromTC($tcContent);

        Log::info("📋 Extracted hotel dates: " . json_encode($hotelDates));

        // ✅ Update each hotel with its specific dates
        $currentDate = $startDate;
        $index = 0;

        foreach ($hotelItems as $hotel) {
            // Try to find dates from extracted hotel data
            $hotelName = $hotel->service_name;
            $foundDates = null;

            foreach ($hotelDates as $hDate) {
                if (stripos($hotelName, $hDate['name']) !== false || 
                    stripos($hDate['name'], $hotelName) !== false) {
                    $foundDates = $hDate;
                    break;
                }
            }

            if ($foundDates) {
                // ✅ Use extracted dates
                $hotel->start_date = $foundDates['check_in'];
                $hotel->end_date = $foundDates['check_out'];
                Log::info("✅ Hotel {$hotelName}: {$foundDates['check_in']} to {$foundDates['check_out']}");
            } else {
                // ✅ Fallback: Use sequential dates
                $details = json_decode($hotel->item_details, true);
                $nights = $details['nights'] ?? 1;
                $checkIn = $currentDate;
                $checkOut = date('Y-m-d', strtotime($checkIn . ' + ' . $nights . ' days'));
                
                $hotel->start_date = $checkIn;
                $hotel->end_date = $checkOut;
                
                // ✅ Update current date for next hotel
                $currentDate = $checkOut;
                Log::info("⚠️ Hotel {$hotelName} (fallback): {$checkIn} to {$checkOut} ({$nights} nights)");
            }
            
            $hotel->save();
        }

        Log::info("✅ Updated " . $hotelItems->count() . " hotel items with individual dates");

    } catch (\Exception $e) {
        Log::error("Error updating item dates from invoice: " . $e->getMessage());
    }
}
/**
 * Extract hotel check-in/check-out dates from TC content
 */
private function extractHotelDatesFromTC($tcContent)
{
    $hotelDates = [];
    
    // Pattern: "Kandy Jul 11, 2026 - Jul 13, 2026 Radisson Hotel Kandy (OZO Kandy)"
    if (preg_match_all('/([A-Za-z]+)\s+([A-Za-z]+\s+\d{1,2},\s+\d{4})\s*-\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})\s+([A-Za-z0-9\s\(\)]+)/i', $tcContent, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $city = trim($match[1]);
            $checkInRaw = trim($match[2]);
            $checkOutRaw = trim($match[3]);
            $hotelName = trim($match[4]);
            
            // Convert dates to Y-m-d
            $checkIn = date('Y-m-d', strtotime($checkInRaw));
            $checkOut = date('Y-m-d', strtotime($checkOutRaw));
            
            if ($checkIn && $checkOut) {
                $hotelDates[] = [
                    'name' => $hotelName,
                    'city' => $city,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ];
                Log::info("✅ Extracted hotel date: {$hotelName} - {$checkIn} to {$checkOut}");
            }
        }
    }
    
    // ✅ Alternative pattern if above doesn't match
    if (empty($hotelDates)) {
        // Pattern: "Jul 11, 2026 - Jul 13, 2026 Radisson Hotel Kandy"
        if (preg_match_all('/([A-Za-z]+\s+\d{1,2},\s+\d{4})\s*-\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})\s+([A-Za-z0-9\s\(\)]+)/i', $tcContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $checkInRaw = trim($match[1]);
                $checkOutRaw = trim($match[2]);
                $hotelName = trim($match[3]);
                
                $checkIn = date('Y-m-d', strtotime($checkInRaw));
                $checkOut = date('Y-m-d', strtotime($checkOutRaw));
                
                if ($checkIn && $checkOut) {
                    $hotelDates[] = [
                        'name' => $hotelName,
                        'city' => '',
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                    ];
                    Log::info("✅ Extracted hotel date (alt): {$hotelName} - {$checkIn} to {$checkOut}");
                }
            }
        }
    }
    
    return $hotelDates;
}
    /**
     * Fallback: Parse with regex
     */
    private function parseWithRegex($record)
    {
        $tcContent = $record->body ?? '';
        $currency = $record->currency ?? 'USD';
        $totalAmount = $record->amount ?? 0;

        if ($totalAmount == 0) {
            $totalAmount = $this->extractTotalAmount($tcContent);
            $record->amount = $totalAmount;
            $record->save();
        }

        $profitLoss = $this->extractProfitLoss($tcContent);
        if ($profitLoss !== null) {
            $record->profit_loss = $profitLoss;
            $record->save();
        }

        PnlItem::where('pnl_record_id', $record->id)->delete();
        $this->createInvoiceItem($record, $totalAmount, $currency);

        $hotels = $this->extractHotelsRegex($tcContent);
        $meals = $this->extractMealsRegex($tcContent);
        $transport = $this->extractTransportRegex($tcContent);
        $tickets = $this->extractTicketsRegex($tcContent);
        $otherRates = $this->extractOtherRatesRegex($tcContent);

        $this->saveItems($record, 'HOTEL', $hotels, $currency);
        $this->saveItems($record, 'MEALS', $meals, $currency);
        $this->saveItems($record, 'TRANSPORT', $transport, $currency);
        $this->saveItems($record, 'TICKETS', $tickets, $currency);
        $this->saveItems($record, 'OTHER RATES', $otherRates, $currency);
    }

    private function createInvoiceItem($record, $totalAmount, $currency)
    {
        PnlItem::create([
            'pnl_record_id' => $record->id,
            'type' => 'INVOICE',
            'service_name' => 'Total Tour Package',
            'amount_original' => $totalAmount,
            'currency' => $currency,
            'start_date' => $record->travel_start_date,
            'end_date' => $record->travel_end_date,
            'invoice_number' => $record->invoice_number,
            'agent_name' => $record->agent_name,
            'credit_type' => $record->credit_type ?? 'Credit',
            'control_number' => $record->tour_ref,
            'country_code' => $record->country_code,
            'client_name' => $record->guest_name ?? $record->vendor_name ?? null,
            'item_details' => json_encode([
                'remarks' => "Pax: {$record->pax_count}, Nights: {$record->nights}"
            ])
        ]);
        Log::info("✅ INVOICE item created: Total Tour Package - \${$totalAmount}");
    }

    private function saveItems($record, $type, $items, $currency)
    {
        foreach ($items as $item) {
            if ($item['amount'] <= 0) continue;
            PnlItem::create([
                'pnl_record_id' => $record->id,
                'type' => $type,
                'service_name' => $item['service_name'],
                'amount_original' => -abs($item['amount']),
                'currency' => $currency,
                'start_date' => $record->travel_start_date,
                'end_date' => $record->travel_end_date,
                'invoice_number' => $record->invoice_number,
                'agent_name' => $record->agent_name,
                'credit_type' => $record->credit_type ?? 'Credit',
                'control_number' => $record->tour_ref,
                'country_code' => $record->country_code,
                'client_name' => $record->guest_name ?? $record->vendor_name ?? null,
                'item_details' => json_encode($item['details'] ?? ['remarks' => $item['service_name']])
            ]);
            Log::info("✅ LK {$type}: {$item['service_name']} - \${$item['amount']}");
        }
    }

    // ====================== REGEX FALLBACK METHODS ======================

    private function extractTotalAmount($text)
    {
        if (preg_match('/Total\s+Tour\s+Cost\s*[:]?\s*\$?\s*([\d,]+\.\d{2})/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        if (preg_match('/Total\s+Mega\s+Cost\s*[:]?\s*\$?\s*([\d,]+\.\d{2})/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        if (preg_match('/\$\s*([\d,]+\.\d{2})/', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        return 0;
    }

    private function extractProfitLoss($text)
    {
        if (preg_match('/Profit\/Loss\s*[:]?\s*([\d,]+\.\d{2})\s*USD/i', $text, $match)) {
            return floatval(str_replace(',', '', $match[1]));
        }
        return null;
    }

    private function extractHotelsRegex($text)
    {
        $hotels = [];
        if (preg_match('/(?:Hotels\/Cruises|Accommodation\s*-\s*SGL|Accommodation).*?(?=Transport|Meal|Total Tour Cost|Total Mega Cost|$)/is', $text, $sectionMatch)) {
            $section = $sectionMatch[0];
            $lines = preg_split('/\r\n|\n|\r/', $section);
            $currentHotel = null;
            $buffer = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/^(Name|SGL|DBL|TPL|CWB|CNB|Nights|Room|Night|Total|Accommodation|Hotels\/Cruises)/i', $line)) {
                    continue;
                }
                if (preg_match('/^Total\s+/i', $line)) {
                    continue;
                }
                if (preg_match('/^[A-Za-z]/', $line) && !preg_match('/^\d/', $line)) {
                    if ($currentHotel !== null && !empty($buffer)) {
                        $this->parseHotelBuffer($currentHotel, $buffer, $hotels);
                    }
                    $currentHotel = $line;
                    $buffer = [];
                } else {
                    $buffer[] = $line;
                }
            }
            if ($currentHotel !== null && !empty($buffer)) {
                $this->parseHotelBuffer($currentHotel, $buffer, $hotels);
            }
        }

        // Fallback
        if (empty($hotels)) {
            if (preg_match_all('/^([A-Za-z\s\.\,\&\-\(\)]+)\s+([\d,]+\.\d{2})$/im', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = trim($match[1]);
                    $amount = floatval(str_replace(',', '', $match[2]));
                    if ($amount > 0 && !empty($name) && !is_numeric($name) && stripos($name, 'total') === false) {
                        $hotels[] = [
                            'service_name' => $name,
                            'amount' => $amount,
                            'details' => ['remarks' => $name]
                        ];
                        Log::info("✅ Hotel extracted (fallback): {$name} - \${$amount}");
                    }
                }
            }
        }
        return $hotels;
    }

    private function parseHotelBuffer($hotelName, $buffer, &$hotels)
    {
        $combined = implode(' ', $buffer);
        if (preg_match('/([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*$/', $combined, $matches)) {
            $hotelTotal = floatval(str_replace(',', '', $matches[2]));
            $nights = null;
            if (preg_match('/\/\s*(\d+)\s+/', $combined, $nightMatch)) {
                $nights = intval($nightMatch[1]);
            }
            if ($hotelTotal > 0) {
                $hotels[] = [
                    'service_name' => trim($hotelName),
                    'amount' => $hotelTotal,
                    'details' => [
                        'nights' => $nights,
                        'remarks' => trim($hotelName) . ($nights ? " ({$nights} nights)" : '')
                    ]
                ];
                Log::info("✅ Hotel extracted: {$hotelName} - \${$hotelTotal}" . ($nights ? " ({$nights} nights)" : ''));
            }
        }
    }

    private function extractMealsRegex($text)
    {
        $meals = [];
        if (preg_match('/Meals(.*?)(?:Transport|Tickets|Attraction|Tour Transfers|Total Tour Cost|$)/is', $text, $sectionMatch)) {
            $section = $sectionMatch[0];
            $lines = explode("\n", $section);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/^Day|^Break|^Lunch|^Dinner|^Total/i', $line)) continue;
                if (preg_match('/^Day\s*-\s*(\d+).*?([\d,]+\.\d{2})\s*USD/i', $line, $matches)) {
                    $day = intval($matches[1]);
                    $amount = floatval(str_replace(',', '', $matches[2]));
                    if ($amount > 0) {
                        $meals[] = [
                            'service_name' => "Day {$day} - Meals",
                            'amount' => $amount,
                            'details' => ['remarks' => "Day {$day} - Total meals"]
                        ];
                        Log::info("✅ Meal extracted: Day {$day} - \${$amount}");
                    }
                }
            }
        }
        return $meals;
    }

    private function extractTicketsRegex($text)
    {
        $tickets = [];
        if (preg_match('/Tickets(.*?)(?:Transport|Meals|Attraction|Tour Transfers|Total Tour Cost|$)/is', $text, $sectionMatch)) {
            $section = $sectionMatch[0];
            $lines = explode("\n", $section);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/^([A-Za-z\s\-]+?)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+([\d,]+\.\d{2})\s*USD/i', $line, $matches)) {
                    $serviceName = trim($matches[1]);
                    $amount = floatval($matches[6]);
                    if ($amount > 0 && !empty($serviceName) && stripos($serviceName, 'total') === false) {
                        $tickets[] = [
                            'service_name' => $serviceName,
                            'amount' => $amount,
                            'details' => ['remarks' => $serviceName]
                        ];
                        Log::info("✅ Ticket extracted: {$serviceName} - \${$amount}");
                    }
                }
            }
        }
        return $tickets;
    }

    private function extractTransportRegex($text)
    {
        $items = [];
        if (preg_match('/Transport\s*(?:\(Only Sri Lanka\))?.*?Distance\/Days\s*Rate\s*Total(.*?)(?:Attraction|Tour Transfers|Meals|Accommodation|Total Tour Cost|$)/is', $text, $sectionMatch)) {
            $section = $sectionMatch[0];
            $lines = explode("\n", $section);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/^Distance|^Rate|^Total|^Transport|^Other Cost|^Expense/i', $line)) continue;
                if (stripos($line, 'Other Cost') !== false) continue;
                if (stripos($line, 'Total') !== false && preg_match('/^\s*Total\s*$/i', $line)) continue;

                if (stripos($line, 'Water Bottles') !== false) {
                    if (preg_match('/Water Bottles.*?([\d,]+\.\d{2})\s*USD/i', $line, $match)) {
                        $amount = floatval($match[1]);
                        if ($amount > 0) {
                            $items[] = [
                                'service_name' => 'Water Bottles',
                                'amount' => $amount,
                                'details' => ['remarks' => 'Water Bottles']
                            ];
                            Log::info("✅ Water Bottles extracted: \${$amount}");
                        }
                    }
                    continue;
                }

                if (preg_match('/^([A-Za-z\s]+?)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s*$/', $line, $matches)) {
                    $serviceName = trim($matches[1]);
                    if (stripos($serviceName, 'total') !== false) continue;
                    $distance = floatval($matches[2]);
                    $rate = floatval($matches[3]);
                    $amount = floatval($matches[4]);
                    if ($amount > 0 && !empty($serviceName)) {
                        $items[] = [
                            'service_name' => $serviceName,
                            'amount' => $amount,
                            'details' => [
                                'remarks' => "{$serviceName} - {$distance} KM, Rate: {$rate}",
                                'distance_days' => $distance,
                                'rate' => $rate,
                            ]
                        ];
                        Log::info("✅ Transport extracted: {$serviceName} - \${$amount} ({$distance} KM)");
                    }
                } elseif (preg_match('/^([A-Za-z\s]+?)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s*$/', $line, $matches)) {
                    $serviceName = trim($matches[1]);
                    $rate = floatval($matches[2]);
                    $amount = floatval($matches[3]);
                    if ($amount > 0 && !empty($serviceName) && stripos($serviceName, 'total') === false) {
                        $items[] = [
                            'service_name' => $serviceName,
                            'amount' => $amount,
                            'details' => [
                                'remarks' => "{$serviceName} - Rate: {$rate}",
                                'rate' => $rate,
                            ]
                        ];
                        Log::info("✅ Transport extracted: {$serviceName} - \${$amount}");
                    }
                }
            }
        }
        return $items;
    }

    private function extractOtherRatesRegex($text)
    {
        $items = [];
        if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|Transport|Total|$)/is', $text, $sectionMatch)) {
            $section = $sectionMatch[0];
            $lines = explode("\n", $section);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/PAX|RATE|TOTAL|Item|Name/i', $line)) continue;
                if (stripos($line, 'total') !== false) continue;

                if (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d,]+\.?\d*)\s*\|/', $line, $match)) {
                    $serviceName = trim($match[1]);
                    $pax = floatval($match[2]);
                    $rate = floatval($match[3]);
                    $amount = floatval(str_replace(',', '', $match[4]));
                    if ($amount > 0 && !empty($serviceName) && stripos($serviceName, 'total') === false) {
                        $items[] = [
                            'service_name' => $serviceName,
                            'amount' => $amount,
                            'details' => [
                                'remarks' => "Pax: {$pax}, Rate: {$rate}",
                                'pax' => $pax,
                                'rate' => $rate,
                            ]
                        ];
                        Log::info("✅ Other Rate extracted: {$serviceName} - \${$amount}");
                    }
                } elseif (preg_match('/^([A-Za-z\s]+)\s+([\d,]+\.?\d*)$/', $line, $match)) {
                    $serviceName = trim($match[1]);
                    $amount = floatval(str_replace(',', '', $match[2]));
                    if ($amount > 0 && !empty($serviceName) && !preg_match('/total|rate|pax/i', $serviceName)) {
                        $items[] = [
                            'service_name' => $serviceName,
                            'amount' => $amount,
                            'details' => ['remarks' => $serviceName]
                        ];
                        Log::info("✅ Other Rate extracted (fallback): {$serviceName} - \${$amount}");
                    }
                }
            }
        }
        return $items;
    }
}