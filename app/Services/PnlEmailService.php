<?php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\IncomingEmail;

class PnlEmailService
{
    protected $accessToken;
    
    private $exchangeRates = [
        'LK' => 330,
        'VN' => 25500,
        'SG' => 1,
        'MY' => 1,
    ];
    
    public function __construct()
    {
        $this->authenticate();
    }
    
    protected function authenticate()
    {
        try {
            $response = Http::asForm()->post(
                'https://login.microsoftonline.com/' . env('GRAPH_TENANT_ID') . '/oauth2/v2.0/token',
                [
                    'client_id' => env('GRAPH_CLIENT_ID'),
                    'client_secret' => env('GRAPH_CLIENT_SECRET'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]
            );
            
            if ($response->ok()) {
                $this->accessToken = $response->json()['access_token'];
                Log::info('PnL Graph authenticated successfully');
                return true;
            }
        } catch (\Exception $e) {
            Log::error('PnL Graph auth error: ' . $e->getMessage());
        }
        return false;
    }
    public function getAccessToken()
{
    return $this->accessToken;
}
public function fetchPnLEmails()
{
    try {
        set_time_limit(600);
        \Illuminate\Support\Facades\DB::reconnect();
        
        // ✅ Get existing subject+body_hash combinations
        $existingEmails = PnlRecord::select('subject', 'body_hash')->get();
        $existingSet = [];
        foreach ($existingEmails as $email) {
            $key = md5(trim($email->subject) . '|' . $email->body_hash);
            $existingSet[$key] = true;
        }
        Log::info("📊 Existing emails in DB: " . count($existingEmails));
        
        // Fetch emails from Graph API
        $allMessages = [];
        $nextLink = null;
        $pageCount = 0;
        $maxPages = 20;
        
        $baseUrl = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages';
        
        do {
          $url = $nextLink ?? $baseUrl . '?' . http_build_query([
    '$top' => 50,
    '$orderby' => 'receivedDateTime asc',  // ✅ OLDEST FIRST
    '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
]);
            
            $response = Http::withToken($this->accessToken)
                ->timeout(60)
                ->get($url);
            
            if (!$response->ok()) {
                Log::error('Failed to fetch emails: ' . $response->body());
                break;
            }
            
            $data = $response->json();
            $messages = $data['value'] ?? [];
            $allMessages = array_merge($allMessages, $messages);
            
            $nextLink = $data['@odata.nextLink'] ?? null;
            $pageCount++;
            
            Log::info("📥 Page {$pageCount}: " . count($messages) . " emails");
            
            if ($nextLink) usleep(100000);
            if ($pageCount >= $maxPages) break;
            
        } while ($nextLink);
        
        Log::info("📬 Total emails fetched: " . count($allMessages));
        
        // ✅ Filter ONLY new emails using subject+body_hash
        $newMessages = [];
        foreach ($allMessages as $message) {
            $plainText = strip_tags($message['body']['content'] ?? $message['bodyPreview'] ?? '');
            $normalizedBody = preg_replace('/\s+/', ' ', trim($plainText));
            $bodyHash = md5($normalizedBody);
            $subject = trim($message['subject'] ?? 'No Subject');
            
            $key = md5($subject . '|' . $bodyHash);
            
            if (!isset($existingSet[$key])) {
                $newMessages[] = $message;
                Log::info("🆕 New email found: {$subject} - Hash: {$bodyHash}");
            } else {
                Log::info("⏭️ SKIPPING duplicate: {$subject} (already exists)");
            }
        }
        
        Log::info("🆕 New emails to process: " . count($newMessages));
        
        if (empty($newMessages)) {
            Log::info("📭 No new emails to process");
            return 0;
        }
        
        // Process new emails
        $sno = PnlRecord::max('sno') ?? 0;
        $newCount = 0;
        
        foreach (array_chunk($newMessages, 5) as $chunk) {
            \Illuminate\Support\Facades\DB::reconnect();
            
         foreach ($chunk as $message) {
    $sno++;

    $fullMessage = $this->fetchFullMessage($message['id']);

    if (!$fullMessage) {
        continue;
    }

    $plainText = strip_tags($fullMessage['body']['content'] ?? $fullMessage['bodyPreview'] ?? '');
    $normalizedBody = preg_replace('/\s+/', ' ', trim($plainText));
    $bodyHash = md5($normalizedBody);
    $subject = trim($fullMessage['subject'] ?? 'No Subject');

    $exists = PnlRecord::where('subject', $subject)
        ->where('body_hash', $bodyHash)
        ->exists();

    if ($exists) {
        Log::info("⏭️ Duplicate found before save: {$subject}");
        continue;   // ✅ This is valid because we're inside foreach
    }

    $saved = $this->savePnLEmail($fullMessage, $sno);

    if ($saved) {
        $newCount++;
    }
}
        }
        
        Log::info("📊 SUMMARY: {$newCount} new emails saved");
        return $newCount;
        
    } catch (\Exception $e) {
        Log::error('Error fetching PnL emails: ' . $e->getMessage());
        return 0;
    }
}
    /**
 * Check if the same body hash exists for this IS number
 */
private function isDuplicateBodyForIsNumber($bodyHash, $isNumber)
{
    if (empty($isNumber)) {
        return false;
    }
    
    return PnlRecord::where(function($query) use ($isNumber) {
            $query->where('is_number', 'LIKE', $isNumber . '%')
                  ->orWhere('original_is_number', $isNumber);
        })
        ->where('body_hash', $bodyHash)
        ->exists();
}
protected function savePnLEmail($message, $sno)
{
    try {
        $subject = $message['subject'] ?? 'No Subject';
        $htmlBody = $message['body']['content'] ?? $message['bodyPreview'] ?? '';
        
        $plainText = strip_tags($htmlBody);
        $plainText = preg_replace('/\r\n/', "\n", $plainText);
        
        // ✅ GENERATE BODY HASH
        $normalizedBody = preg_replace('/\s+/', ' ', trim($plainText));
        $bodyHash = md5($normalizedBody);
          $existing = PnlRecord::where('subject', $subject)
            ->where('body_hash', $bodyHash)
            ->first();
             if ($existing) {
            Log::info("⏭️ SKIPPING DUPLICATE: Subject: '{$subject}' already exists with same body");
            Log::info("   Existing Record ID: {$existing->id}, IS Number: {$existing->is_number}");
            return false;
        }
        
        Log::info("📝 Body Hash: {$bodyHash}");
        
        Log::info("Processing email: " . $subject);
        
        $fromEmail = $message['from']['emailAddress']['address'] ?? '';
        $fromName = $message['from']['emailAddress']['name'] ?? '';
        $receivedAt = Carbon::parse($message['receivedDateTime']);
        $readStatus = isset($message['isRead']) ? ($message['isRead'] ? 'read' : 'unread') : 'unread';
        
        // ========== EXTRACT HEADER DATA ==========
        $tourNumber = null;
        if (preg_match('/Tour No:\s*#?(\d+)/i', $plainText, $match)) {
            $tourNumber = $match[1];
        }
        
        $isNumber = null;
        if (preg_match('/Is Number:\s*([A-Z]{2})\s*(\d+)/i', $plainText, $match)) {
            $isNumber = $match[1] . $match[2];
        }
        
        $baseIsNumber = $isNumber;
        
        // ✅ CHECK: Does this body hash already exist for this IS number?
        $existingWithSameBody = PnlRecord::where(function($query) use ($isNumber) {
                $query->where('is_number', 'LIKE', $isNumber . '%')
                      ->orWhere('original_is_number', $isNumber);
            })
            ->where('body_hash', $bodyHash)
            ->first();
        
        if ($existingWithSameBody) {
            Log::info("⏭️ SKIPPING: Exact same body content already exists for IS Number: {$isNumber}");
            Log::info("   Existing Record ID: {$existingWithSameBody->id}");
            Log::info("   Body Hash: {$bodyHash}");
            return false;
        }
        
        // ✅ Check for existing records (for revision detection)
        $isRevision = false;
        $finalIsNumber = $isNumber;
        $revisionNumber = 0;
        $versionCount = 0;
        
        if ($isNumber) {
            $existingRecords = PnlRecord::where('is_number', 'LIKE', $isNumber . '%')
                ->orWhere('original_is_number', $isNumber)
                ->get();
            
            $isRevision = $existingRecords->isNotEmpty();
            
            if ($isRevision) {
                // ✅ Body is DIFFERENT - Create new revision
                Log::info("🔄 Body changed for IS Number: {$isNumber} - Creating new revision");
                
                $newRevisionNumber = $this->getNextRevisionNumber($baseIsNumber);
                $newVersionCount = $this->getNextVersionCount($baseIsNumber);
                $this->updateAllRevisionsWithNewVersion($baseIsNumber, $newVersionCount);
                $finalIsNumber = $baseIsNumber . '_R' . $newRevisionNumber . '/R' . $newVersionCount;
                $revisionNumber = $newRevisionNumber;
                $versionCount = $newVersionCount;
                
                Log::info("📝 New revision: {$finalIsNumber}");
            } else {
                $finalIsNumber = $isNumber;
                Log::info("📝 New record: {$finalIsNumber}");
            }
        }
        
        // ========== SET COUNTRY CODE ==========
        $countryCode = 'VN';
        if ($baseIsNumber && strpos($baseIsNumber, 'IS') === 0) {
            $countryCode = 'LK';
        } elseif ($baseIsNumber && strpos($baseIsNumber, 'VN') === 0) {
            $countryCode = 'VN';
        } elseif ($baseIsNumber && strpos($baseIsNumber, 'SG') === 0) {
            $countryCode = 'SG';
        } elseif ($baseIsNumber && strpos($baseIsNumber, 'MY') === 0) {
            $countryCode = 'MY';
        }
        
        // ✅ Check duplicate AFTER finalIsNumber is set
        if ($finalIsNumber && $this->isDuplicateEmail($finalIsNumber)) {
            Log::info("⏭️ Skipping duplicate email: {$finalIsNumber}");
            return false;
        }
        
        $agentName = 'Unknown';
        if (preg_match('/Agent:\s*([^\n]+?)(?:\s+No\.|\s+Currency|$)/i', $plainText, $match)) {
            $agentName = trim($match[1]);
        }
 // ========== EXTRACT PAX ==========
$totalPax = 0;
$totalNights = 0;
$adultCount = 0;
$childCount = 0;

Log::info("🔍 Searching for PAX in text...");

// Try to get Adult count
$adultPatterns = [
    '/No\.\s*Adult\s*:\s*(\d+)/i',
    '/No\.\s*Adult:\s*(\d+)/i',
    '/No\.\s*Adult\s*(\d+)/i',
    '/Adult\s*:\s*(\d+)/i',
    '/Adult:\s*(\d+)/i',
];

foreach ($adultPatterns as $pattern) {
    if (preg_match($pattern, $plainText, $match)) {
        $adultCount = intval($match[1]);
        Log::info("✅ Extracted Adult Count: " . $adultCount);
        break;
    }
}

// Try to get Child count
$childPatterns = [
    '/No\.\s*Child\s*:\s*(\d+)/i',
    '/No\.\s*Child:\s*(\d+)/i',
    '/No\.\s*Child\s*(\d+)/i',
    '/Child\s*:\s*(\d+)/i',
    '/Child:\s*(\d+)/i',
];

foreach ($childPatterns as $pattern) {
    if (preg_match($pattern, $plainText, $match)) {
        $childCount = intval($match[1]);
        Log::info("✅ Extracted Child Count: " . $childCount);
        break;
    }
}

// ✅ Calculate TOTAL PAX = Adult + Child
$totalPax = $adultCount + $childCount;
Log::info("📊 FINAL PAX: Adult: {$adultCount} + Child: {$childCount} = Total: {$totalPax}");

// If total is still 0, try fallback patterns
if ($totalPax == 0) {
    $fallbackPatterns = [
        '/No\.\s*Pax\s*:\s*(\d+)/i',
        '/No\.\s*Pax:\s*(\d+)/i',
        '/No\.\s*Pax\s*(\d+)/i',
        '/Pax\s*:\s*(\d+)/i',
        '/Pax:\s*(\d+)/i',
    ];
    
    foreach ($fallbackPatterns as $pattern) {
        if (preg_match($pattern, $plainText, $match)) {
            $totalPax = intval($match[1]);
            Log::info("✅ Extracted PAX (fallback): " . $totalPax);
            break;
        }
    }
}

// If still 0, try searching in HTML directly
if ($totalPax == 0) {
    if (preg_match('/No\.\s*Adult\s*:\s*(\d+)/i', $htmlBody, $match)) {
        $adultCount = intval($match[1]);
    }
    if (preg_match('/No\.\s*Child\s*:\s*(\d+)/i', $htmlBody, $match)) {
        $childCount = intval($match[1]);
    }
    $totalPax = $adultCount + $childCount;
    Log::info("✅ Extracted PAX from HTML: Adult: {$adultCount} + Child: {$childCount} = Total: {$totalPax}");
}

// Extract nights
$nightPatterns = [
    '/No\.\s*Night\s*:\s*(\d+)/i',
    '/No\.\s*Night:\s*(\d+)/i',
    '/No\.\s*Night\s*(\d+)/i',
    '/Night\s*:\s*(\d+)/i',
    '/Night:\s*(\d+)/i',
];

foreach ($nightPatterns as $pattern) {
    if (preg_match($pattern, $plainText, $match)) {
        $totalNights = intval($match[1]);
        Log::info("✅ Extracted NIGHTS: " . $totalNights . " (pattern: " . $pattern . ")");
        break;
    }
}

Log::info("📊 FINAL PAX: " . $totalPax . ", Adult: {$adultCount}, Child: {$childCount}, NIGHTS: " . $totalNights);

// Force add a debug line to check
if ($totalPax == 0) {
    Log::warning("⚠️ PAX extraction failed! Please check the email format.");
}
        // Extract Total Tour Cost
        $totalTourCost = 0;
        if (preg_match('/Total Tour Cost\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/i', $plainText, $match)) {
            $totalTourCost = floatval(str_replace(',', '', $match[1]));
        }
        
        // Extract Profit/Loss
        $profitLoss = $this->extractProfitLossFromEmail($htmlBody);
        if ($profitLoss === null) {
            $profitLoss = $this->extractProfitLossFromEmail($plainText);
        }
        
        // ========== EXTRACT CATEGORIES ==========
        $categoriesFound = [];
        $pnlItemsToSave = [];
        
        // 1. Hotels/Cruises
        if (preg_match('/Hotels\/Cruises/i', $plainText)) {
            $hotels = $this->extractHotelsFromEmail($htmlBody);
            if (!empty($hotels)) {
                $categoriesFound[] = 'Hotels/Cruises';
                foreach ($hotels as $hotel) {
                    $pnlItemsToSave[] = [
                        'type' => 'HOTEL',
                        'service_name' => $hotel['name'],
                        'hotel_name' => $hotel['name'],
                        'amount' => $hotel['amount'],
                        'details' => ['nights' => $hotel['nights'], 'remarks' => $hotel['nights'] . ' nights']
                    ];
                }
            }
        }
        
        // 2. Transport - Extract INDIVIDUAL ITEMS
       // 2. Transport - Extract INDIVIDUAL ITEMS
$transportItems = $this->extractTransportItemsForCountry($htmlBody, $plainText, $countryCode);

// In savePnLEmail method, find this section:
if (!empty($transportItems)) {
    $categoriesFound[] = 'Transport';
    foreach ($transportItems as $transport) {
        $pnlItemsToSave[] = [
            'type' => 'TRANSPORT',
            'service_name' => $transport['service_name'],
            'hotel_name' => null,
            'amount' => $transport['amount'],
            'details' => $transport['details']  // ✅ This already has 'remarks' from extractTransportItemsFromTableText
        ];
        Log::info("✅ Added transport item: {$transport['service_name']} - \${$transport['amount']}");
    }
}      
        // Calculate transport total
        $transportTotal = 0;
        foreach ($transportItems as $item) {
            $transportTotal += $item['amount'];
        }
        
$otherRateItems = $this->extractOtherRateItems($htmlBody, $plainText);

if (!empty($otherRateItems)) {
    $categoriesFound[] = 'Other Rates';
    foreach ($otherRateItems as $other) {
        $pnlItemsToSave[] = [
            'type' => 'OTHER RATES',
            'service_name' => $other['service_name'],
            'hotel_name' => null,
            'amount' => $other['amount'],
            'details' => $other['details']
        ];
        Log::info("✅ Added other rate item: {$other['service_name']} - \${$other['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found and total > 0
    $otherRatesTotal = 0;
    if ($countryCode == 'VN' || $countryCode == 'SG' || $countryCode == 'MY') {
        $otherRatesTotal = $this->extractOtherRatesTotalForSouthEastAsia($htmlBody);
        if ($otherRatesTotal == 0) {
            $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
        }
    } else {
        $otherRatesTotal = $this->extractOtherRatesTotalFromEmail($plainText);
    }
    
    // Only add fallback if there is a positive total AND no individual items were found
    if ($otherRatesTotal > 0) {
        $categoriesFound[] = 'Other Rates';
        $pnlItemsToSave[] = [
            'type' => 'OTHER RATES',
            'service_name' => 'Other Rates (Entrance Tickets, etc.)',
            'hotel_name' => null,
            'amount' => $otherRatesTotal,
            'details' => ['remarks' => 'Other attraction & entrance fees']
        ];
        Log::info("⚠️ Using Other Rates total as fallback: \${$otherRatesTotal}");
    }
}
        // 4. Attraction
      // 4. Attraction - Extract INDIVIDUAL ITEMS
$attractionItems = $this->extractAttractionItems($htmlBody, $plainText);

// ✅ Don't create fallback total - only insert individual attractions
if (!empty($attractionItems)) {
    $categoriesFound[] = 'Attraction';
    foreach ($attractionItems as $attraction) {
        $pnlItemsToSave[] = [
            'type' => 'ATTRACTION',
            'service_name' => $attraction['service_name'],
            'hotel_name' => null,
            'amount' => $attraction['amount'],
            'details' => $attraction['details']
        ];
        Log::info("✅ Added attraction item: {$attraction['service_name']} - \${$attraction['amount']}");
    }
} else {
    Log::warning("⚠️ No attraction items found to save");
}
        
$tourTransferItems = $this->extractTourTransferItems($htmlBody, $plainText, $totalPax, $adultCount, $childCount);

if (!empty($tourTransferItems)) {
    $categoriesFound[] = 'Tour Transfers';
    foreach ($tourTransferItems as $transfer) {
        $pnlItemsToSave[] = [
            'type' => 'TOUR TRANSFER',
            'service_name' => $transfer['service_name'],
            'hotel_name' => null,
            'amount' => $transfer['amount'],
            'details' => $transfer['details']
        ];
        Log::info("✅ Added tour transfer item: {$transfer['service_name']} - \${$transfer['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found
    $tourTransfersTotal = $this->extractTourTransfersTotalFromEmail($plainText);
    if ($tourTransfersTotal > 0) {
        $categoriesFound[] = 'Tour Transfers';
        $pnlItemsToSave[] = [
            'type' => 'TOUR TRANSFER',
            'service_name' => 'Tour Transfer Expenses',
            'hotel_name' => null,
            'amount' => $tourTransfersTotal,
            'details' => ['remarks' => 'Total tour transfer expenses']
        ];
    }
}
        
      // 6. Meals - Extract INDIVIDUAL ITEMS
$mealItems = $this->extractMealItems($plainText);

if (!empty($mealItems)) {
    $categoriesFound[] = 'Meals';
    foreach ($mealItems as $meal) {
        $pnlItemsToSave[] = [
            'type' => 'MEALS',
            'service_name' => $meal['service_name'],
            'hotel_name' => null,
            'amount' => $meal['amount'],
            'details' => $meal['details']
        ];
        Log::info("✅ Added meal item: {$meal['service_name']} - \${$meal['amount']}");
    }
} else {
    // Fallback: Use total if individual items not found
    $mealsTotal = $this->extractMealsTotalFromEmail($plainText);
    if ($mealsTotal > 0) {
        $categoriesFound[] = 'Meals';
        $pnlItemsToSave[] = [
            'type' => 'MEALS',
            'service_name' => 'Meals Expenses',
            'hotel_name' => null,
            'amount' => $mealsTotal,
            'details' => ['remarks' => 'Total meals expenses']
        ];
    }
}
         $pnlItemsToSave = $this->sortPnLItems($pnlItemsToSave);
        
        $categoriesString = implode(', ', $categoriesFound);
        $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
        $tourRef = $tourNumber ? $tourNumber . 'CNTL' : null;
        
                $record = PnlRecord::create([
    'sno' => $sno,
    'message_id' => $message['id'],
    'from_email' => $fromEmail,
    'from_address' => $fromName,
    'from_name' => $fromName,
    'subject' => $subject,
    'body' => $plainText,
    'body_html' => $htmlBody,
      'body_hash' => $bodyHash, 
    'received_at' => $receivedAt,
    'vendor_name' => $fromName ?: 'Apple Holidays',
    'invoice_number' => $finalIsNumber,
    'is_number' => $finalIsNumber,
    'amount' => $totalTourCost,
    'profit_loss' => $profitLoss,
    'currency' => 'USD',
    'country_code' => $countryCode,
    'exchange_rate_used' => $exchangeRate,
    'category' => $categoriesString,
    'status' => 'pending',
    'read_status' => $readStatus,
    'has_attachments' => $message['hasAttachments'] ?? false,
    'agent_name' => $agentName,
    'tour_ref' => $tourRef,
    'total_pax' => $totalPax,
    'adult_count' => $adultCount,   // ✅ Add this
    'child_count' => $childCount,   // ✅ Add this
    'total_nights' => $totalNights,
    'revision_number' => $revisionNumber,
    'version_count' => $versionCount,
    'original_is_number' => $baseIsNumber,
    'is_revised' => $isRevision,
]);

        
        // ========== SAVE INVOICE ITEM ==========
        if ($totalTourCost > 0) {
            PnlItem::create([
                'pnl_record_id' => $record->id,
                'control_number' => $tourRef,
                'invoice_number' => $isNumber,
                'type' => 'INVOICE',
                'credit_type' => 'Credit',
                'agent_name' => $agentName,
                'hotel_name' => null,
                'service_name' => 'Total Tour Package',
                'country_code' => $countryCode,
                'currency' => 'USD',
                'amount_original' => $totalTourCost,
                'exchange_rate' => 1,
                'amount_converted' => $totalTourCost,
                'item_details' => json_encode(['remarks' => "Pax: {$totalPax}, Nights: {$totalNights}"]),
            ]);
        }
        
        // ========== SAVE PNL ITEMS ==========
        foreach ($pnlItemsToSave as $item) {
            PnlItem::create([
                'pnl_record_id' => $record->id,
                'control_number' => $tourRef,
                'invoice_number' => $isNumber,
                'type' => $item['type'],
                'credit_type' => 'Credit',
                'agent_name' => $agentName,
                'hotel_name' => $item['hotel_name'],
                'service_name' => $item['service_name'],
                'country_code' => $countryCode,
                'currency' => 'USD',
                'amount_original' => $item['amount'],
                'exchange_rate' => 1,
                'amount_converted' => $item['amount'],
                'item_details' => json_encode($item['details']),
            ]);
        }
        // In PnlEmailService.php, after saving all PNL items

// ✅ Auto-match hotel dates
$this->autoMatchHotelDates($record);
        // ✅✅✅ AUTO-UPDATE: Get client_name, start_date, end_date from invoices
        $this->updatePnLItemsWithInvoiceData($record);
        $this->updateNonHotelItemDates($record); 
        Log::info("✅ Saved PnL record: {$isNumber}");
        return true;
        
    } catch (\Exception $e) {
        Log::error('Save PnL email failed: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
        return false;
    }
}
    
/**
 * Sort PnL items in the required order
 * Order: INVOICE, HOTEL, ATTRACTION, TOUR TRANSFER, MEALS, OTHER RATES, TRANSPORT
 */
private function sortPnLItems($items)
{
    $order = [
        'INVOICE' => 1,
        'HOTEL' => 2,
        'ATTRACTION' => 3,
        'TOUR TRANSFER' => 4,
         'TRANSPORT' => 5,
        'MEALS' => 6,
        'OTHER RATES' => 7,
       
    ];
    
    usort($items, function ($a, $b) use ($order) {
        $aOrder = $order[$a['type']] ?? 999;
        $bOrder = $order[$b['type']] ?? 999;
        
        if ($aOrder == $bOrder) {
            // If same type, sort by service name
            return strcmp($a['service_name'] ?? '', $b['service_name'] ?? '');
        }
        
        return $aOrder - $bOrder;
    });
    
    return $items;
}
private function extractHotelsFromEmail($html)
{
    $hotels = [];

    try {

        if (empty($html)) {
            return [];
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        $tables = $dom->getElementsByTagName('table');

        Log::info("Total tables found: " . $tables->length);

        foreach ($tables as $tableIndex => $table) {

            $rows = $table->getElementsByTagName('tr');

            if ($rows->length < 2) {
                continue;
            }

            $headers = [];

            $firstRow = $rows->item(0);

            foreach ($firstRow->childNodes as $cell) {

                if (
                    $cell->nodeType === XML_ELEMENT_NODE &&
                    in_array(strtolower($cell->nodeName), ['th', 'td'])
                ) {
                    $headers[] = trim($cell->textContent);
                }
            }

            $headerText = strtoupper(implode(' ', $headers));

            Log::info("Table {$tableIndex} Headers: " . $headerText);

            if (
                strpos($headerText, 'NAME') === false ||
                strpos($headerText, 'NIGHTS') === false ||
                strpos($headerText, 'TOTAL') === false
            ) {
                continue;
            }

            Log::info("Hotels table detected");

            for ($i = 1; $i < $rows->length; $i++) {

                $row = $rows->item($i);

                $cells = [];

                foreach ($row->childNodes as $cell) {

                    if (
                        $cell->nodeType === XML_ELEMENT_NODE &&
                        in_array(strtolower($cell->nodeName), ['td', 'th'])
                    ) {
                        $cells[] = trim($cell->textContent);
                    }
                }

                if (count($cells) < 3) {
                    continue;
                }

                $hotelName = trim($cells[0]);

                if (
                    empty($hotelName) ||
                    strtoupper($hotelName) === 'TOTAL' ||
                    is_numeric($hotelName)
                ) {
                    continue;
                }

                $nights = 0;
                $amount = 0;

                foreach ($cells as $index => $value) {

                    if (
                        strtoupper($headers[$index] ?? '') === 'NIGHTS'
                    ) {
                        $nights = (int) preg_replace('/[^0-9]/', '', $value);
                    }

                    if (
                        strtoupper($headers[$index] ?? '') === 'TOTAL'
                    ) {
                        $amount = (float) str_replace(
                            ',',
                            '',
                            preg_replace('/[^0-9\.]/', '', $value)
                        );
                    }
                }

                if ($amount <= 0) {
                    continue;
                }

                $hotels[] = [
                    'name' => $hotelName,
                    'amount' => $amount,
                    'nights' => $nights,
                ];

                Log::info(
                    "Hotel Extracted => {$hotelName} | Nights: {$nights} | Amount: {$amount}"
                );
            }

            if (!empty($hotels)) {
                break;
            }
        }

    } catch (\Exception $e) {

        Log::error(
            'Hotel extraction error: ' . $e->getMessage()
        );
    }

    Log::info("Total hotels extracted: " . count($hotels));

    return $hotels;
}
  
private function extractTransportTotalFromEmail($text)
{
    // First check if Transport section even exists
    if (!preg_match('/Transport/i', $text)) return 0;
    
    // Look for Total Transport pattern
    if (preg_match('/Total Transport\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found: " . $total);
        return $total > 0 ? $total : 0; // ✅ Return 0 if total is 0 or negative
    }
    
    // Alternative pattern - look for Total row with just number
    if (preg_match('/Total\s*:?\s*([\d,]+\.?\d*)\s*USD?/i', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    // Look for Total row in pipe format: | Total | 227.60 USD |
    if (preg_match('/\|\s*Total\s*\|\s*[\d.]*\s*\|\s*[\d.]*\s*\|\s*([\d,]+\.?\d*)\s*USD?/i', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total found (pipe): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    
    /**
     * Extract Other Rates total
     */
/**
 * Extract Other Rates total - ONLY return > 0
 */
private function extractOtherRatesTotalFromEmail($text)
{
    if (!preg_match('/Other Rates/i', $text)) return 0;
    
    if (preg_match('/Other Rates.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Other Rates Total: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    
    /**
     * Extract Attraction total - only if non-zero
     */
/**
 * Extract Attraction total - ONLY return > 0
 */
private function extractAttractionTotalFromEmail($text)
{
    if (!preg_match('/Attraction/i', $text)) return 0;
    
    if (preg_match('/Attraction.*?Total\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Attraction total found: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    if (preg_match('/Attraction.*?Total[\s\|]*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Attraction total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    
    /**
     * Extract Tour Transfers total - only if non-zero
     */
/**
 * Extract Tour Transfers total - ONLY return > 0
 */
private function extractTourTransfersTotalFromEmail($text)
{
    if (!preg_match('/Tour Transfers/i', $text)) return 0;
    
    // Look for Total at the bottom of Tour Transfers table
    if (preg_match('/Tour Transfers.*?Total\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Tour Transfers total found: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    if (preg_match('/Tour Transfers.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Tour Transfers total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}
    /**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
/**
 * Extract Profit/Loss from email
 */
private function extractProfitLossFromEmail($text)
{
    // Debug: Log what we're searching
    Log::info("Searching for Profit/Loss in text...");
    
    // Try multiple patterns - order matters from most specific to least specific
    
    // Pattern 1: Pipe table format | Profit/Loss | 14.28 USD |
    if (preg_match('/Profit\/Loss\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (pipe table): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 2: HTML table with Profit/Loss in one cell and value in next cell
    if (preg_match('/Profit\/Loss<\/t[dh]>.*?<t[dh][^>]*>([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (HTML table): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 3: Bold/number format | **Profit/Loss** | **14.28** USD
    if (preg_match('/Profit\/Loss.*?\*\*([\d,]+(?:\.\d+)?)\*\*\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (bold format): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 4: Simple "Profit/Loss 14.28 USD" (spaces)
    if (preg_match('/Profit\/Loss\s+([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (space separated): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 5: "Profit/Loss: 14.28 USD" (with colon)
    if (preg_match('/Profit\/Loss\s*:\s*([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (with colon): " . $profitLoss);
        return $profitLoss;
    }
    
    // Pattern 6: Any number after Profit/Loss within 50 characters
    if (preg_match('/Profit\/Loss.{0,50}?([\d,]+(?:\.\d+)?)\s*USD/i', $text, $match)) {
        $profitLoss = floatval(str_replace(',', '', $match[1]));
        Log::info("✅ Profit/Loss found (flexible): " . $profitLoss);
        return $profitLoss;
    }
    
    Log::info("❌ No Profit/Loss pattern matched");
    return null;
}

private function extractMealsTotalFromEmail($text)
{
    if (!preg_match('/Meals/i', $text)) return 0;
    
    if (preg_match('/Meals.*?Total\s*\|\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Meals total found: " . $total);
        return $total > 0 ? $total : 0;
    }
    
    if (preg_match('/Meals.*?Total\s*:?\s*([\d,]+(?:\.\d+)?)\s*USD/is', $text, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Meals total found (alt): " . $total);
        return $total > 0 ? $total : 0;
    }
    
    return 0;
}


private function extractTransportTotalForSouthEastAsia($html)
{
    if (empty($html)) return 0;
    
    // Method 1: Look for "Total Transport" in HTML tables
    if (preg_match('/Total Transport.*?<td[^>]*>.*?<\/td><td[^>]*>(?:[\d.,]+)<\/td><td[^>]*>([\d.,]+)<\/td>/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total from HTML table: " . $total);
        return $total;
    }
    
    // Method 2: Look for pipe table format with Total Transport
    // Format: | Total Transport | 174 | 196.00 | 196.00 |
    if (preg_match('/\|\s*Total Transport\s*\|\s*[\d.]*\s*\|\s*([\d.,]+)\s*\|\s*([\d.,]+)\s*\|/i', $html, $match)) {
        // Take the last number (TOTAL column)
        $total = floatval(str_replace(',', '', end($match)));
        Log::info("Transport Total from pipe table: " . $total);
        return $total;
    }   
    
    // Method 3: Look for the Transport section and find the number in TOTAL column
    if (preg_match('/Transport.*?Total Transport.*?\|.*?\|.*?\|.*?([\d.,]+)\s*\|/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Transport Total from section: " . $total);
        return $total;
    }
    
    return 0;
}

/**
 * Extract Other Rates total for Vietnam/Singapore/Malaysia format
 */
private function extractOtherRatesTotalForSouthEastAsia($html)
{
    if (empty($html)) return 0;
    
    // Look for "Other Rates" table and find the total
    // The total is usually at the bottom of the table
    
    // Method 1: Find the total in the Other Rates section
    if (preg_match('/Other Rates.*?(?:Total|TOTAL)\s*\|\s*([\d.,]+)\s*\|/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Other Rates Total found: " . $total);
        return $total;
    }
    
    // Method 2: For HTML tables
    if (preg_match('/Other Rates.*?<t[dh][^>]*>Total<\/t[dh]>\s*<t[dh][^>]*>([\d.,]+)<\/t[dh]>/is', $html, $match)) {
        $total = floatval(str_replace(',', '', $match[1]));
        Log::info("Other Rates Total from HTML: " . $total);
        return $total;
    }
    
    // Method 3: Get the last number in Other Rates section
    if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|$)/is', $html, $sectionMatch)) {
        $section = $sectionMatch[1];
        if (preg_match_all('/([\d.,]+)/', $section, $matches)) {
            if (!empty($matches[1])) {
                $total = floatval(str_replace(',', '', end($matches[1])));
                Log::info("Other Rates Total (last number): " . $total);
                return $total;
            }
        }
    }
    
    return 0;
}

/**
 * Extract individual transport items from Transport section
 * Returns array of transport items with their amounts
 */
private function extractTransportItemsFromEmail($html, $plainText = '')
{
    $transportItems = [];
    
    if (empty($html) && empty($plainText)) {
        return $transportItems;
    }
    
    // Try HTML extraction first
    if (!empty($html)) {
        $transportItems = $this->extractTransportItemsFromHTML($html);
    }
    
    // If no items found, try plain text
    if (empty($transportItems) && !empty($plainText)) {
        $transportItems = $this->extractTransportItemsFromPlainText($plainText);
    }
    
    return $transportItems;
}

/**
 * Extract transport items from HTML tables
 */
private function extractTransportItemsFromHTML($html)
{
    $transportItems = [];
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            
            if ($rows->length < 2) continue;
            
            // Check if this is a Transport table
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $headerText = implode(' ', $headers);
            
            // ✅ CRITICAL FIX: Only identify as Transport table if it has EXPENSE column
            // AND it doesn't have hotel-related columns (NAME, SGL, DBL, TPL, CWB, CNB)
            $hasExpense = strpos($headerText, 'EXPENSE') !== false;
            $hasTransport = strpos($headerText, 'TRANSPORT') !== false;
            $hasDistance = strpos($headerText, 'DISTANCE') !== false || strpos($headerText, 'DAYS') !== false;
            $hasHotelColumns = strpos($headerText, 'SGL') !== false || 
                              strpos($headerText, 'DBL') !== false || 
                              strpos($headerText, 'TPL') !== false || 
                              strpos($headerText, 'CWB') !== false || 
                              strpos($headerText, 'CNB') !== false;
            
            // ✅ ONLY process if it's a Transport table (has EXPENSE or TRANSPORT and DISTANCE/DAYS)
            // AND it's NOT a hotel table
            if (!($hasExpense || $hasTransport) || !$hasDistance || $hasHotelColumns) {
                Log::info("Skipping non-transport table: " . $headerText);
                continue;
            }
            
            Log::info("Processing Transport table: " . $headerText);
            
            // Find column indices
            $nameIndex = -1;
            $totalIndex = -1;
            $rateIndex = -1;
            
            foreach ($headers as $index => $header) {
                $upperHeader = strtoupper(trim($header));
                if (strpos($upperHeader, 'EXPENSE') !== false || 
                    strpos($upperHeader, 'NAME') !== false || 
                    strpos($upperHeader, 'PARTICULARS') !== false) {
                    $nameIndex = $index;
                }
                if (strpos($upperHeader, 'TOTAL') !== false) {
                    $totalIndex = $index;
                }
                if (strpos($upperHeader, 'RATE') !== false) {
                    $rateIndex = $index;
                }
            }
            
            if ($nameIndex == -1 || $totalIndex == -1) {
                continue;
            }
            
            // Parse rows (skip header row)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                if (count($cells) <= max($nameIndex, $totalIndex)) {
                    continue;
                }
                
                $serviceName = trim($cells[$nameIndex]);
                
                // Extract the total amount - ensure it's properly parsed
                $totalValue = trim($cells[$totalIndex]);
                // Remove any non-numeric characters except decimal point
                $totalValue = preg_replace('/[^0-9.]/', '', $totalValue);
                $totalAmount = floatval($totalValue);
                
                // ✅ STRICT CHECK: Skip if amount is 0 or negative
                if ($totalAmount <= 0) {
                    Log::info("Skipping transport item with zero/negative amount: {$serviceName} - {$totalAmount}");
                    continue;
                }
                
                // Skip if service name is empty or is a total/summary row
                if (empty($serviceName) || 
                    strtoupper($serviceName) === 'TOTAL' ||
                    strtoupper($serviceName) === 'TOTAL TRANSPORT' ||
                    strtoupper($serviceName) === 'MEAL TRANSPORT TOTAL' ||
                    preg_match('/^[\d.,]+$/', $serviceName)) {
                    Log::info("Skipping summary row: {$serviceName}");
                    continue;
                }
                
                // Skip if it's a "Meal Transport Total" or summary row
                if (preg_match('/meal transport|total transport/i', $serviceName)) {
                    Log::info("Skipping transport summary: {$serviceName}");
                    continue;
                }
                
                // ✅ EXTRA CHECK: Skip if it looks like a hotel name (has common hotel keywords)
                if (preg_match('/hotel|resort|villa|apartment|inn|lodge|hostel/i', $serviceName)) {
                    Log::info("Skipping hotel item in transport: {$serviceName}");
                    continue;
                }
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $totalAmount,
                    'details' => ['remarks' => $serviceName]
                ];
                
                Log::info("✓ Transport item extracted (HTML): {$serviceName} - \${$totalAmount}");
            }
            
            // If we found items, break out of table loop
            if (!empty($transportItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Transport items HTML extraction error: ' . $e->getMessage());
    }
    
    return $transportItems;
}

/**
 * Extract individual transport items from plain text.
 * Handles space‑separated data (HTML tables stripped to text).
 * Extracts EACH row with amount > 0.
 */
private function extractTransportItemsFromPlainText($text)
{
    $transportItems = [];
    if (empty($text)) {
        return $transportItems;
    }

    // Find the Transport section
    if (!preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        Log::info("Transport section not found in plain text");
        return $transportItems;
    }

    $section = $sectionMatch[1];
    Log::info("Transport section found for plain text extraction");

    // Split into lines
    $lines = preg_split('/\r\n|\n|\r/', $section);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        // Skip header lines
        if (preg_match('/^(EXPENSE|DISTANCE|DAYS|RATE|TOTAL|NAME|PARTICULARS)/i', $line)) {
            continue;
        }

        // Skip total rows (lines that contain "Total" as a standalone word or in a cell)
        if (preg_match('/^\s*Total\s*$/i', $line) || 
            preg_match('/\|\s*Total\s*\|/i', $line) ||
            preg_match('/\*\*Total\*\*/i', $line) ||
            preg_match('/^Total\s+Transport/i', $line) ||
            preg_match('/Meal Transport Total/i', $line)) {
            continue;
        }

        // Find all numbers (including decimals) in the line
        preg_match_all('/\b\d+\.?\d*\b/', $line, $matches);
        $numbers = $matches[0];
        if (empty($numbers)) {
            continue; // no numbers, skip
        }

        // The total is the last number
        $total = (float) end($numbers);
        if ($total <= 0) {
            continue; // skip zero or negative totals
        }

        // Find the position of the first number
        $firstNumber = reset($numbers);
        $pos = strpos($line, $firstNumber);
        if ($pos === false) {
            continue;
        }

        // Service name is everything before the first number
        $serviceName = trim(substr($line, 0, $pos));
        // Clean up extra spaces and pipe characters
        $serviceName = preg_replace('/\s+/', ' ', $serviceName);
        $serviceName = trim($serviceName, '| ');

        // Skip if service name is empty or is a known header/total
        if (empty($serviceName)) {
            continue;
        }
        if (in_array(strtoupper($serviceName), ['EXPENSE', 'DISTANCE/DAYS', 'RATE', 'TOTAL', 'NAME', 'OTHER COST'])) {
            continue;
        }
        if (preg_match('/^(Total|TOTAL)$/i', $serviceName) || 
            preg_match('/^Total\s+Transport/i', $serviceName) ||
            preg_match('/Meal Transport Total/i', $serviceName)) {
            continue;
        }

        // Avoid duplicates (same service name)
        $exists = false;
        foreach ($transportItems as $item) {
            if ($item['service_name'] === $serviceName) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            continue;
        }

        $transportItems[] = [
            'service_name' => $serviceName,
            'amount' => $total,
            'details' => ['remarks' => $serviceName]
        ];
        Log::info("✓ Transport item extracted (plain): {$serviceName} - \${$total}");
    }

    // Final fallback: If no items found, use the total row (only if > 0)
    if (empty($transportItems)) {
        if (preg_match('/Total\s*:?\s*([\d,]+\.?\d*)\s*USD?/i', $section, $match)) {
            $total = floatval(str_replace(',', '', $match[1]));
            if ($total > 0) {
                $transportItems[] = [
                    'service_name' => 'Transport Expenses (Total)',
                    'amount' => $total,
                    'details' => ['remarks' => 'Total transport expenses']
                ];
                Log::info("⚠️ Using transport total as fallback: \${$total}");
            }
        }
    }

    Log::info("Total transport items extracted (plain): " . count($transportItems));
    return $transportItems;
}
/**
 * Extract transport items from table text format (for Sri Lanka)
 * Handles: Travel, Bata, Paging, Highway Charges, Driver Accomodation, Guide Fee, Water Bottles
 */
private function extractTransportItemsFromTableText($text)
{
    $transportItems = [];
    
    // Try to find Transport section
    if (preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        
        $lines = explode("\n", $section);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header lines
            if (preg_match('/EXPENSE|DISTANCE|DAYS|RATE|TOTAL/i', $line)) continue;
            if (preg_match('/^Total/i', $line)) continue;
            
            // ✅ Special handling for Water Bottles
            if (preg_match('/Water Bottles/i', $line)) {
                if (preg_match('/Water Bottles.*?(\d+\.?\d*)\s*USD/i', $line, $match)) {
                    $amount = floatval($match[1]);
                    if ($amount > 0) {
                        $rateDetail = '';
                        if (preg_match('/Adt\s*-\s*([\d.]+),\s*cwb\s*-\s*([\d.]+),\s*cnb\s*-\s*([\d.]+)/i', $line, $rateMatch)) {
                            $rateDetail = "Adult: {$rateMatch[1]}, CWB: {$rateMatch[2]}, CNB: {$rateMatch[3]}";
                        }
                        
                        $transportItems[] = [
                            'service_name' => 'Water Bottles',
                            'amount' => $amount,
                            'details' => [
                                'remarks' => 'Water Bottles',
                                'rate' => $rateDetail,
                                'type' => 'water_bottles'
                            ]
                        ];
                        Log::info("✓ Water Bottles extracted: \${$amount} - {$rateDetail}");
                    }
                }
                continue;
            }
            
            // ✅ Try to match pattern: Name    Number    Rate    Amount
            if (preg_match('/^([A-Za-z\s]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $line, $match)) {
                $serviceName = trim($match[1]);
                $distanceOrDays = floatval($match[2]);
                $rate = floatval($match[3]);
                $amount = floatval($match[4]);
                
                if ($amount <= 0 || empty($serviceName)) continue;
                if (preg_match('/total|transport/i', $serviceName)) continue;
                
                // ✅ Determine if the DISTANCE/DAYS is KM or Days
                $unitType = 'Days';
                
                // Travel is always KM (distance)
                if (stripos($serviceName, 'Travel') !== false) {
                    $unitType = 'KM';
                } 
                // Bata, Paging, Highway Charges, Driver Accommodation, Guide Fee are Days
                elseif (stripos($serviceName, 'Bata') !== false || 
                        stripos($serviceName, 'Paging') !== false || 
                        stripos($serviceName, 'Highway') !== false || 
                        stripos($serviceName, 'Driver') !== false || 
                        stripos($serviceName, 'Guide') !== false) {
                    $unitType = 'Days';
                }
                
                // ✅ Build remarks with distance/days info
                $remarks = $serviceName;
                if ($distanceOrDays > 0) {
                    if ($unitType == 'KM') {
                        $remarks = "{$serviceName} - {$distanceOrDays} KM";
                    } else {
                        $remarks = "{$serviceName} - {$distanceOrDays} Days";
                    }
                }
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $remarks,
                        'distance_days' => $distanceOrDays,
                        'unit_type' => $unitType,
                        'rate' => $rate,
                    ]
                ];
                
                Log::info("✓ Transport item extracted (LK): {$serviceName} - {$distanceOrDays} {$unitType} - \${$amount}");
            }
            // ✅ Try pattern with pipe: | Name | value | value | amount |
            elseif (preg_match('/\|\s*([A-Za-z\s]+)\s*\|\s*[\d.]*\s*\|\s*[\d.]*\s*\|\s*([\d.]+)\s*\|/', $line, $match)) {
                $serviceName = trim($match[1]);
                $amount = floatval($match[2]);
                
                if ($amount <= 0 || empty($serviceName)) continue;
                if (preg_match('/total|transport/i', $serviceName)) continue;
                
                $distanceOrDays = 0;
                $unitType = 'Days';
                
                if (preg_match('/' . preg_quote($serviceName, '/') . '\s*\|\s*([\d.]+)\s*\|/', $line, $distMatch)) {
                    $distanceOrDays = floatval($distMatch[1]);
                }
                
                if (stripos($serviceName, 'Travel') !== false) {
                    $unitType = 'KM';
                }
                
                $remarks = $serviceName;
                if ($distanceOrDays > 0) {
                    if ($unitType == 'KM') {
                        $remarks = "{$serviceName} - {$distanceOrDays} KM";
                    } else {
                        $remarks = "{$serviceName} - {$distanceOrDays} Days";
                    }
                }
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $remarks,
                        'distance_days' => $distanceOrDays,
                        'unit_type' => $unitType,
                    ]
                ];
                
                Log::info("✓ Transport item extracted (LK pipe): {$serviceName} - \${$amount}");
            }
        }
    }
    
    return $transportItems;
}

private function extractTransportItemsForCountry($html, $plainText, $countryCode)
{
    $transportItems = [];

    // ✅ For Sri Lanka (LK) - Extract from HTML table directly
    if ($countryCode == 'LK') {
        $transportItems = $this->extractTransportItemsFromHTMLTable($html);
        Log::info("Transport items from HTML table (LK): " . json_encode($transportItems));
        
        // Filter out items with amount <= 0
        $transportItems = array_filter($transportItems, function ($item) {
            return isset($item['amount']) && $item['amount'] > 0;
        });
        
        return array_values($transportItems);
    }

    // For other countries (VN, SG, MY) - use existing logic
    if (!empty($html)) {
        $transportItems = $this->extractTransportItemsFromHTML($html);
        Log::info("Transport items from HTML: " . json_encode($transportItems));
    }

    if (empty($transportItems)) {
        $transportItems = $this->extractTransportItemsFromPlainText($plainText);
        Log::info("Transport items from plain text: " . json_encode($transportItems));
    }

    $transportItems = array_filter($transportItems, function ($item) {
        return isset($item['amount']) && $item['amount'] > 0;
    });

    return array_values($transportItems);
}
private function extractTransportItemsFromHTMLTable($html)
{
    $transportItems = [];
    
    if (empty($html)) {
        Log::warning("⚠️ HTML is empty for LK transport extraction");
        return $transportItems;
    }

    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        Log::info("📊 Total tables found for LK: " . $tables->length);
        
        foreach ($tables as $tableIndex => $table) {
            $rows = $table->getElementsByTagName('tr');
            
            if ($rows->length < 2) continue;
            
            // Get headers from first row
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $headerText = implode(' ', $headers);
            Log::info("📋 Table {$tableIndex} Headers: " . $headerText);
            
            // ✅ Check if this is a Transport table
            $hasExpense = strpos($headerText, 'EXPENSE') !== false;
            $hasDistance = strpos($headerText, 'DISTANCE') !== false || strpos($headerText, 'DAYS') !== false;
            $hasRate = strpos($headerText, 'RATE') !== false;
            $hasTotal = strpos($headerText, 'TOTAL') !== false;
            
            // Skip if not a transport table
            if (!$hasExpense || !$hasDistance || !$hasRate || !$hasTotal) {
                Log::info("⏭️ Skipping non-transport table: " . $headerText);
                continue;
            }
            
            Log::info("✅ Found Transport table for LK!");
            
            // Find column indices
            $expenseIndex = -1;
            $distanceIndex = -1;
            $rateIndex = -1;
            $totalIndex = -1;
            
            foreach ($headers as $index => $header) {
                $upperHeader = strtoupper(trim($header));
                if (strpos($upperHeader, 'EXPENSE') !== false) {
                    $expenseIndex = $index;
                }
                if (strpos($upperHeader, 'DISTANCE') !== false || strpos($upperHeader, 'DAYS') !== false) {
                    $distanceIndex = $index;
                }
                if (strpos($upperHeader, 'RATE') !== false) {
                    $rateIndex = $index;
                }
                if (strpos($upperHeader, 'TOTAL') !== false) {
                    $totalIndex = $index;
                }
            }
            
            Log::info("📊 Column indices - Expense: {$expenseIndex}, Distance: {$distanceIndex}, Rate: {$rateIndex}, Total: {$totalIndex}");
            
            // Process data rows (skip header row)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                if (count($cells) <= max($expenseIndex, $distanceIndex, $rateIndex, $totalIndex)) {
                    continue;
                }
                
                $serviceName = trim($cells[$expenseIndex]);
                $distanceOrDays = floatval($cells[$distanceIndex] ?? 0);
                $rate = floatval($cells[$rateIndex] ?? 0);
                $amount = floatval($cells[$totalIndex] ?? 0);
                
                // ✅ Skip if amount is 0 or service name is empty
                if ($amount <= 0 || empty($serviceName)) {
                    Log::info("⏭️ Skipping row - Amount: {$amount}, Service: {$serviceName}");
                    continue;
                }
                
                // Skip summary rows
                if (preg_match('/total|transport|other cost/i', $serviceName)) {
                    Log::info("⏭️ Skipping summary row: {$serviceName}");
                    continue;
                }
                
                // ✅ Special handling for Water Bottles
                if (stripos($serviceName, 'Water Bottles') !== false) {
                    $rateDetail = '';
                    if (preg_match('/Adt\s*-\s*([\d.]+),\s*cwb\s*-\s*([\d.]+),\s*cnb\s*-\s*([\d.]+)/i', $cells[$rateIndex] ?? '', $rateMatch)) {
                        $rateDetail = "Adult: {$rateMatch[1]}, CWB: {$rateMatch[2]}, CNB: {$rateMatch[3]}";
                    }
                    
                    $transportItems[] = [
                        'service_name' => 'Water Bottles',
                        'amount' => $amount,
                        'details' => [
                            'remarks' => 'Water Bottles',
                            'rate' => $rateDetail,
                            'type' => 'water_bottles'
                        ]
                    ];
                    Log::info("✅ Water Bottles extracted: \${$amount} - {$rateDetail}");
                    continue;
                }
                
                // ✅ Determine unit type
                $unitType = 'Days';
                if (stripos($serviceName, 'Travel') !== false) {
                    $unitType = 'KM';
                }
                
                // ✅ Build remarks with distance/days and rate
                $remarks = $serviceName;
                if ($distanceOrDays > 0) {
                    $remarks .= " - {$distanceOrDays} " . $unitType;
                }
                if ($rate > 0) {
                    $remarks .= " ({$rate} per " . $unitType . ")";
                }
                
                $transportItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $remarks,
                        'distance_days' => $distanceOrDays,
                        'unit_type' => $unitType,
                        'rate' => $rate,
                    ]
                ];
                
                Log::info("✅ LK Transport: {$serviceName} - {$distanceOrDays} {$unitType} - Rate: {$rate} - \${$amount}");
            }
            
            // If we found items, break out of table loop
            if (!empty($transportItems)) {
                Log::info("✅ Found " . count($transportItems) . " transport items for LK");
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('LK Transport HTML extraction error: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
    
    return $transportItems;
}
public function fetchAllPnLEmailsInBackground()
{
    try {
        set_time_limit(0); // No time limit
        ini_set('memory_limit', '1024M');
        
        $allMessages = [];
        $nextLink = null;
        $pageCount = 0;
        $maxPages = 50;

        $url = 'https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages'
            . '?$top=200'
            . '&$orderby=receivedDateTime desc'
            . '&$select=id,subject,bodyPreview,from,receivedDateTime,isRead,hasAttachments';

        do {
            $response = Http::withToken($this->accessToken)
                ->timeout(300) // 5 minutes per page
                ->get($url);

            if (!$response->ok()) {
                Log::error('Failed to fetch PnL emails: ' . $response->body());
                break;
            }

            $data = $response->json();
            $messages = $data['value'] ?? [];
            $allMessages = array_merge($allMessages, $messages);
            
            $nextLink = $data['@odata.nextLink'] ?? null;
            $pageCount++;
            
            Log::info("Background fetch page {$pageCount}: " . count($messages) . " emails");

            if ($pageCount >= $maxPages) {
                Log::warning("Reached max pages ({$maxPages})");
                break;
            }

            if ($nextLink) {
                usleep(300000); // 0.3 second delay
            }

        } while ($nextLink);

        Log::info("Total emails fetched in background: " . count($allMessages));

        $newCount = 0;
        $sno = PnlRecord::max('sno') ?? 0;

        foreach ($allMessages as $message) {
            $existing = PnlRecord::where('message_id', $message['id'])->first();
            if (!$existing) {
                $sno++;
                $fullMessage = $this->fetchFullMessage($message['id']);
                if ($fullMessage) {
                    $saved = $this->savePnLEmail($fullMessage, $sno);
                    if ($saved) $newCount++;
                }
            }
        }

        Log::info("Background fetch completed: {$newCount} new emails");
        return $newCount;

    } catch (\Exception $e) {
        Log::error('Background fetch error: ' . $e->getMessage());
        return 0;
    }
}

protected function fetchFullMessage($messageId)
{
    try {
        $response = Http::withToken($this->accessToken)
            ->timeout(30) // ✅ Reduced from 60 to 30 seconds
            ->get('https://graph.microsoft.com/v1.0/users/' . env('GRAPH_PNL_USER') . '/messages/' . $messageId, [
                '$select' => 'id,subject,body,bodyPreview,from,receivedDateTime,isRead,hasAttachments',
            ]);
        
        if ($response->ok()) {
            return $response->json();
        }
    } catch (\Exception $e) {
        Log::error("Failed to fetch full message: " . $e->getMessage());
    }
    return null;
}
/**
 * Update PnL items with client info from matching invoices
 */
protected function updatePnLItemsWithInvoiceData($pnlRecord)
{
    try {
        $invoiceNumber = $pnlRecord->invoice_number;
        $tourRef = $pnlRecord->tour_ref;
        
        $matchedEmail = null;
        
        // Try to match by invoice_number
        if ($invoiceNumber && $invoiceNumber !== 'NA' && $invoiceNumber !== 'N/A') {
            $matchedEmail = \App\Models\IncomingEmail::where('invoice_number', $invoiceNumber)
                ->whereNotNull('guest_name')
                ->where('guest_name', '!=', 'NA')
                ->where('guest_name', '!=', '')
                ->first();
        }
        
        // If not found, try by tour_ref
        if (!$matchedEmail && $tourRef && $tourRef !== 'NA' && $tourRef !== 'N/A') {
            $matchedEmail = \App\Models\IncomingEmail::where('tour_ref', $tourRef)
                ->whereNotNull('guest_name')
                ->where('guest_name', '!=', 'NA')
                ->where('guest_name', '!=', '')
                ->first();
        }
        
        if ($matchedEmail) {
            Log::info("✅ Found matching invoice for PnL record {$pnlRecord->id}");
            
            // ✅ UPDATE PNL RECORD - client_name and dates
            $pnlRecord->vendor_name = $matchedEmail->guest_name;
            $pnlRecord->start_date = $matchedEmail->travel_start_date;
            $pnlRecord->end_date = $matchedEmail->travel_end_date;
            $pnlRecord->save();
            
            // Update all PnL items
            $pnlItems = PnlItem::where('pnl_record_id', $pnlRecord->id)->get();
            $updatedCount = 0;
            
            foreach ($pnlItems as $item) {
                $updated = false;
                
                // ✅ Always update client_name
                if ($matchedEmail->guest_name && $matchedEmail->guest_name !== 'NA' && $matchedEmail->guest_name !== '') {
                    $item->client_name = $matchedEmail->guest_name;
                    $updated = true;
                }
                
                // ✅ IMPORTANT: Only update dates for NON-HOTEL items
                // Hotel items already have their own dates from autoMatchHotelDates
                if ($item->type !== 'HOTEL') {
                    if ($matchedEmail->travel_start_date) {
                        $item->start_date = $matchedEmail->travel_start_date;
                        if (isset($item->check_in_date)) {
                            $item->check_in_date = $matchedEmail->travel_start_date;
                        }
                        $updated = true;
                    }
                    
                    if ($matchedEmail->travel_end_date) {
                        $item->end_date = $matchedEmail->travel_end_date;
                        if (isset($item->check_out_date)) {
                            $item->check_out_date = $matchedEmail->travel_end_date;
                        }
                        $updated = true;
                    }
                }
                
                if ($updated) {
                    $item->save();
                    $updatedCount++;
                }
            }
            
            Log::info("✅ Updated PnL record and {$updatedCount} items with client info and dates (non-hotel items only)");
            return $updatedCount;
        }
        
        return 0;
        
    } catch (\Exception $e) {
        Log::error("Error updating PnL items with invoice data: " . $e->getMessage());
        return 0;
    }
}

private function extractAttractionItems($html, $plainText)
{
    $attractionItems = [];
    
    // Get Adult and Child counts from the email header
    $adultCount = 0;
    $childCount = 0;
    
    if (preg_match('/No\.\s*Adult\s*:\s*(\d+)/i', $plainText, $match)) {
        $adultCount = intval($match[1]);
    }
    if (preg_match('/No\.\s*Child\s*:\s*(\d+)/i', $plainText, $match)) {
        $childCount = intval($match[1]);
    }
    
    $totalPax = $adultCount + $childCount;
    Log::info("Total PAX for attraction: Adult: {$adultCount}, Child: {$childCount}, Total: {$totalPax}");
    
    // ✅ FIRST: Try HTML extraction (more reliable)
    if (!empty($html)) {
        $attractionItems = $this->extractAttractionItemsFromHTML($html, $totalPax, $adultCount, $childCount);
        if (!empty($attractionItems)) {
            Log::info("✅ Extracted " . count($attractionItems) . " attractions from HTML");
            return $attractionItems;
        }
    }
    
    // ✅ SECOND: Fallback to plain text parsing
    Log::info("No attractions found in HTML, trying plain text...");
    
    if (preg_match('/Attraction(.*?)(?:Tour Transfers|Meals|Other Rates|$)/is', $plainText, $sectionMatch)) {
        $attractionSection = $sectionMatch[1];
        $lines = explode("\n", $attractionSection);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line);
                $cleanParts = array_map('trim', $parts);
                $cleanParts = array_filter($cleanParts, function($part) {
                    return $part !== '';
                });
                $cleanParts = array_values($cleanParts);
                
                if (count($cleanParts) < 4) continue;
                
                $firstPart = strtoupper($cleanParts[0] ?? '');
                if (in_array($firstPart, ['#DAY(S)', 'DAY', 'CITY', 'ATTRACTION', 'TOTAL'])) continue;
                
                // Check if this is a total row
                $firstPartLower = strtolower($cleanParts[0] ?? '');
                if (strpos($firstPartLower, 'total') !== false) continue;
                
                // Extract day number
                $dayNumber = 0;
                if (preg_match('/Day\s*(\d+)/i', $cleanParts[0] ?? '', $dayMatch)) {
                    $dayNumber = intval($dayMatch[1]);
                }
                
                // Extract attraction name
                $attractionName = '';
                foreach ($cleanParts as $index => $part) {
                    $part = trim($part);
                    if (preg_match('/^Day\s*\d+/i', $part)) continue;
                    if (preg_match('/^[A-Za-z\s]+$/', $part) && strlen($part) < 20 && in_array(trim($part), ['Danang', 'Hanoi', 'Langkawi', 'Singapore', 'Bali', 'Kuala Lumpur', 'Colombo'])) continue;
                    if (strlen($part) > 5 && !preg_match('/^\d+$/', $part)) {
                        $attractionName = $part;
                        break;
                    }
                }
                
                if (empty($attractionName) && isset($cleanParts[2])) {
                    $attractionName = trim($cleanParts[2]);
                }
                
                if (empty($attractionName)) continue;
                
                // Skip if it's a total or summary
                if (preg_match('/total|transfer|entrance/i', $attractionName)) continue;
                
                $adultRate = 0;
                $childRate = 0;
                foreach ($cleanParts as $part) {
                    $part = trim($part);
                    if (preg_match('/Adult:\s*([\d.]+)/i', $part, $match)) {
                        $adultRate = floatval($match[1]);
                    }
                    if (preg_match('/Child:\s*([\d.]+)/i', $part, $match)) {
                        $childRate = floatval($match[1]);
                    }
                }
                
                if ($adultRate == 0) {
                    $lastPart = trim(end($cleanParts));
                    if (preg_match('/Adult:\s*([\d.]+)/i', $lastPart, $match)) {
                        $adultRate = floatval($match[1]);
                    }
                }
                
                if ($adultRate <= 0) continue;
                
                // ✅ Calculate total amount using adult and child counts
                $totalAmount = 0;
                if ($adultRate > 0 && $adultCount > 0) {
                    $totalAmount += $adultRate * $adultCount;
                }
                if ($childRate > 0 && $childCount > 0) {
                    $totalAmount += $childRate * $childCount;
                }
                
                // If still 0, use total PAX as fallback
                if ($totalAmount == 0 && $adultRate > 0 && $totalPax > 0) {
                    $totalAmount = $adultRate * $totalPax;
                }
                
                // ✅ Build remarks with both adult and child details
                $remarks = "";
                if ($adultRate > 0 && $adultCount > 0) {
                    $remarks .= "Adult: {$adultCount} × " . number_format($adultRate, 2) . " = " . number_format($adultRate * $adultCount, 2);
                }
                if ($childRate > 0 && $childCount > 0) {
                    if (!empty($remarks)) $remarks .= ", ";
                    $remarks .= "Child: {$childCount} × " . number_format($childRate, 2) . " = " . number_format($childRate * $childCount, 2);
                }
                if (empty($remarks)) {
                    $remarks = "Total: " . number_format($totalAmount, 2);
                }
                
                $attractionItems[] = [
                    'service_name' => $attractionName,
                    'amount' => $totalAmount,
                    'details' => [
                        'remarks' => $remarks,
                        'adult_rate' => $adultRate,
                        'child_rate' => $childRate,
                        'adult_count' => $adultCount,
                        'child_count' => $childCount,
                        'pax' => $totalPax,
                        'day' => $dayNumber
                    ]
                ];
                
                Log::info("✓ Attraction (plain text): {$attractionName} - Day {$dayNumber} - {$remarks}");
            }
        }
    }
    
    // ✅ If still no items found, return empty array
    if (empty($attractionItems)) {
        Log::warning("⚠️ No attraction items found! Check the email format.");
        return [];
    }
    
    return $attractionItems;
}


private function extractAttractionItemsFromHTML($html, $totalPax = 0, $adultCount = 0, $childCount = 0)
{
    $attractionItems = [];
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            // Check headers
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $headerText = implode(' ', $headers);
            
            // Check if this is an Attraction table
            if (strpos($headerText, 'ATTRACTION') === false && strpos($headerText, 'ENTRANCE') === false) {
                continue;
            }
            
            Log::info("✅ Found Attraction table with headers: " . $headerText);
            
            // Find column indices
            $nameIndex = 2; // Default: ATTRACTION is column 2
            $adultEntranceIndex = -1;
            $childEntranceIndex = -1;
            $transferIndex = -1;
            $rateIndex = -1;
            $dayIndex = 0;
            
            foreach ($headers as $index => $header) {
                $upper = strtoupper(trim($header));
                if (strpos($upper, 'ATTRACTION') !== false || strpos($upper, 'NAME') !== false) {
                    $nameIndex = $index;
                }
                if (strpos($upper, 'ADULT ENTRANCE') !== false) {
                    $adultEntranceIndex = $index;
                }
                if (strpos($upper, 'CHILD ENTRANCE') !== false) {
                    $childEntranceIndex = $index;
                }
                if (strpos($upper, 'TRANSFER') !== false) {
                    $transferIndex = $index;
                }
                if (strpos($upper, 'RATE') !== false) {
                    $rateIndex = $index;
                }
                if (strpos($upper, 'DAY') !== false) {
                    $dayIndex = $index;
                }
            }
            
            Log::info("📊 Column indices - Day: {$dayIndex}, Name: {$nameIndex}, Adult Entrance: {$adultEntranceIndex}, Child Entrance: {$childEntranceIndex}, Transfer: {$transferIndex}, Rate: {$rateIndex}");
            
            // Process rows (skip header)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                // Skip empty rows
                if (empty($cells) || count($cells) < 2) continue;
                
                // Skip total row
                $firstCell = strtolower($cells[0] ?? '');
                if (strpos($firstCell, 'total') !== false) continue;
                
                // Extract day number
                $dayNumber = 0;
                if (isset($cells[$dayIndex])) {
                    if (preg_match('/Day\s*(\d+)/i', $cells[$dayIndex], $match)) {
                        $dayNumber = intval($match[1]);
                    }
                }
                
                // Extract attraction name
                $attractionName = '';
                if (isset($cells[$nameIndex])) {
                    $attractionName = trim($cells[$nameIndex]);
                }
                
                // Skip if empty or looks like a city name
                if (empty($attractionName) || strlen($attractionName) < 3) continue;
                if (in_array($attractionName, ['Danang', 'Hanoi', 'Phu Quoc', 'Da Nang', 'City Center', 'Kuala Lumpur', 'Singapore', 'Bali'])) continue;
                
                // ✅ GET ADULT ENTRANCE (This is the RATE per person)
                $adultEntranceRate = 0;
                if ($adultEntranceIndex != -1 && isset($cells[$adultEntranceIndex])) {
                    $adultEntranceRate = floatval(preg_replace('/[^0-9.]/', '', $cells[$adultEntranceIndex]));
                }
                Log::info("📊 Adult Entrance Rate: {$adultEntranceRate} for {$attractionName}");
                
                // ✅ GET CHILD ENTRANCE (This is the RATE per child)
                $childEntranceRate = 0;
                if ($childEntranceIndex != -1 && isset($cells[$childEntranceIndex])) {
                    $childEntranceRate = floatval(preg_replace('/[^0-9.]/', '', $cells[$childEntranceIndex]));
                }
                Log::info("📊 Child Entrance Rate: {$childEntranceRate} for {$attractionName}");
                
                // ✅ GET TRANSFER AMOUNT (if any)
                $transferAmount = 0;
                if ($transferIndex != -1 && isset($cells[$transferIndex])) {
                    $transferAmount = floatval(preg_replace('/[^0-9.]/', '', $cells[$transferIndex]));
                }
                Log::info("📊 Transfer Amount: {$transferAmount} for {$attractionName}");
                
                // ✅ GET RATE from RATE column (Adult: X, Child: Y)
                $adultRate = 0;
                $childRate = 0;
                if ($rateIndex != -1 && isset($cells[$rateIndex])) {
                    $rateValue = trim($cells[$rateIndex]);
                    Log::info("📊 Raw RATE value: '{$rateValue}' for {$attractionName}");
                    
                    if (preg_match('/Adult:\s*([\d.]+)/i', $rateValue, $match)) {
                        $adultRate = floatval($match[1]);
                        Log::info("✅ Extracted Adult Rate: {$adultRate}");
                    }
                    if (preg_match('/Child:\s*([\d.]+)/i', $rateValue, $match)) {
                        $childRate = floatval($match[1]);
                        Log::info("✅ Extracted Child Rate: {$childRate}");
                    }
                    
                    // If no Adult: format, try to get any number
                    if ($adultRate == 0 && preg_match('/\b(\d+\.?\d*)\b/', $rateValue, $match)) {
                        $adultRate = floatval($match[1]);
                        Log::info("✅ Extracted Adult Rate (fallback): {$adultRate}");
                    }
                }
                
                Log::info("📊 Adult Count from header: {$adultCount}, Child Count from header: {$childCount}");
                Log::info("📊 Adult Entrance: {$adultEntranceRate}, Child Entrance: {$childEntranceRate}");
                Log::info("📊 Adult Rate from RATE column: {$adultRate}, Child Rate from RATE column: {$childRate}");
                
                // ✅ ========== CALCULATE ATTRACTION AMOUNT ==========
                $totalAmount = 0;
                $remarks = "";
                $calculationType = "";
                
                // ✅ METHOD 1: Use Adult Entrance × Adult Count (if Adult Entrance > 0)
                if ($adultEntranceRate > 0 && $adultCount > 0) {
                    $totalAmount += $adultEntranceRate * $adultCount;
                    $remarks = "Adult: {$adultCount} × " . number_format($adultEntranceRate, 2) . " = " . number_format($adultEntranceRate * $adultCount, 2);
                    $calculationType = "ENTRANCE";
                    Log::info("✅ Adult Entrance: {$adultCount} × {$adultEntranceRate} = " . ($adultEntranceRate * $adultCount));
                }
                
                // ✅ METHOD 2: Use Child Entrance × Child Count (if Child Entrance > 0)
                if ($childEntranceRate > 0 && $childCount > 0) {
                    if (!empty($remarks)) $remarks .= ", ";
                    $totalAmount += $childEntranceRate * $childCount;
                    $remarks .= "Child: {$childCount} × " . number_format($childEntranceRate, 2) . " = " . number_format($childEntranceRate * $childCount, 2);
                    $calculationType = "ENTRANCE";
                    Log::info("✅ Child Entrance: {$childCount} × {$childEntranceRate} = " . ($childEntranceRate * $childCount));
                }
                
                // ✅ METHOD 3: If NO entrance rates, use RATE column values
                if ($totalAmount == 0) {
                    // Use Adult Rate × Adult Count
                    if ($adultRate > 0 && $adultCount > 0) {
                        $totalAmount += $adultRate * $adultCount;
                        $remarks = "Adult: {$adultCount} × " . number_format($adultRate, 2) . " = " . number_format($adultRate * $adultCount, 2);
                        $calculationType = "RATE";
                        Log::info("✅ Adult Rate: {$adultCount} × {$adultRate} = " . ($adultRate * $adultCount));
                    }
                    
                    // Use Child Rate × Child Count
                    if ($childRate > 0 && $childCount > 0) {
                        if (!empty($remarks)) $remarks .= ", ";
                        $totalAmount += $childRate * $childCount;
                        $remarks .= "Child: {$childCount} × " . number_format($childRate, 2) . " = " . number_format($childRate * $childCount, 2);
                        $calculationType = "RATE";
                        Log::info("✅ Child Rate: {$childCount} × {$childRate} = " . ($childRate * $childCount));
                    }
                }
                
                // ✅ METHOD 4: If transfer exists and no other amounts
                if ($totalAmount == 0 && $transferAmount > 0) {
                    $totalAmount = $transferAmount;
                    $remarks = "Transfer: " . number_format($transferAmount, 2);
                    $calculationType = "TRANSFER";
                    Log::info("✅ Using Transfer Amount: {$transferAmount}");
                }
                
                // ✅ METHOD 5: Fallback - use adultRate × totalPax
                if ($totalAmount == 0 && $adultRate > 0 && $totalPax > 0) {
                    $totalAmount = $adultRate * $totalPax;
                    $remarks = "Adult Rate: {$adultRate} × {$totalPax} pax = " . number_format($totalAmount, 2);
                    $calculationType = "FALLBACK";
                    Log::info("⚠️ Fallback: {$totalPax} × {$adultRate} = {$totalAmount}");
                }
                
                // ✅ Skip if no amount found
                if ($totalAmount <= 0) {
                    Log::info("⏭️ Skipping attraction with zero amount: {$attractionName}");
                    continue;
                }
                
                // ✅ Add the attraction item
                $attractionItems[] = [
                    'service_name' => $attractionName,
                    'amount' => $totalAmount,
                    'details' => [
                        'remarks' => $remarks,
                        'adult_rate' => ($calculationType == 'ENTRANCE') ? $adultEntranceRate : $adultRate,
                        'child_rate' => ($calculationType == 'ENTRANCE') ? $childEntranceRate : $childRate,
                        'adult_count' => $adultCount,
                        'child_count' => $childCount,
                        'adult_entrance' => $adultEntranceRate,
                        'child_entrance' => $childEntranceRate,
                        'pax' => $totalPax,
                        'day' => $dayNumber,
                        'transfer' => $transferAmount,
                        'calculation_type' => $calculationType,
                    ]
                ];
                
                Log::info("✅ Attraction: {$attractionName} - Day {$dayNumber} - {$remarks} - \${$totalAmount} (Type: {$calculationType})");
            }
            
            // If we found items, break
            if (!empty($attractionItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Attraction HTML extraction error: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
    
    return $attractionItems;
}

private function extractTourTransferItems($html, $plainText, $totalPax, $adultCount = 0, $childCount = 0)
{
    $transferItems = [];
    
    // First try HTML parsing for VN/SG/MY or any HTML content
    if (!empty($html)) {
        $items = $this->extractTourTransferItemsFromHTML($html, $totalPax, $adultCount, $childCount);
        if (!empty($items)) {
            return $items;
        }
    }
    
    // Fallback to plain text parsing (for LK or if HTML parsing fails)
    return $this->extractTourTransferItemsFromPlainText($plainText, $totalPax);
}
/**
 * Fallback: Extract Tour Transfer items from plain text
 */
private function extractTourTransferItemsFromPlainText($plainText, $totalPax)
{
    $transferItems = [];
    
    if (empty($plainText)) {
        return $transferItems;
    }
    
    // Try to find Tour Transfers section
    if (!preg_match('/Tour Transfers(.*?)(?:Attraction|Meals|Other Rates|$)/is', $plainText, $sectionMatch)) {
        Log::info("No Tour Transfers section found in plain text");
        return $transferItems;
    }
    
    $section = $sectionMatch[1];
    Log::info("Tour Transfers section found in plain text");
    
    // Look for rows with pipe format
    $lines = explode("\n", $section);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Skip header and total lines
        if (preg_match('/#DAY|CITY|ATTRACTION|ADULT|CHILD|TRANSFER|RATE|Total/i', $line)) {
            continue;
        }
        
        // Try pipe format
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line);
            $cleanParts = array_map('trim', $parts);
            $cleanParts = array_filter($cleanParts, function($p) { return $p !== ''; });
            $cleanParts = array_values($cleanParts);
            
            // Need at least 5 columns
            if (count($cleanParts) < 5) continue;
            
            // ✅ FIX: Get service name from ATTRACTION column
            $serviceName = '';
            $adultRate = 0;
            $transferAmount = 0;
            
            // ATTRACTION is usually column 2 or 3
            // Skip if it's empty or just a number
            if (isset($cleanParts[2]) && !empty($cleanParts[2]) && !is_numeric($cleanParts[2])) {
                $serviceName = trim($cleanParts[2]);
            } elseif (isset($cleanParts[1]) && !empty($cleanParts[1]) && !is_numeric($cleanParts[1])) {
                $serviceName = trim($cleanParts[1]);
            }
            
            // Skip if no service name
            if (empty($serviceName)) {
                continue;
            }
            
            // Skip if it's a total or summary
            if (strpos(strtolower($serviceName), 'total') !== false) {
                continue;
            }
            
            // Find transfer and rate
            foreach ($cleanParts as $idx => $val) {
                if (is_numeric($val) && floatval($val) > 0 && $idx > 2) {
                    // Check if this is transfer column (usually before rate)
                    if (isset($cleanParts[$idx+1]) && preg_match('/Adult:/i', $cleanParts[$idx+1])) {
                        $transferAmount = floatval($val);
                        if (preg_match('/Adult:\s*([\d.]+)/i', $cleanParts[$idx+1], $match)) {
                            $adultRate = floatval($match[1]);
                        }
                    } elseif (preg_match('/Adult:/i', $val)) {
                        if (preg_match('/Adult:\s*([\d.]+)/i', $val, $match)) {
                            $adultRate = floatval($match[1]);
                            if ($idx > 0 && is_numeric($cleanParts[$idx-1])) {
                                $transferAmount = floatval($cleanParts[$idx-1]);
                            }
                        }
                    }
                }
            }
            
            // Determine amount
            $amount = 0;
            if ($transferAmount > 0) {
                $amount = $transferAmount;
            } elseif ($adultRate > 0 && $totalPax > 0) {
                $amount = $adultRate * $totalPax;
            }
            
            if ($amount <= 0) continue;
            
            // Avoid duplicates
            $exists = false;
            foreach ($transferItems as $item) {
                if ($item['service_name'] === $serviceName) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) continue;
            
            $transferItems[] = [
                'service_name' => $serviceName,
                'amount' => $amount,
                'details' => [
                    'remarks' => $serviceName,
                    'adult_rate' => $adultRate,
                    'transfer_amount' => $transferAmount,
                    'pax' => $totalPax,
                ]
            ];
            Log::info("✅ Added Tour Transfer item from plain text: {$serviceName} - \${$amount}");
        }
    }
    
    return $transferItems;
}
private function extractTransfersFromAttraction($plainText, $noAdult = 0)
{
    $transferItems = [];
    
    if ($noAdult == 0) {
        if (preg_match('/No\.\s*Adult\s*:\s*(\d+)/i', $plainText, $match)) {
            $noAdult = intval($match[1]);
        }
    }
    
    if (preg_match('/Attraction(.*?)(?:Tour Transfers|Meals|Other Rates|$)/is', $plainText, $sectionMatch)) {
        $attractionSection = $sectionMatch[1];
        Log::info("Checking Attraction section for transfers...");
        
        // Look for lines with Adult: X pattern
        $lines = explode("\n", $attractionSection);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (preg_match('/Adult:\s*([\d.]+)/i', $line, $match)) {
                $adultRate = floatval($match[1]);
                if ($adultRate <= 0) continue;
                
                // Try to find service name
                $serviceName = '';
                
                if (strpos($line, '|') !== false) {
                    $parts = explode('|', $line);
                    $cleanParts = array_map('trim', $parts);
                    $cleanParts = array_filter($cleanParts, function($part) {
                        return $part !== '';
                    });
                    $cleanParts = array_values($cleanParts);
                    
                    foreach ($cleanParts as $index => $part) {
                        if (stripos($part, 'Transfer') !== false || stripos($part, 'SIC') !== false || stripos($part, 'PVT') !== false) {
                            $serviceName = trim($part);
                            break;
                        }
                    }
                    
                    if (empty($serviceName) && isset($cleanParts[2]) && !empty($cleanParts[2]) && strlen($cleanParts[2]) > 3) {
                        $serviceName = trim($cleanParts[2]);
                    }
                }
                
                if (empty($serviceName)) {
                    if (preg_match('/Day\s*\d+\s+[A-Za-z\s]+\s+(.+?)\s*Adult:/i', $line, $nameMatch)) {
                        $serviceName = trim($nameMatch[1]);
                    }
                }
                
                if (!empty($serviceName)) {
                    $serviceName = str_replace('|', '', $serviceName);
                    $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                    $serviceName = trim($serviceName);
                    
                    $cityNames = ['Danang', 'Hanoi', 'Langkawi', 'Phuquoc', 'Singapore', 'Bali', 'Kuala Lumpur', 'Colombo', 'Phu Quoc', 'Hoi An', 'City Center', 'Da Nang'];
                    if (in_array($serviceName, $cityNames)) continue;
                    if (strlen($serviceName) < 3) continue;
                }
                
                if (empty($serviceName)) continue;
                
                $amount = $adultRate * $noAdult;
                if ($amount <= 0) continue;
                
                $transferItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'adult_rate' => $adultRate,
                        'pax' => $noAdult
                    ]
                ];
                
                Log::info("✓ Transfer extracted from Attraction section: {$serviceName} - \${$amount} (Adult: {$adultRate} x {$noAdult} pax)");
            }
        }
    }
    
    return $transferItems;
}

private function extractTourTransferItemsFromHTML($html, $totalPax, $adultCount = 0, $childCount = 0)
{
    $transferItems = [];
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        // ✅ FIRST: Collect all attraction names from Attraction table
        $attractionNames = [];
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $headerText = implode(' ', $headers);
            if (strpos($headerText, 'ATTRACTION') !== false || strpos($headerText, 'ENTRANCE') !== false) {
                for ($i = 1; $i < $rows->length; $i++) {
                    $row = $rows->item($i);
                    $cells = [];
                    foreach ($row->childNodes as $cell) {
                        if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                            $cells[] = trim($cell->textContent);
                        }
                    }
                    if (count($cells) >= 3) {
                        $attractionName = $cells[2] ?? '';
                        if (!empty($attractionName) && $attractionName !== 'TOTAL' && !is_numeric($attractionName)) {
                            $attractionNames[] = strtolower(trim($attractionName));
                        }
                    }
                }
                break;
            }
        }
        
        Log::info("Found " . count($attractionNames) . " attraction names to skip");
        
        // ✅ SECOND: Process Tour Transfers table
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            $hasDay = false;
            $hasTransfer = false;
            $hasRate = false;
            $hasAdultEntrance = false;
            $hasChildEntrance = false;
            
            foreach ($headers as $h) {
                if (strpos($h, 'DAY') !== false) $hasDay = true;
                if (strpos($h, 'TRANSFER') !== false) $hasTransfer = true;
                if (strpos($h, 'RATE') !== false) $hasRate = true;
                if (strpos($h, 'ADULT ENTRANCE') !== false || strpos($h, 'ADULT') !== false) $hasAdultEntrance = true;
                if (strpos($h, 'CHILD ENTRANCE') !== false || strpos($h, 'CHILD') !== false) $hasChildEntrance = true;
            }
            
            if (!$hasDay || !$hasTransfer || !$hasRate) continue;
            
            $dayIndex = -1;
            $attractionIndex = -1;
            $transferIndex = -1;
            $rateIndex = -1;
            $adultEntranceIndex = -1;
            $childEntranceIndex = -1;
            
            foreach ($headers as $i => $h) {
                $upper = strtoupper(trim($h));
                if (strpos($upper, 'DAY') !== false) $dayIndex = $i;
                if (strpos($upper, 'ATTRACTION') !== false) $attractionIndex = $i;
                if (strpos($upper, 'TRANSFER') !== false) $transferIndex = $i;
                if (strpos($upper, 'RATE') !== false) $rateIndex = $i;
                if (strpos($upper, 'ADULT ENTRANCE') !== false) $adultEntranceIndex = $i;
                if (strpos($upper, 'CHILD ENTRANCE') !== false) $childEntranceIndex = $i;
            }
            
            Log::info("Processing Tour Transfers table - Day: {$dayIndex}, Attraction: {$attractionIndex}, Transfer: {$transferIndex}, Rate: {$rateIndex}, Adult Entrance: {$adultEntranceIndex}, Child Entrance: {$childEntranceIndex}");
            
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                // Skip if not enough columns
                if (count($cells) <= max($transferIndex, $rateIndex)) continue;
                
                // Extract day number
                $dayNumber = 0;
                if ($dayIndex != -1 && isset($cells[$dayIndex])) {
                    $dayValue = trim($cells[$dayIndex]);
                    if (preg_match('/Day\s*(\d+)/i', $dayValue, $match)) {
                        $dayNumber = intval($match[1]);
                    }
                }
                
                // ✅ Get service name from ATTRACTION column
                $serviceName = '';
                if ($attractionIndex != -1 && isset($cells[$attractionIndex])) {
                    $attractionValue = trim($cells[$attractionIndex]);
                    if (!empty($attractionValue) && !is_numeric($attractionValue) && $attractionValue !== '0' && $attractionValue !== '-') {
                        $serviceName = $attractionValue;
                    }
                }
                
                // ✅ Skip if service name is empty
                if (empty($serviceName)) {
                    Log::info("Skipping row with empty service name - Day: {$dayNumber}");
                    continue;
                }
                
                // ✅ Skip if it's a total row
                if (strtoupper($serviceName) === 'TOTAL' || strpos(strtolower($serviceName), 'total') !== false) {
                    continue;
                }
                
                // ✅ Skip if it's just a number or placeholder
                if (is_numeric($serviceName) || $serviceName === '0' || $serviceName === '-') {
                    continue;
                }
                
                // ✅ Skip if service name is an attraction name
                $isAttraction = false;
                foreach ($attractionNames as $attractionName) {
                    if (stripos($serviceName, $attractionName) !== false || stripos($attractionName, $serviceName) !== false) {
                        $isAttraction = true;
                        break;
                    }
                }
                
                if ($isAttraction) {
                    Log::info("Skipping attraction in Tour Transfers: {$serviceName}");
                    continue;
                }
                
                // ✅ Get transfer amount (PACKAGE COST for PVT)
                $transferAmount = 0;
                if ($transferIndex != -1 && isset($cells[$transferIndex])) {
                    $transferValue = trim($cells[$transferIndex]);
                    $transferAmount = floatval(preg_replace('/[^0-9.]/', '', $transferValue));
                }
                Log::info("📊 Transfer (Package) Amount: {$transferAmount} for {$serviceName}");
                
                // ✅ GET ACTUAL ADULT ENTRANCE COUNT (NOT RATE!)
                $adultEntranceCount = 0;
                if ($adultEntranceIndex != -1 && isset($cells[$adultEntranceIndex])) {
                    $adultEntranceCount = intval(preg_replace('/[^0-9]/', '', $cells[$adultEntranceIndex]));
                }
                
                // ✅ GET ACTUAL CHILD ENTRANCE COUNT (NOT RATE!)
                $childEntranceCount = 0;
                if ($childEntranceIndex != -1 && isset($cells[$childEntranceIndex])) {
                    $childEntranceCount = intval(preg_replace('/[^0-9]/', '', $cells[$childEntranceIndex]));
                }
                Log::info("📊 Adult Entrance Count: {$adultEntranceCount}, Child Entrance Count: {$childEntranceCount} for {$serviceName}");
                
                // ✅ Get adult and child rates from RATE column
                $adultRate = 0;
                $childRate = 0;
                
                if ($rateIndex != -1 && isset($cells[$rateIndex])) {
                    $rateValue = trim($cells[$rateIndex]);
                    Log::info("📊 Raw RATE value: '{$rateValue}' for {$serviceName}");
                    
                    if (preg_match('/Adult:\s*([\d.]+)/i', $rateValue, $match)) {
                        $adultRate = floatval($match[1]);
                        Log::info("✅ Extracted Adult Rate: {$adultRate}");
                    }
                    if (preg_match('/Child:\s*([\d.]+)/i', $rateValue, $match)) {
                        $childRate = floatval($match[1]);
                        Log::info("✅ Extracted Child Rate: {$childRate}");
                    }
                    
                    if ($adultRate == 0 && preg_match('/\b(\d+\.?\d*)\b/', $rateValue, $match)) {
                        $adultRate = floatval($match[1]);
                        Log::info("✅ Extracted Adult Rate (fallback): {$adultRate}");
                    }
                }
                
                Log::info("📊 Final Adult Rate: {$adultRate}, Child Rate: {$childRate}");
                Log::info("📊 Adult Count from header: {$adultCount}, Child Count from header: {$childCount}");
                Log::info("📊 Adult Entrance: {$adultEntranceCount}, Child Entrance: {$childEntranceCount}");
                
                // ✅ ========== CALCULATE ENTRANCE AMOUNT ==========
                // FIX: Use adultEntranceCount as the RATE, multiplied by adultCount from header
                $entranceAmount = 0;
                $entranceRemarks = "";
                
                // ✅ Adult Entrance: adultEntranceCount (from table) × adultCount (from header)
                if ($adultEntranceCount > 0 && $adultCount > 0) {
                    $entranceAmount += $adultEntranceCount * $adultCount;
                    $entranceRemarks = "Adult: {$adultCount} × " . number_format($adultEntranceCount, 2) . " = " . number_format($adultEntranceCount * $adultCount, 2);
                    Log::info("✅ Adult Entrance: {$adultCount} × {$adultEntranceCount} = " . ($adultEntranceCount * $adultCount));
                }
                
                // ✅ Child Entrance: childEntranceCount (from table) × childCount (from header)
                if ($childEntranceCount > 0 && $childCount > 0) {
                    if (!empty($entranceRemarks)) $entranceRemarks .= ", ";
                    $entranceAmount += $childEntranceCount * $childCount;
                    $entranceRemarks .= "Child: {$childCount} × " . number_format($childEntranceCount, 2) . " = " . number_format($childEntranceCount * $childCount, 2);
                    Log::info("✅ Child Entrance: {$childCount} × {$childEntranceCount} = " . ($childEntranceCount * $childCount));
                }
                
                // ✅ If entrance amount > 0, save as separate item
                if ($entranceAmount > 0) {
                    $transferItems[] = [
                        'service_name' => $serviceName,
                        'amount' => $entranceAmount,
                        'details' => [
                            'remarks' => $entranceRemarks,
                            'adult_rate' => $adultEntranceCount,
                            'child_rate' => $childEntranceCount,
                            'adult_entrance' => $adultEntranceCount,
                            'child_entrance' => $childEntranceCount,
                            'transfer_amount' => 0,
                            'pax' => $totalPax,
                            'day' => $dayNumber,
                            'calculation_type' => 'ENTRANCE',
                            'is_entrance' => true,
                            'is_transfer' => false,
                            'adult_count' => $adultCount,
                            'child_count' => $childCount,
                        ]
                    ];
                    Log::info("✅ Added ENTRANCE: {$serviceName} - {$entranceRemarks} - \${$entranceAmount}");
                }
                
                // ✅ ========== ROW 2: TRANSFER (PACKAGE) ==========
                if ($transferAmount > 0) {
                    $transferItems[] = [
                        'service_name' => $serviceName,
                        'amount' => $transferAmount,
                        'details' => [
                            'remarks' => "Package: " . number_format($transferAmount, 2),
                            'adult_rate' => 0,
                            'child_rate' => 0,
                            'adult_entrance' => 0,
                            'child_entrance' => 0,
                            'transfer_amount' => $transferAmount,
                            'pax' => $totalPax,
                            'day' => $dayNumber,
                            'calculation_type' => 'TRANSFER',
                            'is_entrance' => false,
                            'is_transfer' => true,
                            'adult_count' => 0,
                            'child_count' => 0,
                        ]
                    ];
                    Log::info("✅ Added TRANSFER: {$serviceName} - Package: \${$transferAmount}");
                }
                
                // ✅ ========== ROW 3: TRANSPORT (when no entrance/transfer but RATE exists) ==========
                if ($entranceAmount == 0 && $transferAmount == 0 && $adultRate > 0) {
                    $transportAmount = $adultRate * $totalPax;
                    
                    $transferItems[] = [
                        'service_name' => $serviceName,
                        'amount' => $transportAmount,
                        'details' => [
                            'remarks' => "Rate: Adult " . number_format($adultRate, 2) . " × {$totalPax} pax = " . number_format($transportAmount, 2),
                            'adult_rate' => $adultRate,
                            'child_rate' => $childRate,
                            'adult_entrance' => 0,
                            'child_entrance' => 0,
                            'transfer_amount' => 0,
                            'pax' => $totalPax,
                            'day' => $dayNumber,
                            'calculation_type' => 'TRANSPORT_RATE',
                            'is_entrance' => false,
                            'is_transfer' => false,
                            'is_transport' => true,
                            'adult_count' => $totalPax,
                            'child_count' => 0,
                        ]
                    ];
                    Log::info("✅ Added TRANSPORT: {$serviceName} - \${$transportAmount} (Adult Rate: {$adultRate} × {$totalPax} pax)");
                }
                
                if ($entranceAmount == 0 && $transferAmount == 0 && $adultRate == 0) {
                    Log::info("⏭️ Skipping {$serviceName} - no entrance, transfer, or rate");
                }
            }
            
            if (!empty($transferItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Error extracting Tour Transfers: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
    
    return $transferItems;
}
private function extractMealItems($plainText)
{
    $mealItems = [];
    
    // Get No. Adult
    $noAdult = 0;
    if (preg_match('/No\.\s*Adult:\s*(\d+)/i', $plainText, $match)) {
        $noAdult = intval($match[1]);
    }
    
    // Try to find Meals section
    if (preg_match('/Meals(.*?)(?:Transport|Other Rates|Attraction|Tour Transfers|$)/is', $plainText, $sectionMatch)) {
        $mealSection = $sectionMatch[1];
        Log::info("Meals section found");
        Log::info("Meals section preview: " . substr($mealSection, 0, 500));
        
        // Look for meal rows with pipe format
        $lines = explode("\n", $mealSection);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line);
                $cleanParts = array_map('trim', $parts);
                $cleanParts = array_filter($cleanParts, function($part) {
                    return $part !== '';
                });
                $cleanParts = array_values($cleanParts);
                
                if (count($cleanParts) < 2) continue;
                
                // Skip header
                $firstPart = strtoupper($cleanParts[0] ?? '');
                if (in_array($firstPart, ['DAY', 'TOTAL'])) continue;
                
                // Check if this is a total row
                if (strpos(strtolower($cleanParts[0] ?? ''), 'total') !== false) {
                    continue;
                }
                
                // Get day name
                $day = trim($cleanParts[0] ?? '');
                if (empty($day) || !preg_match('/Day/i', $day)) continue;
                
                // Try to find total amount in the last column
                $amount = 0;
                $lastPart = trim(end($cleanParts));
                if (preg_match('/\b(\d+\.\d+)\b/', $lastPart, $match)) {
                    $amount = floatval($match[1]);
                }
                
                // Also check other columns for meal amounts
                $breakfast = 0;
                $lunch = 0;
                $dinner = 0;
                
                foreach ($cleanParts as $part) {
                    if (preg_match('/BREAK.*?(\d+\.?\d*)/i', $part, $match)) {
                        $breakfast = floatval($match[1]);
                    }
                    if (preg_match('/LUNCH.*?(\d+\.?\d*)/i', $part, $match)) {
                        $lunch = floatval($match[1]);
                    }
                    if (preg_match('/DINNER.*?(\d+\.?\d*)/i', $part, $match)) {
                        $dinner = floatval($match[1]);
                    }
                }
                
                // If no total found, calculate from meal components
                if ($amount == 0 && ($breakfast > 0 || $lunch > 0 || $dinner > 0)) {
                    // Multiply by No. Adult
                    $amount = ($breakfast + $lunch + $dinner) * $noAdult;
                }
                
                if ($amount <= 0) continue;
                
                // Create separate items for each meal type if they have amounts
                if ($breakfast > 0) {
                    $mealItems[] = [
                        'service_name' => "{$day} - Breakfast",
                        'amount' => $breakfast * $noAdult,
                        'details' => ['remarks' => "{$day} - Breakfast", 'pax' => $noAdult]
                    ];
                }
                if ($lunch > 0) {
                    $mealItems[] = [
                        'service_name' => "{$day} - Lunch",
                        'amount' => $lunch * $noAdult,
                        'details' => ['remarks' => "{$day} - Lunch", 'pax' => $noAdult]
                    ];
                }
                if ($dinner > 0) {
                    $mealItems[] = [
                        'service_name' => "{$day} - Dinner",
                        'amount' => $dinner * $noAdult,
                        'details' => ['remarks' => "{$day} - Dinner", 'pax' => $noAdult]
                    ];
                }
            }
        }
    }
    
    return $mealItems;
}

private function extractOtherRateItems($html, $plainText = '')
{
    $otherRateItems = [];
    
    // If HTML is empty, fallback to plain text parsing
    if (empty($html)) {
        return $this->extractOtherRateItemsFromPlainText($plainText);
    }
    
    try {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        
        $tables = $dom->getElementsByTagName('table');
        
        foreach ($tables as $table) {
            $rows = $table->getElementsByTagName('tr');
            if ($rows->length < 2) continue;
            
            // Check headers to identify the Other Rates table
            $headers = [];
            $firstRow = $rows->item(0);
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['th', 'td'])) {
                    $headers[] = trim(strtoupper($cell->textContent));
                }
            }
            
            // Look for headers: PAX, RATE, TOTAL (case-insensitive)
            $hasPax = false;
            $hasRate = false;
            $hasTotal = false;
            foreach ($headers as $h) {
                if (strpos($h, 'PAX') !== false) $hasPax = true;
                if (strpos($h, 'RATE') !== false) $hasRate = true;
                if (strpos($h, 'TOTAL') !== false) $hasTotal = true;
            }
            
            // If not an Other Rates table, skip
            if (!$hasPax || !$hasRate || !$hasTotal) {
                continue;
            }
            
            Log::info("✅ Found Other Rates table with headers: " . implode(', ', $headers));
            
            // Find column indices
            $paxIndex = -1;
            $rateIndex = -1;
            $totalIndex = -1;
            $serviceNameIndex = 0;
            
            foreach ($headers as $i => $h) {
                $upper = strtoupper(trim($h));
                if (strpos($upper, 'PAX') !== false) $paxIndex = $i;
                if (strpos($upper, 'RATE') !== false) $rateIndex = $i;
                if (strpos($upper, 'TOTAL') !== false) $totalIndex = $i;
                // Service name is usually the first column
                if ($i == 0) $serviceNameIndex = $i;
            }
            
            Log::info("📊 Column indices - Service: {$serviceNameIndex}, PAX: {$paxIndex}, RATE: {$rateIndex}, TOTAL: {$totalIndex}");
            
            // Process data rows (skip header row)
            for ($i = 1; $i < $rows->length; $i++) {
                $row = $rows->item($i);
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'])) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                
                // Skip if not enough columns
                if (count($cells) <= max($paxIndex, $rateIndex, $totalIndex)) {
                    continue;
                }
                
                // ✅ Get service name from first column
                $serviceName = trim($cells[$serviceNameIndex] ?? '');
                
                // Skip if service name is empty or is a total row
                if (empty($serviceName) || strtoupper($serviceName) === 'TOTAL') {
                    continue;
                }
                
                // ✅ Get PAX info - handle both numbers and dashes
                $paxInfo = trim($cells[$paxIndex] ?? '');
                $paxCount = 0;
                $paxType = 'adult';
                
                // Check if PAX is a dash or empty - treat as 1 (single item)
                if ($paxInfo === '-' || $paxInfo === '') {
                    $paxCount = 1;
                    $paxType = 'adult';
                } 
                // Check for different PAX formats
                elseif (preg_match('/adult\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'adult';
                } 
                elseif (preg_match('/cwb\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'cwb';
                }
                elseif (preg_match('/cnb\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'cnb';
                }
                elseif (preg_match('/child\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'child';
                }
                // If it's just a number
                elseif (preg_match('/\b(\d+)\b/', $paxInfo, $match) && floatval($match[1]) > 0) {
                    $paxCount = intval($match[1]);
                    $paxType = 'adult';
                }
                
                // ✅ Get rate - handle dashes
                $rateValue = trim($cells[$rateIndex] ?? '');
                $rate = 0;
                if ($rateValue !== '-' && $rateValue !== '') {
                    $rate = floatval(preg_replace('/[^0-9.]/', '', $rateValue));
                }
                
                // ✅ Get total amount - THIS IS THE KEY
                $totalValue = trim($cells[$totalIndex] ?? '');
                $amount = 0;
                if ($totalValue !== '-' && $totalValue !== '') {
                    $amount = floatval(preg_replace('/[^0-9.]/', '', $totalValue));
                }
                
                // ✅ If amount is 0 but rate and pax exist, calculate
                if ($amount == 0 && $rate > 0 && $paxCount > 0) {
                    $amount = $rate * $paxCount;
                    Log::info("📊 Calculated amount: {$rate} × {$paxCount} = {$amount}");
                }
                
                // ✅ If amount is 0, try to find any number in the row
                if ($amount == 0) {
                    foreach ($cells as $cell) {
                        $cell = trim($cell);
                        if ($cell !== '-' && $cell !== '' && is_numeric(str_replace(',', '', $cell))) {
                            $num = floatval(str_replace(',', '', $cell));
                            if ($num > 0) {
                                $amount = $num;
                                Log::info("📊 Found amount from cell: {$amount}");
                                break;
                            }
                        }
                    }
                }
                
                // ✅ Skip if amount <= 0
                if ($amount <= 0) {
                    Log::info("⏭️ Skipping row with zero amount: {$serviceName} - Amount: {$amount}");
                    continue;
                }
                
                // ✅ Create unique key to avoid duplicates
                $uniqueKey = $serviceName . '_' . $paxType . '_' . $paxCount;
                
                // Check for duplicates
                $exists = false;
                foreach ($otherRateItems as $item) {
                    $itemKey = $item['service_name'] . '_' . ($item['details']['pax_type'] ?? 'adult') . '_' . ($item['details']['pax'] ?? 0);
                    if ($itemKey === $uniqueKey) {
                        $exists = true;
                        break;
                    }
                }
                
                if ($exists) {
                    Log::info("⏭️ Skipping duplicate: {$serviceName} - {$paxType} : {$paxCount}");
                    continue;
                }
                
                // ✅ Build remarks WITHOUT service name (only pax details)
                if ($paxType == 'adult') {
                    $remarks = "Adult: {$paxCount} × " . number_format($rate, 2) . " = " . number_format($amount, 2);
                } elseif ($paxType == 'cwb') {
                    $remarks = "CWB: {$paxCount} × " . number_format($rate, 2) . " = " . number_format($amount, 2);
                } elseif ($paxType == 'cnb') {
                    $remarks = "CNB: {$paxCount} × " . number_format($rate, 2) . " = " . number_format($amount, 2);
                } else {
                    $remarks = "Total: " . number_format($amount, 2);
                }
                
                $otherRateItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $remarks,
                        'pax' => $paxCount,
                        'rate' => $rate,
                        'pax_type' => $paxType,
                    ]
                ];
                
                Log::info("✅ Added Other Rate: {$serviceName} - {$remarks}");
            }
            
            // If we found items, break out of table loop
            if (!empty($otherRateItems)) {
                break;
            }
        }
        
    } catch (\Exception $e) {
        Log::error('Error extracting Other Rates from HTML: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
    
    // If no items found via HTML, try plain text fallback
    if (empty($otherRateItems) && !empty($plainText)) {
        Log::info("No Other Rate items from HTML, trying plain text fallback");
        return $this->extractOtherRateItemsFromPlainText($plainText);
    }
    
    Log::info("📊 Total Other Rate items extracted: " . count($otherRateItems));
    return $otherRateItems;
}
private function extractOtherRateItemsFromPlainText($plainText)
{
    $otherRateItems = [];
    
    // Find Other Rates section
    if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|Transport|$)/is', $plainText, $sectionMatch)) {
        $section = $sectionMatch[1];
        Log::info("Other Rates section found in plain text");
        
        // Split into lines
        $lines = explode("\n", $section);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip header
            if (preg_match('/PAX|RATE|TOTAL/i', $line)) continue;
            
            // Try to extract using pipe format or spaces
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line);
                $cleanParts = array_map('trim', $parts);
                $cleanParts = array_filter($cleanParts, function($p) { return $p !== ''; });
                $cleanParts = array_values($cleanParts);
                
                if (count($cleanParts) < 3) continue;
                
                // Check if this is a total row
                if (strtolower($cleanParts[0]) === 'total') continue;
                
                $serviceName = $cleanParts[0];
                $paxInfo = $cleanParts[1] ?? '';
                $rate = floatval(preg_replace('/[^0-9.]/', '', $cleanParts[2] ?? '0'));
                $amount = 0;
                
                // Try to get amount from the last column
                if (isset($cleanParts[3])) {
                    $amount = floatval(preg_replace('/[^0-9.]/', '', $cleanParts[3]));
                }
                
                // If no amount found, try to find any number in the row
                if ($amount == 0) {
                    foreach ($cleanParts as $part) {
                        $num = floatval(preg_replace('/[^0-9.]/', '', $part));
                        if ($num > 0 && $num != $rate) {
                            $amount = $num;
                            break;
                        }
                    }
                }
                
                if ($amount <= 0) continue;
                
                // Determine pax type and count
                $paxCount = 1;
                $paxType = 'adult';
                if (preg_match('/adult\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                } elseif (preg_match('/cnb\s*:\s*(\d+)/i', $paxInfo, $match)) {
                    $paxCount = intval($match[1]);
                    $paxType = 'cnb';
                } elseif (is_numeric($paxInfo) && floatval($paxInfo) > 0) {
                    $paxCount = intval($paxInfo);
                }
                
                $otherRateItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'pax' => $paxCount,
                        'rate' => $rate,
                        'pax_type' => $paxType,
                    ]
                ];
                
                Log::info("✅ Other Rate from plain text: {$serviceName} - \${$amount} (Pax: {$paxCount})");
            }
        }
    }
    
    return $otherRateItems;
}

/**
 * Alternative method to extract Other Rate items using pattern matching
 */
private function extractOtherRateItemsByPattern($plainText)
{
    $otherRateItems = [];
    
    // Try to find the Other Rates section
    if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|$)/is', $plainText, $sectionMatch)) {
        $otherSection = $sectionMatch[1];
        
        // Look for patterns like: "| Service Name | adult : 6 | 37 | 222.00 |"
        // This pattern matches lines that start with |, have text, then adult : number, then rate, then amount
        if (preg_match_all('/\|\s*([^|]+?)\s*\|\s*adult\s*:\s*(\d+)\s*\|\s*(\d+\.?\d*)\s*\|\s*([\d,]+\.\d+)\s*\|/i', $otherSection, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $serviceName = trim($match[1]);
                $paxCount = intval($match[2]);
                $rate = floatval($match[3]);
                $amount = floatval(str_replace(',', '', $match[4]));
                
                // Skip if amount is 0 or negative
                if ($amount <= 0) continue;
                
                // Skip if service name is empty or too short
                if (empty($serviceName) || strlen($serviceName) < 3) continue;
                
                // Skip if it's a child row (we only want adult rows)
                // Child rows are handled separately, we only process adult rows here
                
                $otherRateItems[] = [
                    'service_name' => $serviceName,
                    'amount' => $amount,
                    'details' => [
                        'remarks' => $serviceName,
                        'pax' => $paxCount,
                        'rate' => $rate
                    ]
                ];
                
                Log::info("✓ Other Rate item extracted (pattern): {$serviceName} - \${$amount} (Pax: {$paxCount}, Rate: {$rate})");
            }
        }
    }
    
    return $otherRateItems;
}
protected function autoMatchHotelDates($record)
{
    try {
        if (!$record->tour_ref || $record->tour_ref === 'NA' || $record->tour_ref === 'N/A') {
            Log::info("No tour_ref for hotel date matching: " . $record->id);
            return;
        }
        
        // Check if there are hotel items
        $hotelItems = PnlItem::where('pnl_record_id', $record->id)
            ->where('type', 'HOTEL')
            ->get();
        
        if ($hotelItems->isEmpty()) {
            Log::info("No hotel items to match for record: " . $record->id);
            return;
        }
        
        // ✅ Use the HotelDateMatcherService for proper per-hotel date matching
        $matcher = new \App\Services\HotelDateMatcherService();
        $result = $matcher->matchHotelDates($record->id);
        
        if ($result['success'] && $result['updated_count'] > 0) {
            Log::info("✅ Auto-matched hotel dates for {$record->tour_ref}: {$result['updated_count']} hotels updated");
        } else {
            Log::info("No hotel dates matched for: " . $record->tour_ref);
        }
        
    } catch (\Exception $e) {
        Log::error('Auto hotel date matching failed: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
}

protected function updateNonHotelItemDates($record)
{
    try {
        if (!$record->tour_ref || $record->tour_ref === 'NA' || $record->tour_ref === 'N/A') {
            return;
        }
        
        // ✅ Use cached email to avoid multiple queries
        $tourEmail = \App\Models\IncomingEmail::where('tour_ref', $record->tour_ref)
            ->where('is_tour_confirmation', true)
            ->first();
        
        if (!$tourEmail) {
            Log::info("No tour email found for: " . $record->tour_ref);
            return;
        }
        
        $travelStartDate = $tourEmail->travel_start_date ? Carbon::parse($tourEmail->travel_start_date) : null;
        $travelEndDate = $tourEmail->travel_end_date ? Carbon::parse($tourEmail->travel_end_date) : null;
        
        // ✅ If no travel dates, use record dates
        if (!$travelStartDate && $record->start_date) {
            $travelStartDate = Carbon::parse($record->start_date);
        }
        if (!$travelEndDate && $record->end_date) {
            $travelEndDate = Carbon::parse($record->end_date);
        }
        
        if (!$travelStartDate && !$travelEndDate) {
            Log::info("No travel dates found for: " . $record->tour_ref);
            return;
        }
        
        // ✅ Get all non-hotel items
        $items = PnlItem::where('pnl_record_id', $record->id)
            ->whereIn('type', ['ATTRACTION', 'TOUR TRANSFER', 'TRANSPORT', 'OTHER RATES', 'MEALS'])
            ->get();
        
        if ($items->isEmpty()) {
            Log::info("No items to update for record: " . $record->id);
            return;
        }
        
        $updatedCount = 0;
        $isSriLanka = ($record->country_code == 'LK');
        
        foreach ($items as $item) {
            // ✅ For Sri Lanka TRANSPORT, use the full travel date range
            if ($isSriLanka && $item->type == 'TRANSPORT') {
                if ($travelStartDate) {
                    $item->start_date = $travelStartDate->format('Y-m-d');
                }
                if ($travelEndDate) {
                    $item->end_date = $travelEndDate->format('Y-m-d');
                }
                $item->save();
                $updatedCount++;
                Log::info("✅ [Sri Lanka] Updated Transport: {$item->service_name} - {$travelStartDate->format('Y-m-d')} to {$travelEndDate->format('Y-m-d')}");
                continue;
            }
            
            // For other countries OR other item types, try to extract day number
            $itemDetails = json_decode($item->item_details, true);
            $dayNumber = $this->extractDayNumber($item->service_name, $itemDetails);
            
            if ($dayNumber && $dayNumber > 0 && $travelStartDate) {
                $dayDate = $travelStartDate->copy()->addDays($dayNumber - 1);
                $item->start_date = $dayDate->format('Y-m-d');
                $item->end_date = $dayDate->format('Y-m-d');
                $item->save();
                $updatedCount++;
                Log::info("✅ Updated: {$item->service_name} - Day {$dayNumber} -> {$dayDate->format('Y-m-d')}");
            } elseif ($travelStartDate && $travelEndDate) {
                // Fallback: use full travel date range
                $item->start_date = $travelStartDate->format('Y-m-d');
                $item->end_date = $travelEndDate->format('Y-m-d');
                $item->save();
                $updatedCount++;
                Log::info("✅ Updated: {$item->service_name} - {$travelStartDate->format('Y-m-d')} to {$travelEndDate->format('Y-m-d')}");
            }
        }
        
        Log::info("✅ Updated {$updatedCount} items with travel dates for record {$record->id}");
        
    } catch (\Exception $e) {
        Log::error('Error updating non-hotel item dates: ' . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
}

/**
 * Extract day number from service name or details
 */
private function extractDayNumber($serviceName, $details)
{
    // Try to find "Day X" pattern in service name
    if (preg_match('/Day\s*(\d+)/i', $serviceName, $match)) {
        return intval($match[1]);
    }
    
    // Try from details
    if (isset($details['day']) && is_numeric($details['day'])) {
        return intval($details['day']);
    }
    
    // Try from remarks
    if (isset($details['remarks']) && preg_match('/Day\s*(\d+)/i', $details['remarks'], $match)) {
        return intval($match[1]);
    }
    
    return null;
}

/**
 * Get revision information from email
 * Format: VN40202_R2/R3
 */
private function getRevisionInfo($isNumber, $plainText, $subject)
{
    $revisionNumber = 0;
    $versionCount = 0;
    $finalIsNumber = $isNumber;
    $baseIsNumber = $isNumber;
    
    // 1. Check if is_number already has revision format
    if (preg_match('/^([A-Z]{2}\d+)_R(\d+)\/R(\d+)$/', $isNumber, $match)) {
        $baseIsNumber = $match[1];
        $revisionNumber = intval($match[2]);
        $versionCount = intval($match[3]);
        Log::info("📝 IS number has revision format: R{$revisionNumber}/R{$versionCount}");
        return [
            'base_is_number' => $baseIsNumber,
            'revision_number' => $revisionNumber,
            'version_count' => $versionCount,
            'final_is_number' => $isNumber,
            'display_number' => $isNumber
        ];
    }
    
    // 2. Check subject for revision info
    if (preg_match('/R(\d+)\/R(\d+)/', $subject, $match)) {
        $revisionNumber = intval($match[1]);
        $versionCount = intval($match[2]);
        $finalIsNumber = $isNumber . '_R' . $revisionNumber . '/R' . $versionCount;
        Log::info("📝 Found revision in subject: R{$revisionNumber}/R{$versionCount}");
        return [
            'base_is_number' => $isNumber,
            'revision_number' => $revisionNumber,
            'version_count' => $versionCount,
            'final_is_number' => $finalIsNumber,
            'display_number' => $finalIsNumber
        ];
    }
    
    // 3. Check body for revision info
    if (preg_match('/Revised\s*(?:Version|Copy|Invoice)?\s*:?\s*R(\d+)\/R(\d+)/i', $plainText, $match)) {
        $revisionNumber = intval($match[1]);
        $versionCount = intval($match[2]);
        $finalIsNumber = $isNumber . '_R' . $revisionNumber . '/R' . $versionCount;
        Log::info("📝 Found revision in body: R{$revisionNumber}/R{$versionCount}");
        return [
            'base_is_number' => $isNumber,
            'revision_number' => $revisionNumber,
            'version_count' => $versionCount,
            'final_is_number' => $finalIsNumber,
            'display_number' => $finalIsNumber
        ];
    }
    
    // 4. Check if this is a revision based on existing records
    $existingRecords = PnlRecord::where('is_number', 'LIKE', $isNumber . '%')->get();
    
    if ($existingRecords->isNotEmpty()) {
        // Find the latest version
        $latestRevision = $this->getLatestRevisionInfo($isNumber);
        
        if ($latestRevision) {
            // This is a new revision - increment version count
            $newVersionCount = $latestRevision['version_count'] + 1;
            $revisionNumber = $latestRevision['revision_number'];
            $finalIsNumber = $isNumber . '_R' . $revisionNumber . '/R' . $newVersionCount;
            
            Log::info("📝 Auto-incrementing revision: R{$revisionNumber}/R{$newVersionCount}");
            return [
                'base_is_number' => $isNumber,
                'revision_number' => $revisionNumber,
                'version_count' => $newVersionCount,
                'final_is_number' => $finalIsNumber,
                'display_number' => $finalIsNumber
            ];
        }
    }
    
    // 5. Default: No revision
    return [
        'base_is_number' => $isNumber,
        'revision_number' => 0,
        'version_count' => 0,
        'final_is_number' => $isNumber,
        'display_number' => $isNumber
    ];
}

/**
 * Get the latest revision info for a base IS number
 */
private function getLatestRevisionInfo($baseIsNumber)
{
    // Find all records with this base IS number
    $records = PnlRecord::where('is_number', 'LIKE', $baseIsNumber . '%')
        ->orderBy('created_at', 'desc')
        ->get();
    
    $latestRevision = null;
    $maxVersionCount = 0;
    
    foreach ($records as $record) {
        // Check if it has revision format
        if (preg_match('/_R(\d+)\/R(\d+)$/', $record->is_number, $match)) {
            $revisionNumber = intval($match[1]);
            $versionCount = intval($match[2]);
            
            if ($versionCount > $maxVersionCount) {
                $maxVersionCount = $versionCount;
                $latestRevision = [
                    'revision_number' => $revisionNumber,
                    'version_count' => $versionCount,
                    'is_number' => $record->is_number
                ];
            }
        } else if ($record->is_number == $baseIsNumber) {
            // This is the original version
            $latestRevision = [
                'revision_number' => 1,
                'version_count' => 1,
                'is_number' => $baseIsNumber
            ];
        }
    }
    
    return $latestRevision;
}

/**
 * Check if this is a duplicate email (same IS number already exists)
 */
private function isDuplicateEmail($isNumber)
{
    return PnlRecord::where('is_number', $isNumber)->exists();
}
/**
 * Update all existing revisions with new version count
 */
private function updateAllRevisionsWithNewVersion($baseIsNumber, $newVersionCount)
{
    try {
        // Find all records with this base IS number
        $records = PnlRecord::where('is_number', 'LIKE', $baseIsNumber . '%')
            ->orWhere('original_is_number', $baseIsNumber)
            ->get();
        
        $updatedCount = 0;
        
        foreach ($records as $record) {
            // Check if it has revision format
            if (preg_match('/^' . preg_quote($baseIsNumber, '/') . '_R(\d+)\/R(\d+)$/', $record->is_number, $match)) {
                $revisionNumber = intval($match[1]);
                $oldVersionCount = intval($match[2]);
                
                // Only update if version count is less than new version
                if ($oldVersionCount < $newVersionCount) {
                    $newIsNumber = $baseIsNumber . '_R' . $revisionNumber . '/R' . $newVersionCount;
                    $record->update([
                        'is_number' => $newIsNumber,
                        'invoice_number' => $newIsNumber,
                        'version_count' => $newVersionCount,
                        'updated_at' => now(),
                    ]);
                    $updatedCount++;
                    Log::info("✅ Updated record: {$record->id} -> {$newIsNumber}");
                }
            }
        }
        
        Log::info("✅ Updated {$updatedCount} existing records to version /R{$newVersionCount}");
        return $updatedCount;
        
    } catch (\Exception $e) {
        Log::error("❌ Failed to update existing revisions: " . $e->getMessage());
        return 0;
    }
}

private function getNextRevisionNumber($baseIsNumber)
{
    $records = PnlRecord::where('is_number', 'LIKE', $baseIsNumber . '%')
        ->orWhere('original_is_number', $baseIsNumber)
        ->get();
    
    $maxRevision = 0;
    foreach ($records as $record) {
        if ($record->is_number == $baseIsNumber) {
            // ✅ Original version - revision should be 0 (will become 2 when incremented)
            continue;
        }
        if (preg_match('/_R(\d+)\/R(\d+)$/', $record->is_number, $match)) {
            $rev = intval($match[1]);
            if ($rev > $maxRevision) {
                $maxRevision = $rev;
            }
        }
    }
    
    // ✅ Fix: If no revisions exist, return 2 (first revision)
    // If revisions exist, return maxRevision + 1
    return $maxRevision == 0 ? 2 : $maxRevision + 1;
}

private function getNextVersionCount($baseIsNumber)
{
    $records = PnlRecord::where('is_number', 'LIKE', $baseIsNumber . '%')
        ->orWhere('original_is_number', $baseIsNumber)
        ->get();
    
    $maxVersionCount = 0;
    foreach ($records as $record) {
        if (preg_match('/_R\d+\/R(\d+)$/', $record->is_number, $match)) {
            $version = intval($match[1]);
            if ($version > $maxVersionCount) {
                $maxVersionCount = $version;
            }
        }
    }
    
    // ✅ Fix: First revision = 2, Second = 3, etc.
    return $maxVersionCount == 0 ? 2 : $maxVersionCount + 1;
}
}