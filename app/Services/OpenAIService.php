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
            // Prepare the prompt
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
                'temperature' => 0.1, // Low temperature for consistent results
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

    /**
     * Build multi-field extraction prompt
     */
   /**
 * Build multi-field extraction prompt
 */
protected function buildMultiFieldPrompt($text)
{
    return <<<PROMPT
Extract the following fields from this tour confirmation email. Return ONLY valid JSON.

Fields to extract:
1. guest_id - The Guest ID (e.g., "6A43F887B46606000172B9BB" or "IN1B1782989769823"). If not found, use null.
2. reference_no - The Reference/IS Number (e.g., "VN40232" or "VN 40232" or "NL1234567890"). If not found, use null.

Rules:
- The Guest ID appears after "Guests ID:" or "Guest ID:"
- The Reference/IS Number appears after:
  - "IS Number:" 
  - "Reference No:"
  - "Booking ID:"
  - "Tour Ref:" (but extract only the number, not "CNTL" suffix)
- For "IS Number:" values like "VN 40232", extract "VN40232" or "40232"
- Do not confuse Agent Name ("Pick your trail") with Reference No
- Return valid JSON only

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
}