<?php
// app/Services/ServiceNameMatcher.php

namespace App\Services;

use App\Models\PnlItem;
use App\Models\PnlRecord;
use App\Models\IncomingEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ServiceNameMatcher
{
    private $apiKey;
    
    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY');
    }
    
    /**
     * Match PNL items and update dates
     */
    public function matchAndUpdatePnLItems($pnlRecordId)
    {
        try {
            $pnlRecord = PnlRecord::with('items')->find($pnlRecordId);
            
            if (!$pnlRecord) {
                return ['success' => false, 'message' => 'PNL record not found'];
            }
            
            $tourEmail = IncomingEmail::where('tour_ref', $pnlRecord->tour_ref)
                ->where('is_tour_confirmation', true)
                ->first();
            
            if (!$tourEmail) {
                Log::info("No tour confirmation email found for: " . $pnlRecord->tour_ref);
                return ['success' => false, 'message' => 'No matching tour email found'];
            }
            
            $itinerary = $this->extractDayByDayItinerary($tourEmail);
            
            if (empty($itinerary)) {
                Log::info("No itinerary found for: " . $pnlRecord->tour_ref);
                return ['success' => false, 'message' => 'No itinerary found'];
            }
            
            // Log itinerary
            foreach ($itinerary as $item) {
                Log::info("Day {$item['day']}: {$item['date']} - Services: " . implode(', ', $item['services'] ?? []));
            }
            
            $pnlItems = PnlItem::where('pnl_record_id', $pnlRecord->id)
                ->where('type', '!=', 'INVOICE')
                ->get();
            
            if ($pnlItems->isEmpty()) {
                return ['success' => false, 'message' => 'No PNL items to match'];
            }
            
            $matchedCount = 0;
            $updatedItems = [];
            
            foreach ($pnlItems as $pnlItem) {
                $pnlServiceName = $pnlItem->service_name;
                
                $match = $this->findMatchingDay($pnlServiceName, $itinerary);
                
                if ($match) {
                    $pnlItem->start_date = $match['date'];
                    $pnlItem->end_date = $match['date'];
                    $pnlItem->matched_service_name = $match['service_name'];
                    $pnlItem->match_confidence = $match['confidence'];
                    $pnlItem->matched_at = now();
                    $pnlItem->save();
                    
                    $matchedCount++;
                    $updatedItems[] = [
                        'pnl_service' => $pnlServiceName,
                        'matched_service' => $match['service_name'],
                        'date' => $match['date'],
                        'day' => $match['day'],
                        'confidence' => $match['confidence']
                    ];
                    
                    Log::info("✅ Updated: {$pnlServiceName} -> Day {$match['day']}: {$match['date']}");
                } else {
                    Log::info("❌ No match found for: {$pnlServiceName}");
                }
            }
            
            return [
                'success' => true,
                'matched_count' => $matchedCount,
                'total_items' => $pnlItems->count(),
                'updates' => $updatedItems,
                'itinerary' => $itinerary,
                'tour_email_id' => $tourEmail->id,
                'tour_ref' => $pnlRecord->tour_ref
            ];
            
        } catch (\Exception $e) {
            Log::error('Service matching failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Extract DAY-BY-DAY itinerary with dates
     */
    private function extractDayByDayItinerary($tourEmail)
    {
        $itinerary = [];
        $body = $tourEmail->body ?? '';
        
        // Get travel start date
        $travelStartDate = $tourEmail->travel_start_date;
        
        Log::info("Travel Start Date: " . ($travelStartDate ?? 'null'));
        
        // ✅ Extract "DAY X - Date : Description" pattern
        $dayPattern = '/DAY\s*(\d+)\s*[-–]\s*([A-Za-z]+\s+\d{1,2},\s*\d{4})\s*:\s*(.*?)(?=DAY\s*\d+|$)/is';
        if (preg_match_all($dayPattern, $body, $matches, PREG_SET_ORDER)) {
            Log::info("Found " . count($matches) . " days in DAY format");
            
            foreach ($matches as $match) {
                $day = intval($match[1]);
                $dateStr = trim($match[2]);
                $description = trim($match[3]);
                
                try {
                    $date = Carbon::parse($dateStr)->format('Y-m-d');
                    
                    // Extract attractions/services from this day
                    $services = $this->extractServicesFromDay($description, $day);
                    
                    $itinerary[] = [
                        'day' => $day,
                        'date' => $date,
                        'description' => $description,
                        'services' => $services,
                        'source' => 'day_format'
                    ];
                    
                    Log::info("Day {$day}: {$date} - Services: " . implode(', ', $services));
                    
                } catch (\Exception $e) {
                    Log::error("Date parsing error for Day {$day}: " . $e->getMessage());
                }
            }
        }
        
        // Sort by day
        usort($itinerary, function($a, $b) {
            return $a['day'] - $b['day'];
        });
        
        Log::info("Extracted " . count($itinerary) . " itinerary items");
        return $itinerary;
    }
    
    /**
     * Extract services from a day's description
     */
    private function extractServicesFromDay($description, $day)
    {
        $services = [];
        
        // ✅ Look for "Attraction XXX:" pattern
        if (preg_match_all('/Attraction\s+([^:]+):/i', $description, $matches)) {
            foreach ($matches[1] as $name) {
                $name = trim($name);
                if (!empty($name) && strlen($name) > 3) {
                    $services[] = $name;
                }
            }
        }
        
        // ✅ Look for "City Tour XXX:" pattern
        if (preg_match_all('/City Tour\s+([^:]+):/i', $description, $matches)) {
            foreach ($matches[1] as $name) {
                $name = trim($name);
                if (!empty($name) && strlen($name) > 3) {
                    $services[] = $name;
                }
            }
        }
        
        // ✅ Look for "XXX transfers:" pattern
        if (preg_match_all('/([A-Za-z\s]+)\s+transfers:/i', $description, $matches)) {
            foreach ($matches[1] as $name) {
                $name = trim($name);
                if (!empty($name) && strlen($name) > 3) {
                    $services[] = $name . ' transfers';
                }
            }
        }
        
        // ✅ If no services found, add the whole description as a service
        if (empty($services) && !empty($description) && strlen($description) > 10) {
            $services[] = substr($description, 0, 100);
        }
        
        return $services;
    }
    
    /**
     * Find which day a PNL service belongs to - FIXED VERSION
     */
    private function findMatchingDay($pnlServiceName, $itinerary)
    {
        if (empty($itinerary)) {
            return null;
        }
        
        $pnlLower = strtolower($pnlServiceName);
        Log::info("🔍 Finding match for: " . $pnlServiceName);
        
        // ✅ STEP 1: Direct keyword matching
        $directMatches = [
            'sunset town' => 'sunset town',
            'kiss of the sea' => 'sunset town',
            'kots' => 'sunset town',
            'kiss bridge' => 'sunset town',
            '4 islands' => '4 islands',
            'hon thom' => '4 islands',
            'cable car' => '4 islands',
            'grand world' => 'grand world',
            'vinwonders' => 'grand world',
            'vinpearl' => 'grand world',
        ];
        
        $matchedKeyword = null;
        foreach ($directMatches as $keyword => $matchGroup) {
            if (strpos($pnlLower, $keyword) !== false) {
                $matchedKeyword = $matchGroup;
                break;
            }
        }
        
        if ($matchedKeyword) {
            foreach ($itinerary as $item) {
                foreach ($item['services'] ?? [] as $service) {
                    $serviceLower = strtolower($service);
                    if (strpos($serviceLower, $matchedKeyword) !== false) {
                        Log::info("✅ Keyword match: {$pnlServiceName} -> Day {$item['day']} ({$item['date']})");
                        return [
                            'service_name' => $service,
                            'date' => $item['date'],
                            'day' => $item['day'],
                            'confidence' => 0.95
                        ];
                    }
                }
            }
        }
        
        // ✅ STEP 2: Try partial match with service names
        foreach ($itinerary as $item) {
            foreach ($item['services'] ?? [] as $service) {
                if ($this->isServiceMatch($pnlServiceName, $service)) {
                    Log::info("✅ Service match: Day {$item['day']}");
                    return [
                        'service_name' => $service,
                        'date' => $item['date'],
                        'day' => $item['day'],
                        'confidence' => 0.9
                    ];
                }
            }
        }
        
        // ✅ STEP 3: AI fallback
        return $this->matchWithAI($pnlServiceName, $itinerary);
    }
    
    /**
     * Check if two service names match
     */
    private function isServiceMatch($service1, $service2)
    {
        $s1 = strtolower(trim($service1));
        $s2 = strtolower(trim($service2));
        
        $removeWords = ['transfer', 'private', 'basis', 'sic', 'tour', 'city', 'airport', 'hotel', 'transport', 'to', 'from', 'and', 'the', 'with', 'for', 'on', 'at', 'of'];
        foreach ($removeWords as $word) {
            $s1 = str_replace($word, '', $s1);
            $s2 = str_replace($word, '', $s2);
        }
        
        $s1 = preg_replace('/\s+/', ' ', trim($s1));
        $s2 = preg_replace('/\s+/', ' ', trim($s2));
        
        if (strlen($s1) > 3 && strlen($s2) > 3) {
            if (strpos($s1, $s2) !== false || strpos($s2, $s1) !== false) {
                return true;
            }
        }
        
        similar_text($s1, $s2, $percent);
        return $percent > 60;
    }
    
    /**
     * Use AI to match service with day
     */
    private function matchWithAI($pnlServiceName, $itinerary)
    {
        try {
            $itineraryText = '';
            foreach ($itinerary as $item) {
                $services = implode(', ', $item['services'] ?? []);
                $itineraryText .= "Day {$item['day']} ({$item['date']}): {$services}\n";
            }
            
            $prompt = "Match this service to the correct day in the itinerary.\n\n";
            $prompt .= "Service: \"{$pnlServiceName}\"\n\n";
            $prompt .= "Itinerary:\n{$itineraryText}\n\n";
            $prompt .= "Return ONLY the day number. If no match, return 0.";
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'You match services to itinerary days. Return only the day number.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.1,
                'max_tokens' => 10,
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $day = intval(trim($data['choices'][0]['message']['content'] ?? '0'));
                
                foreach ($itinerary as $item) {
                    if ($item['day'] === $day) {
                        Log::info("✅ AI match found: Day {$day}");
                        return [
                            'service_name' => $item['services'][0] ?? 'Activity',
                            'date' => $item['date'],
                            'day' => $item['day'],
                            'confidence' => 0.8
                        ];
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error('AI matching failed: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Match all PNL records
     */
    public function matchAllPnLRecords()
    {
        $pnlRecords = PnlRecord::whereNotNull('tour_ref')
            ->where('tour_ref', '!=', 'NA')
            ->where('tour_ref', '!=', '')
            ->get();
        
        $results = [];
        foreach ($pnlRecords as $record) {
            $result = $this->matchAndUpdatePnLItems($record->id);
            $results[$record->id] = $result;
        }
        
        return $results;
    }
}