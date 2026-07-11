<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    protected $apiKey;
    protected $model;

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY');
       $this->model = 'gpt-4.1-mini';
    }

    /**
     * Extract Guest ID from email content using OpenAI
     */
    public function extractGuestId($plainText, $htmlBody = null)
    {
        try {
            $prompt = $this->buildGuestIdPrompt($plainText);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI assistant that extracts specific information from tour confirmation emails. Always respond with only the extracted value, nothing else. If the information is not found, respond with "NA".'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 50,
            ]);

            if ($response->successful()) {
                $result = trim($response->json()['choices'][0]['message']['content'] ?? 'NA');
                Log::info("🤖 OpenAI extracted Guest ID: {$result}");
                return $result;
            } else {
                Log::error('❌ OpenAI API error: ' . $response->body());
                return 'NA';
            }

        } catch (\Exception $e) {
            Log::error('❌ OpenAI extraction failed: ' . $e->getMessage());
            return 'NA';
        }
    }
    /**
     * Build the prompt for Guest ID extraction
     */
    protected function buildGuestIdPrompt($text)
    {
        return <<<PROMPT
Extract the Guest ID from this tour confirmation email. The Guest ID is a unique identifier for the guest/customer.

Look for patterns like:
- "Guests ID: 6A43F887B46606000172B9BB"
- "Guest ID: IN1B1782989769823"
- "Guest ID: 6A45460AB466060001773EC0"
- Any alphanumeric string that appears after "Guests ID" or "Guest ID"

Rules:
1. If you find a Guest ID, return ONLY the ID value (e.g., "6A43F887B46606000172B9BB")
2. If no Guest ID is found, return "NA"
3. Do not return any other text or explanation
4. The Guest ID is typically 20-30 characters long and contains both letters and numbers

Email content:
---
{$text}
---
PROMPT;
    }

    /**
     * Extract multiple fields at once (more efficient)
     */
    public function extractFields($plainText)
    {
        try {
            $prompt = $this->buildMultiFieldPrompt($plainText);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an AI assistant that extracts specific information from tour confirmation emails. Respond in JSON format only.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 150,
            ]);

            if ($response->successful()) {
                $result = $response->json()['choices'][0]['message']['content'] ?? '{}';
                $data = json_decode($result, true);
                
                Log::info("🤖 OpenAI extracted fields: " . json_encode($data));
                return $data;
            } else {
                Log::error('❌ OpenAI API error: ' . $response->body());
                return [];
            }

        } catch (\Exception $e) {
            Log::error('❌ OpenAI multi-field extraction failed: ' . $e->getMessage());
            return [];
        }
    }

   protected function buildMultiFieldPrompt($text)
    {
        return <<<PROMPT
Extract the following fields from this tour confirmation email. Return ONLY valid JSON.

Fields to extract:
1. **guest_id** - The Guest/Booking/MMT ID
   - Look for these labels: "Guests ID:", "Guest ID:", "Booking ID:", "MMT ID:", "Confirmation ID:", "Booking ID", "Confirmation"
   - Examples: "6A43F887B46606000172B9BB", "IN1B1782989769823", "MMT123456"
   - This is a unique alphanumeric identifier (20-30 characters usually)
   - If multiple IDs found, prefer the one labeled "Guest ID" or "Guests ID"

2. **agent_id** - The Agent/Booking reference
   - Look for these labels: "Agent ID:", "Booking ID:", "MMT ID:", "Confirmation ID:"
   - Examples: "6A43F887B46606000172B9BB", "IN1B1782989769823"
   - Usually same as Guest ID for some bookings

3. **sales_person** - The Sales Person name
   - Look for these labels: "Sales Person:", "Sales:", "Handler:", "File Handler:"
   - Examples: "Mr. Shahinsha", "Saratha", "Madhu", "Esther"
   - If not found, check if there's a name after "Sales Person" or "File Handler"

4. **reference_no** - The Reference/IS Number
   - Look for: "IS Number:", "Reference No:", "Tour Ref:"
   - Example: "VN40232", "MY23030"

5. **agent_name** - The Agent/Company name
   - Look for: "Agent:", "Agent Name:", "Agency:"
   - Example: "FIT", "Global Journeys", "MakeMyTrip"

6. **guest_name** - The Guest/Customer name
   - Look for: "Guests Name:", "Guest Name:", "Name:"
   - Example: "MR. Srinandh Subramanian"

Rules:
- Return ONLY valid JSON
- Use null if field not found
- Sales Person is usually a person's name (Saratha, Madhu, Esther, etc.)
- Guest ID is usually alphanumeric and 20+ characters

Email content:
---
{$text}
---
PROMPT;
    }
/**
 * Extract Total Tour Cost using OpenAI
 */
public function extractTotalTourCost($plainText)
{
    try {
        $prompt = $this->buildTotalCostPrompt($plainText);
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an AI assistant that extracts the Total Tour Cost from tour confirmation emails. Respond in JSON format only with keys: amount and currency.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'max_tokens' => 150,
        ]);

        if ($response->successful()) {
            $result = trim($response->json()['choices'][0]['message']['content'] ?? '{}');
            $data = json_decode($result, true);
            
            if (is_array($data) && isset($data['amount']) && $data['amount'] > 0) {
                Log::info("🤖 OpenAI extracted Total Tour Cost: " . json_encode($data));
                return $data;
            }
            
            Log::warning("⚠️ OpenAI returned invalid format: " . $result);
            return null;
        } else {
            Log::error('❌ OpenAI API error: ' . $response->body());
            return null;
        }

    } catch (\Exception $e) {
        Log::error('❌ OpenAI total cost extraction failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Build prompt for Total Tour Cost extraction
 */
protected function buildTotalCostPrompt($text)
{
    return <<<PROMPT
You are an expert in extracting booking totals from hotel quotations,
tour confirmations, invoices and travel emails.

Your task is to extract the FINAL TOTAL TOUR COST.

Priority:

1. Look for:
- Total Tour Cost
- Grand Total
- Total Amount
- Total Cost
- Amount Payable
- Net Amount
- Final Amount

Return that value.

2. If none exists,
calculate from lines like:

Cost Per Person Double USD 419.71 x 2

Answer:
839.42

Adult USD 300 x2
Child USD 150 x1

Answer:
750

Single USD 500 x1
Twin USD 400 x2

Answer:
1300

Ignore hotel names,
room types,
dates,
booking ids,
reference numbers.

Detect currency automatically.

Possible currencies:

USD
SGD
MYR
INR
EUR
GBP
THB
AED

Return ONLY JSON.

{
"amount":839.42,
"currency":"USD"
}

If not found

{
"amount":null,
"currency":null
}

Email:

$text

PROMPT;
}
public function extractTravelDates($plainText)
{
    try {
        $prompt = <<<PROMPT
Extract the travel start date from this booking email.

Look for:
- "Arrival Date:" followed by a date
- The date can be like "2026-8-19" or "2026-8 -19" (with spaces)

Return ONLY JSON with the start date in Y-m-d format.

Example:
{
    "travel_start": "2026-08-19"
}

If not found:
{
    "travel_start": null,
    "travel_end": null
}

Email:
{$plainText}
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You extract travel dates from emails. Respond ONLY valid JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_object'
            ]
        ]);

        if ($response->successful()) {
            $result = json_decode($response['choices'][0]['message']['content'], true);
            Log::info("🤖 OpenAI Response: " . json_encode($result));
            return $result;
        } else {
            Log::error('❌ OpenAI API error: ' . $response->body());
            return null;
        }

    } catch (\Exception $e) {
        Log::error('❌ OpenAI travel date extraction failed: ' . $e->getMessage());
        return null;
    }
}
/**
 * Extract structured data from text
 */
// In OpenAIService.php, replace the extract method:

public function extract($prompt)
{
    try {
        // ✅ Use Http directly instead of client
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a data extraction assistant. Extract the requested information and return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object']
        ]);

        if ($response->successful()) {
            $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'success' => true,
                    'data' => $data
                ];
            }
        }
        
        return ['success' => false];
        
    } catch (\Exception $e) {
        Log::error("OpenAI extract error: " . $e->getMessage());
        return ['success' => false];
    }
}

 public function extractTCData($tcContent, $invoiceNumber, $folderName)
    {
        try {
            $prompt = $this->buildSimpleTCPrompt($tcContent, $invoiceNumber, $folderName);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert at extracting information from Tour Confirmation documents. Return ONLY valid JSON. No other text.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
                $data = json_decode($content, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info("✅ OpenAI extraction successful for: {$invoiceNumber}");
                    Log::info("📊 Extracted: " . json_encode($data));
                    return [
                        'success' => true,
                        'data' => $data
                    ];
                } else {
                    Log::error("JSON parse error: " . json_last_error_msg());
                    return ['success' => false, 'error' => json_last_error_msg()];
                }
            } else {
                Log::error("OpenAI API error: " . $response->body());
                return ['success' => false, 'error' => $response->body()];
            }

        } catch (\Exception $e) {
            Log::error("OpenAI TC extraction failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function buildSimpleTCPrompt($content, $invoiceNumber, $folderName)
    {
        return <<<PROMPT
Extract these 4 fields from this Tour Confirmation document:

1. **tour_ref** - The Tour Reference number
   - Look for these labels: "Tour Ref", "Tour Reference", "TourRef"
   - Extract the full value that comes after the label
   - Examples: "448629CNTL | MY23030", "460372CNTL // MY231113", "MY23030"
   - If you see "448629CNTL|I| MY23030", extract as "448629CNTL | MY23030"

2. **agent_name** - The Agent/Company name
   - Look for these labels: "Agent", "Agent Name", "Agency"
   - Extract the value that comes after the label
   - Examples: "FIT", "Global Journeys", "MakeMyTrip"
   - IMPORTANT: Skip any text that says "Agent name revised" - that's from the title

3. **arrival_date** - The travel start date
   - Look for labels: "Arrival Date", "Check-in", "From"
   - Extract the date
   - Format to YYYY-MM-DD
   - Examples: "2026-7-14" → "2026-07-14", "16th Jul" → "2026-07-16"

4. **departure_date** - The travel end date
   - Look for labels: "Departure Date", "Check-out", "To"
   - Extract the date
   - Format to YYYY-MM-DD
   - Examples: "2026-7-17" → "2026-07-17"
   - If not found, return null

**Document:**
{$content}

**Return EXACT JSON:**
{
    "tour_ref": null,
    "agent_name": null,
    "arrival_date": null,
    "departure_date": null
}
PROMPT;
    }

    /**
 * ✅ FALLBACK: Extract TC Data with more explicit context
 */
public function extractTCDataWithMoreContext($tcContent, $invoiceNumber, $folderName)
{
    try {
        $prompt = <<<PROMPT
IMPORTANT: You MUST extract these 4 fields from this Tour Confirmation document.

The document contains labels like:
- "Tour Ref:" or "Tour Reference:" followed by the reference number
- "Agent:" or "Agent Name:" followed by the agent name  
- "Arrival Date:" followed by a date
- "Departure Date:" followed by a date

Look carefully for these labels and extract the value that comes AFTER them.

DOCUMENT:
{$tcContent}

Return ONLY this JSON:
{
    "tour_ref": "the tour reference number",
    "agent_name": "the agent name",
    "arrival_date": "YYYY-MM-DD",
    "departure_date": "YYYY-MM-DD"
}

If a field is not found, use null.
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert at extracting data from documents. Return ONLY valid JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object']
        ]);

        if ($response->successful()) {
            $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info("✅ OpenAI second attempt successful for: {$invoiceNumber}");
                return [
                    'success' => true,
                    'data' => $data
                ];
            }
        }
        
        return ['success' => false];
        
    } catch (\Exception $e) {
        Log::error("OpenAI TC extraction (2nd attempt) failed: " . $e->getMessage());
        return ['success' => false];
    }
}

 protected function buildCompleteTCPrompt($content, $invoiceNumber, $folderName)
    {
        return <<<PROMPT
You are extracting COMPLETE data from a TOUR CONFIRMATION (TC) document.

Extract ALL fields from this document including the table data.

**Document Content:**
{$content}

**Fields to Extract:**

1. **invoice_number** - From folder name or document
   - Current folder: {$folderName}
   - Pattern: "MY23030" or "MY40018"
   - Return as string

2. **tour_ref** - Tour Reference number
   - Look for: "Tour Ref" or "Tour Reference"
   - Example: "448629CNTL | MY23030"
   - Return full value as string

3. **agent_name** - Agent/Company name
   - Look for: "Agent" or "Agent Name"
   - Example: "FIT"
   - Return as string

4. **guest_name** - Guest/Customer name
   - Look for: "Guests Name" or "Guest Name"
   - Example: "MR. Srinandh Subramanian"
   - Return as string

5. **guest_count** - Number of guests (adults)
   - Look for: "No. of Guests" or "Adults"
   - Example: "2 Adults" → extract 2
   - Return as integer

6. **meal_plan** - Meal plan
   - Look for: "Meal Plan" or "Meal Type"
   - Example: "BB" (Bed & Breakfast)
   - Return as string

7. **file_handler** - File handler name
   - Look for: "File Handler"
   - Example: "P. Sarathapriya"
   - Return as string

8. **arrival_date** - Arrival date
   - Look for: "Arrival Date"
   - Format: "2026-7-14" → "2026-07-14"
   - Return in YYYY-MM-DD format

9. **departure_date** - Departure date
   - Look for: "Departure Date"
   - Format: "2026-7-16" → "2026-07-16"
   - Return in YYYY-MM-DD format

10. **hotel_name** - Hotel name from table
    - Look in the table: Hotel column
    - Example: "Own Arrangement" or actual hotel name
    - Return as string

11. **city** - City name from table
    - Look in the table: City column
    - Example: "KualaLumpur" or "Kuala Lumpur"
    - Return as string

12. **nights** - Number of nights from table
    - Look in the table: Nights column
    - Example: 3
    - Return as integer

13. **room_type** - Room type from table
    - Look in the table: Room Type column
    - Example: "Deluxe", "Twin Room"
    - Return as string

14. **meal_type** - Meal type from table
    - Look in the table: Meal Type column
    - Example: "BB", "HB"
    - Return as string

15. **total_amount** - Total tour cost
    - Look for: "Total Tour Cost" or "Total Cost"
    - Example: 1500.00
    - Return as float

16. **currency** - Currency code
    - Example: "MYR", "USD", "SGD"
    - Return as string

17. **emergency_contacts** - Array of emergency contacts
    - Look for: "Emergency contact" or "Customer Support"
    - Extract all contacts with name and phone
    - Return as array of objects

18. **chauffeur_contact** - Chauffeur contact info
    - Look for: "Chauffeur contact"
    - Example: "Will Advice"
    - Return as string

19. **flight** - Flight information
    - Look for: "Flight"
    - Example: "TBA"
    - Return as string

**Return EXACT JSON:**
{
    "invoice_number": null,
    "tour_ref": null,
    "agent_name": null,
    "guest_name": null,
    "guest_count": null,
    "meal_plan": null,
    "file_handler": null,
    "arrival_date": null,
    "departure_date": null,
    "hotel_name": null,
    "city": null,
    "nights": null,
    "room_type": null,
    "meal_type": null,
    "total_amount": null,
    "currency": null,
    "emergency_contacts": [],
    "chauffeur_contact": null,
    "flight": null
}

Return ONLY valid JSON. No other text.
PROMPT;
    }
     protected function buildTCDataPrompt($content, $invoiceNumber, $folderName)
    {
        return <<<PROMPT
You are extracting data from a TOUR CONFIRMATION (TC) document.

This is a TC document - extract ALL fields from it including table data.

FIELDS TO EXTRACT:

1. **invoice_number** - Extract from folder name or document
   - Found in: Folder name like "MY40031 - Saratha"
   - Example: "MY40031"
   - Current folder: {$folderName}

2. **tour_ref** - Tour Reference number
   - Look for: "Tour Ref" or "Tour Reference"
   - Example: "448629CNTL | MY23030"

3. **agent_name** - Agent/Company name
   - Look for: "Agent" or "Agent Name"
   - Example: "FIT"

4. **guest_name** - Guest/Customer name
   - Look for: "Guests Name" or "Guest Name" or "Guest"
   - Example: "MR. Srinandh Subramanian"

5. **guest_count** - Number of guests (adults)
   - Look for: "No. of Guests" or "Adults"
   - Example: "2 Adults" → extract 2

6. **meal_plan** - Meal plan
   - Look for: "Meal Plan" or "Meal Type"
   - Example: "BB", "HB", "FB"

7. **file_handler** - File handler name
   - Look for: "File Handler"
   - Example: "P. Sarathapriya"

8. **arrival_date** - Arrival date in YYYY-MM-DD format
   - Look for: "Arrival Date"
   - Example: "2026-7-14" → "2026-07-14"

9. **departure_date** - Departure date in YYYY-MM-DD format
   - Look for: "Departure Date"
   - Example: "2026-7-16" → "2026-07-16"

10. **hotel_name** - Hotel name
    - Look for in table: "Hotel" column
    - Example: "Own Arrangement" or actual hotel name

11. **city** - City name
    - Look for in table: "City" column
    - Example: "Kuala Lumpur"

12. **nights** - Number of nights
    - Look for in table: "Nights" column
    - Example: 3

13. **room_type** - Room type
    - Look for in table: "Room Type" column
    - Example: "Deluxe", "Twin Room"

14. **total_amount** - Total tour cost (if mentioned)
    - Look for: "Total Tour Cost" or "Total Cost"
    - Example: 1500.00

15. **currency** - Currency code
    - Example: "MYR", "USD", "SGD"

16. **emergency_contacts** - Array of emergency contacts
    - Look for: "Emergency contact" or "Customer Support"
    - Extract: name and phone number
    - Example: [{"name": "Thazli", "phone": "+65 8184 8967"}]

17. **chauffeur_contact** - Chauffeur contact info
    - Look for: "Chauffeur contact"
    - Example: "Will Advice" or phone number

DOCUMENT CONTENT:
{$content}

Return EXACT JSON with these keys. Use null if field not found:
{
    "invoice_number": null,
    "tour_ref": null,
    "agent_name": null,
    "guest_name": null,
    "guest_count": null,
    "meal_plan": null,
    "file_handler": null,
    "arrival_date": null,
    "departure_date": null,
    "hotel_name": null,
    "city": null,
    "nights": null,
    "room_type": null,
    "total_amount": null,
    "currency": null,
    "emergency_contacts": [],
    "chauffeur_contact": null
}
PROMPT;
    }

    /**
     * ✅ Fallback: Extract using regex
     */
/**
 * ✅ Fallback: Extract using regex - MORE SPECIFIC
 */
/**
 * ✅ Fallback: Extract using regex - SIMPLER PATTERNS
 */
public function extractTCWithRegex($content, $invoiceNumber, $folderName)
{
    $data = [
        'tour_ref' => null,
        'agent_name' => null,
        'arrival_date' => null,
        'departure_date' => null
    ];

    // ✅ Clean the content first - remove special characters
    $cleanContent = preg_replace('/[^\x20-\x7E]/', ' ', $content);
    $cleanContent = preg_replace('/\s+/', ' ', $cleanContent);
    
    Log::info("📄 Cleaned content preview: " . substr($cleanContent, 0, 500));

    // ✅ Tour Ref - Look for pattern with "Tour Ref" or "TourRef"
    $patterns = [
        '/Tour\s*Ref\s*[:]?\s*([A-Z0-9\s\|]+)/i',
        '/Tour\s*Ref\s*[:]?\s*([^\n,]+)/i',
        '/([A-Z]{2}\d{5,})/',  // Just the number like MY23030
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $cleanContent, $match)) {
            $tourRef = trim($match[1]);
            // Clean up
            $tourRef = preg_replace('/\s+/', ' ', $tourRef);
            // Remove any trailing special chars
            $tourRef = preg_replace('/[^A-Z0-9\s\|]/', '', $tourRef);
            if (!empty($tourRef) && strlen($tourRef) > 3) {
                $data['tour_ref'] = $tourRef;
                Log::info("✅ Found Tour Ref: {$tourRef}");
                break;
            }
        }
    }

    // ✅ Agent Name - Look for "Agent" label
    // First try to find "Agent:" specifically
    if (preg_match('/Agent\s*[:]?\s*([A-Za-z\s]+)(?=\s|$|,)/', $cleanContent, $match)) {
        $agent = trim($match[1]);
        // Skip common false matches
        if (!preg_match('/name\s+revised|travel|date|glass|gondola|ticket/i', $agent) && strlen($agent) < 20) {
            $data['agent_name'] = $agent;
            Log::info("✅ Found Agent: {$agent}");
        }
    }
    
    // If agent not found, try looking for common agent names
    if (!$data['agent_name']) {
        if (preg_match('/\b(FIT|GLOBAL\s*JOURNEYS|MAKEMYTRIP)\b/i', $cleanContent, $match)) {
            $data['agent_name'] = strtoupper($match[1]);
            Log::info("✅ Found Agent (common name): {$data['agent_name']}");
        }
    }

    // ✅ Arrival Date - Look for date patterns
    $datePatterns = [
        '/Arrival\s*Date\s*[:]?\s*(\d{4})\s*[-\/]\s*(\d{1,2})\s*[-\/]\s*(\d{1,2})/i',
        '/Arrival\s*Date\s*[:]?\s*(\d{1,2})\s*[-\/]\s*(\d{1,2})\s*[-\/]\s*(\d{4})/i',
        '/Arrival\s*Date\s*[:]?\s*(\d{4})\s*-\s*(\d{1,2})\s*-\s*(\d{1,2})/i',
    ];
    
    foreach ($datePatterns as $pattern) {
        if (preg_match($pattern, $cleanContent, $match)) {
            // Try different orders
            if (strlen($match[1]) == 4) {
                $year = $match[1];
                $month = $match[2];
                $day = $match[3];
            } else {
                $day = $match[1];
                $month = $match[2];
                $year = $match[3];
            }
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", intval($year), intval($month), intval($day));
            Log::info("✅ Found Arrival Date: {$data['arrival_date']}");
            break;
        }
    }

    // ✅ If no arrival date found, try to find any date in the document
    if (!$data['arrival_date']) {
        if (preg_match('/(\d{4})\s*-\s*(\d{1,2})\s*-\s*(\d{1,2})/', $cleanContent, $match)) {
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", $match[1], $match[2], $match[3]);
            Log::info("✅ Found Arrival Date (any date): {$data['arrival_date']}");
        }
    }

    // ✅ Departure Date - Look for second date
    if ($data['arrival_date']) {
        // Look for another date after the arrival date
        $datePattern = '/(\d{4})\s*-\s*(\d{1,2})\s*-\s*(\d{1,2})/';
        preg_match_all($datePattern, $cleanContent, $matches, PREG_SET_ORDER);
        
        if (count($matches) > 1) {
            // The second date is departure
            $match = $matches[1];
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $match[1], $match[2], $match[3]);
            Log::info("✅ Found Departure Date: {$data['departure_date']}");
        }
    }

    // ✅ If departure date not found but we have 2 dates, use the second one
    if (!$data['departure_date'] && $data['arrival_date']) {
        // Try to find the second date in the "Jul 14, 2026 - Jul 17, 2026" format
        if (preg_match('/Jul\s+(\d+),\s*(\d{4})\s*-\s*Jul\s+(\d+),\s*(\d{4})/i', $cleanContent, $match)) {
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $match[4], 7, $match[3]);
            Log::info("✅ Found Departure Date (from Jul-Jul range): {$data['departure_date']}");
        }
    }

    Log::info("📊 Final Regex extracted: " . json_encode($data));
    return $data;
}
  protected function getMonthNumber($monthName)
    {
        $months = [
            'jan' => 1, 'january' => 1,
            'feb' => 2, 'february' => 2,
            'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'may' => 5,
            'jun' => 6, 'june' => 6,
            'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8,
            'sep' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10,
            'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12,
        ];
        
        $monthName = strtolower(trim($monthName));
        return $months[$monthName] ?? null;
    }
protected function extractTableDataRegex($content)
    {
        $data = [];
        
        // Pattern for table data in the document
        // Look for: "KualaLumpur" and "Own Arrangement" and "3"
        if (preg_match('/(Kuala\s*Lumpur|KualaLumpur)\s+Own\s+Arrangement\s+(\d+)\s*[-]/i', $content, $match)) {
            $data['city'] = 'Kuala Lumpur';
            $data['hotel'] = 'Own Arrangement';
            $data['nights'] = intval($match[2]);
            $data['room_type'] = '-';
            $data['meal_type'] = '-';
        }
        
        // More generic table extraction
        if (preg_match('/City\s+Hotel\s+Nights\s+Room\s+Type\s+Meal\s+Type/i', $content)) {
            // Try to extract from table structure
            if (preg_match('/([A-Za-z\s]+)\s+([A-Za-z\s]+)\s+(\d+)\s+([A-Za-z\s\-]+)\s+([A-Za-z\s\-]+)/', $content, $match)) {
                if (!isset($data['city'])) {
                    $data['city'] = trim($match[1]);
                    $data['hotel'] = trim($match[2]);
                    $data['nights'] = intval($match[3]);
                    $data['room_type'] = trim($match[4]);
                    $data['meal_type'] = trim($match[5]);
                }
            }
        }
        
        return !empty($data) ? $data : null;
    }

    /**
 * ✅ NEW: Extract COMPLETE TC data including hotels, transport, meals
 */
   public function extractTCDataFull($content, $invoiceNumber, $folderName)
    {
        // Detect if it's Malaysia content
        $isMalaysia = (stripos($content, 'RM') !== false || 
                       stripos($content, 'MYR') !== false ||
                       stripos($content, 'Malaysia') !== false ||
                       stripos($folderName, 'MY') !== false);

        if ($isMalaysia) {
            return $this->extractMalaysiaTCData($content, $invoiceNumber, $folderName);
        }

        // Default: General extraction
        try {
            $prompt = <<<PROMPT
Extract these fields from this tour confirmation document:

1. **tour_ref** - Tour reference number
2. **agent_name** - Agent/agency name
3. **file_handler** - File handler name
4. **sales_person** - Sales Person name ⭐
5. **guest_id** - Guest/Booking ID (MMT ID, Booking ID, Confirmation ID) ⭐
6. **guest_name** - Guest name
7. **guest_count** - Number of guests
8. **arrival_date** - Arrival date in YYYY-MM-DD
9. **departure_date** - Departure date in YYYY-MM-DD
10. **nights** - Total nights
11. **hotel_name** - Hotel name
12. **city** - City name
13. **meal_plan** - Meal plan
14. **total_amount** - Total tour cost as number
15. **currency** - Currency code (USD, MYR, SGD)

Document:
{$content}

Return JSON only.
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert at extracting structured data from tour confirmation documents. Return ONLY valid JSON.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
                $data = json_decode($content, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info("✅ OpenAI Full extraction successful for: {$invoiceNumber}");
                    Log::info("📊 Extracted: " . json_encode($data));
                    return [
                        'success' => true,
                        'data' => $data
                    ];
                }
            }
            return ['success' => false];

        } catch (\Exception $e) {
            Log::error("OpenAI Full TC extraction failed: " . $e->getMessage());
            return ['success' => false];
        }
    }

  public function extractGuestIdFromText($text)
    {
        // Look for Guest ID patterns
        $patterns = [
            '/Guests?\s*ID\s*[:|\s]+([A-Z0-9]{20,})/i',
            '/Guest\s*ID\s*[:|\s]+([A-Z0-9]{20,})/i',
            '/Booking\s*ID\s*[:|\s]+([A-Z0-9]{15,})/i',
            '/MMT\s*ID\s*[:|\s]+([A-Z0-9]{15,})/i',
            '/Confirmation\s*ID\s*[:|\s]+([A-Z0-9]{15,})/i',
            '/[A-Z0-9]{24,}/', // Generic long alphanumeric
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $id = trim($match[1]);
                if (strlen($id) >= 15) {
                    Log::info("✅ Found Guest ID: {$id}");
                    return $id;
                }
            }
        }
        
        return null;
    }
     public function extractSalesPersonFromText($text)
    {
        // Look for Sales Person patterns
        $patterns = [
            '/Sales\s*Person\s*[:|\s]+([^\n,]+)/i',
            '/Sales\s*[:|\s]+([^\n,]+)/i',
            '/File\s*Handler\s*[:|\s]+([^\n,]+)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $name = trim($match[1]);
                // Clean up
                $name = preg_replace('/\s+/', ' ', $name);
                $name = preg_replace('/[^A-Za-z\s\.]/', '', $name);
                if (!empty($name) && strlen($name) < 50) {
                    Log::info("✅ Found Sales Person: {$name}");
                    return $name;
                }
            }
        }
        
        return null;
    }
    /**
     * ✅ General TC Data Extraction (fallback)
     */
    protected function extractGeneralTCData($content, $invoiceNumber, $folderName)
    {
        try {
            $prompt = <<<PROMPT
Extract these fields from this tour confirmation document:

1. tour_ref - Tour reference number
2. agent_name - Agent/agency name
3. file_handler - File handler name
4. guest_name - Guest name
5. guest_count - Number of guests
6. arrival_date - Arrival date in YYYY-MM-DD
7. departure_date - Departure date in YYYY-MM-DD
8. nights - Total nights
9. hotel_name - Hotel name
10. city - City name
11. meal_plan - Meal plan
12. total_amount - Total tour cost as number
13. currency - Currency code (USD, MYR, SGD)

Document:
{$content}

Return JSON only.
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert at extracting structured data from tour confirmation documents. Return ONLY valid JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object']
        ]);

        if ($response->successful()) {
            $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info("✅ OpenAI Full extraction successful for: {$invoiceNumber}");
                Log::info("📊 Extracted: " . json_encode($data));
                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                Log::error("JSON parse error: " . json_last_error_msg());
                return ['success' => false];
            }
        } else {
            Log::error("OpenAI API error: " . $response->body());
            return ['success' => false];
        }

    } catch (\Exception $e) {
        Log::error("OpenAI Full TC extraction failed: " . $e->getMessage());
        return ['success' => false];
    }
}
/**
 * ✅ Extract ONLY Total Tour Cost using OpenAI
 */
  public function extractTotalTourCostOnly($content)
    {
        try {
            $prompt = <<<PROMPT
Extract ONLY the Total Tour Cost from this document.

Look for these patterns (in order of priority):

1. "Total Tour Cost" followed by currency and amount
   - Malaysia: "Total Tour Cost RM 4,830.00" → return 4830.00, currency "MYR"
   - Sri Lanka: "Total Tour Cost \$ 900.00" → return 900.00, currency "USD"
   - Singapore: "Total Tour Cost SGD 1,200.00" → return 1200.00, currency "SGD"

2. If "Total Tour Cost" not found, look for:
   - "Grand Total" 
   - "Total Amount"
   - "Total Cost"

3. Detect currency from the symbol or code:
   - "RM" or "MYR" → MYR
   - "\$" or "USD" → USD
   - "SGD" or "S$" → SGD

Return ONLY JSON:
{
    "total_amount": 4830.00,
    "currency": "MYR"
}

If not found:
{
    "total_amount": null,
    "currency": null
}

Document:
{$content}
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert at extracting Total Tour Cost from documents. Return ONLY valid JSON.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 100,
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
                $data = json_decode($content, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info("🤖 OpenAI extracted Total Cost: " . json_encode($data));
                    return [
                        'success' => true,
                        'data' => $data
                    ];
                }
            }
            
            return ['success' => false];

        } catch (\Exception $e) {
            Log::error("OpenAI Total Cost extraction failed: " . $e->getMessage());
            return ['success' => false];
        }
    }


/**
 * ✅ Extract TC Data for Sri Lanka (LK) specifically
 */
public function extractLKTCData($content, $invoiceNumber, $folderName)
{
    try {
        $prompt = <<<PROMPT
You are extracting data from a SRI LANKA TOUR CONFIRMATION document.

Extract these fields from the document:

1. **tour_ref** - Tour reference number
   - Look for "Tour Ref:" 
   - Example: "447662CNTL", "470643CNTL"

2. **agent_name** - Agency name
   - Look for "Agent:" 
   - Example: "Pick Your Trails", "ZEAL TOURISM"

3. **file_handler** - File handler name
   - Look for "File Handler:"
   - Example: "Miss Shabrina", "Shahil"

4. **guest_name** - Guest name
   - Look for "Guests Name:" 
   - Example: "Bhavya", "Ian Savio DSouza"

5. **guest_count** - Number of guests
   - Look for "No. of Guests:" or "3 Adults | 0 CWB | 0 CNB"
   - Extract total number (e.g., 2, 3)

6. **arrival_date** - Arrival date in YYYY-MM-DD
   - Look for "Arrival Date:"
   - Example: "2026-7-11" → "2026-07-11"

7. **departure_date** - Departure date in YYYY-MM-DD (if available)

8. **nights** - Total nights (if available)

9. **hotel_name** - Hotel name from the table
   - Example: "Radisson Hotel Kandy", "EKHO Surf Bentota"

10. **city** - City name from the table
    - Example: "Kandy", "Nuwara Eliya", "Bentota"

11. **meal_plan** - Meal plan
    - Example: "HB", "BB"

12. **room_type** - Room type
    - Example: "DBL(1)", "Deluxe"

**Document Content:**
{$content}

**Return ONLY this JSON:**
{
    "tour_ref": null,
    "agent_name": null,
    "file_handler": null,
    "guest_name": null,
    "guest_count": null,
    "arrival_date": null,
    "departure_date": null,
    "nights": null,
    "total_amount": null,
    "currency": "USD",
    "hotel_name": null,
    "city": null,
    "meal_plan": null,
    "room_type": null,
    "hotels": []
}

Return ONLY valid JSON. No explanations, no markdown.
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert at extracting structured data from Sri Lanka tour confirmation documents. Return ONLY valid JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object']
        ]);

        if ($response->successful()) {
            $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info("✅ OpenAI LK extraction successful for: {$invoiceNumber}");
                Log::info("📊 LK Extracted: " . json_encode($data));
                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                Log::error("JSON parse error: " . json_last_error_msg());
                return ['success' => false];
            }
        } else {
            Log::error("OpenAI API error: " . $response->body());
            return ['success' => false];
        }

    } catch (\Exception $e) {
        Log::error("OpenAI LK TC extraction failed: " . $e->getMessage());
        return ['success' => false];
    }
}
/**
 * Extract PNL data using OpenAI
 */
public function extractPNLData($content, $invoiceNumber)
{
    try {
        $prompt = <<<PROMPT
You are an expert at extracting data from Sri Lanka PNL (Profit & Loss) documents.

Extract the following data from this PNL document in JSON format:

1. **hotels** - Array of hotels with:
   - name: Hotel name
   - amount: Total hotel cost (the last number in the row)
   - nights: Number of nights (if available)

2. **transport_items** - Array of transport items with:
   - service_name: Name (Travel, Bata, Paging, Driver Accomodation, Water Bottles, etc.)
   - amount: Total amount
   - distance: Distance/Days (if available)
   - rate: Rate (if available)

3. **total_transport** - Total transport cost (sum of all transport items)

4. **total_hotels** - Total hotel cost (sum of all hotels)

5. **total_tour_cost** - The Total Tour Cost

6. **profit_loss** - The Profit/Loss value

**IMPORTANT RULES:**
- Hotel rows have two totals at the end (Room Night Total and Hotel Total). Use the LAST number as the hotel amount.
- Example: "Radisson Hotel Kandy (OZO Kandy) 0 0 90 / 90 / 1 0 0 0 0 0 0 2 90.00 180.00" → Hotel amount is 180.00
- EKHO Surf Bentota has amount 130.00

- Transport items: Each row has 4 columns: Expense, Distance/Days, Rate, Total
- Example: "Travel 1040 0.2476 257.52" → service_name: "Travel", amount: 257.52

- Water Bottles: "Water Bottles Adt - 1.4, cwb - 0, cnb - 0 2.80USD" → service_name: "Water Bottles", amount: 2.80

**Document content:**
{$content}

Return ONLY valid JSON. No explanations.
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert at extracting structured data from Sri Lanka PNL documents. Return ONLY valid JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object']
        ]);

        if ($response->successful()) {
            $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info("✅ OpenAI PNL extraction successful for: {$invoiceNumber}");
                Log::info("📊 Extracted: " . json_encode($data));
                return [
                    'success' => true,
                    'data' => $data
                ];
            }
        }
        
        return ['success' => false];

    } catch (\Exception $e) {
        Log::error("OpenAI PNL extraction failed: " . $e->getMessage());
        return ['success' => false];
    }
}
 public function extractMalaysiaTCData($content, $invoiceNumber, $folderName)
    {
        try {
            $prompt = <<<PROMPT
You are extracting data from a MALAYSIA TOUR CONFIRMATION document.

Extract these fields from the document:

1. **tour_ref** - Tour reference number
   - Look for "Tour Ref:" or "Tour Reference:"
   - Example formats:
     - "Tour Ref 472369CNTL" → extract "472369CNTL"
     - "Tour Ref: 472369CNTL" → extract "472369CNTL"
     - "Tour Ref 448629CNTL | MY23030" → extract "448629CNTL"
   - IMPORTANT: Extract ONLY the CNTL number (the part that ends with CNTL)

2. **agent_name** - Agency name
   - Look for "Agent:" 
   - Example: "FIT", "Global Journeys", "MakeMyTrip"
   - Skip if it says "Agent name revised"

3. **file_handler** - File handler name
   - Look for "File Handler:"
   - Example: "P. Sarathapriya", "Madhu", "Saratha"

4. **sales_person** - Sales Person name ⭐ NEW
   - Look for "Sales Person:" or "Sales:"
   - Examples: "Mr. Shahinsha", "Saratha", "Madhu", "Esther"
   - This is the person who made the booking
   - If "Sales Person:" not found, check "File Handler:" as fallback

5. **guest_id** - Guest/Booking ID ⭐ NEW
   - Look for "Guests ID:", "Guest ID:", "Booking ID:", "MMT ID:", "Confirmation ID:"
   - Examples: "6A43F887B46606000172B9BB", "IN1B1782989769823"
   - This is usually a long alphanumeric string (20-30 characters)
   - If not found, look for "MMT" or "Booking" reference

6. **guest_name** - Guest name
   - Look for "Guests Name:" or "Guest Name:"
   - Example: "MR. Srinandh Subramanian", "MRS. USHA RANI SANKARAMOORTHY"

7. **guest_count** - Number of guests
   - Look for "No. of Guests:" or "X Adults"
   - Extract total number (e.g., 2, 3, 4)

8. **arrival_date** - Arrival date in YYYY-MM-DD
   - Look for "Arrival Date:" or date range like "Jul 13, 2026 - Jul 17, 2026"
   - Example: "2026-7-13" → "2026-07-13"

9. **departure_date** - Departure date in YYYY-MM-DD
   - Look for "Departure Date:" or the end of date range

10. **nights** - Total nights (calculate from dates if not directly given)

11. **hotel_name** - Hotel name
    - Look for hotel name in the itinerary or table
    - Example: "Upper View Regalia Hotel", "Own Arrangement"

12. **city** - City name
    - Look for "Kuala Lumpur", "Penang", "Langkawi", etc.

13. **meal_plan** - Meal plan
    - Look for "Meal Plan:" or "BB", "HB", "FB"
    - Example: "BB" (Bed & Breakfast)

14. **total_amount** - Total tour cost in RM (Malaysian Ringgit)
    - Look for "Total Tour Cost RM X,XXX.XX"
    - Example: "RM 4,830.00" → 4830.00

15. **currency** - Currency code (should be "MYR" for Malaysia)

16. **guest_id** - Guest/Booking ID ⭐
    - Look for "Guests ID:", "Guest ID:", "Booking ID:", "MMT ID:"
    - This is the unique identifier for the booking
    - Examples: "6A43F887B46606000172B9BB", "IN1B1782989769823"

**Document Content:**
{$content}

**Return ONLY this JSON:**
{
    "tour_ref": null,
    "agent_name": null,
    "file_handler": null,
    "sales_person": null,
    "guest_id": null,
    "guest_name": null,
    "guest_count": null,
    "arrival_date": null,
    "departure_date": null,
    "nights": null,
    "hotel_name": null,
    "city": null,
    "meal_plan": null,
    "total_amount": null,
    "currency": "MYR"
}

Return ONLY valid JSON. No explanations, no markdown.
PROMPT;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert at extracting structured data from Malaysia tour confirmation documents. Return ONLY valid JSON.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object']
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
                $data = json_decode($content, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info("✅ OpenAI Malaysia extraction successful for: {$invoiceNumber}");
                    Log::info("📊 Malaysia Extracted: " . json_encode($data));
                    return [
                        'success' => true,
                        'data' => $data
                    ];
                } else {
                    Log::error("JSON parse error: " . json_last_error_msg());
                    return ['success' => false];
                }
            } else {
                Log::error("OpenAI API error: " . $response->body());
                return ['success' => false];
            }

        } catch (\Exception $e) {
            Log::error("OpenAI Malaysia TC extraction failed: " . $e->getMessage());
            return ['success' => false];
        }
    }

}