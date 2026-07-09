<?php
// app/Services/OneDriveService.php

namespace App\Services;

use App\Models\OneDriveImport;
use App\Models\OneDriveProcessLog;
use App\Models\PnlRecord;
use App\Models\PnlItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\IOFactory;

class OneDriveService
{
    protected $accessToken;
    protected $userEmail;
    protected $baseUrl = 'https://graph.microsoft.com/v1.0';

    protected $countryDrives = [
        'MY' => 'Malaysia Drive',
        'SG' => 'Singapore Drive',
        'VN' => 'Vietnam Drive',
        'LK' => 'Sri Lanka Drive',
    ];

    protected $monthFolders = [
        '01' => '01 January',
        '02' => '02 February',
        '03' => '03 March',
        '04' => '04 April',
        '05' => '05 MAY',
        '06' => '06 June',
        '07' => '07 July',
        '08' => '08 Aug',
        '09' => '09 sep',
        '10' => '10 Oct',
        '11' => '11 November',
        '12' => '12 December',
    ];

    public function __construct()
    {
        $this->userEmail = env('ONEDRIVE_USER', 'accounts@aahaas.com');
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
                Log::info('OneDrive authenticated successfully for: ' . $this->userEmail);
                return true;
            }
            
            Log::error('OneDrive auth failed: ' . $response->body());
            return false;
            
        } catch (\Exception $e) {
            Log::error('OneDrive auth failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Switch user and re-authenticate
     */
    public function setUser($email)
    {
        $this->userEmail = $email;
        $this->authenticate();
        return $this;
    }


public function syncToStaging($country = 'MY', $month = null)
{
    $runId = uniqid('onedrive_');
    $year = date('Y');
    
    // ✅ Use PathFinder to get the base path
    $pathFinder = new OneDrivePathFinder();
    $result = $pathFinder->findPnLFolderWithUser($country);
    
    if (!$result || !isset($result['path'])) {
        Log::warning("⚠️ No folder found for country: {$country}");
        return [
            'success' => true,
            'message' => 'No folder found',
            'run_id' => $runId,
            'results' => ['found' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0]
        ];
    }
    
    // ✅ Get the YEAR path
    $yearPath = $result['path'];
    $foundUser = $result['user'] ?? $this->userEmail;
    
    Log::info("📁 Year Path: {$yearPath}");
    
    // ✅ SWITCH TO THE USER THAT HAS ACCESS
    if ($foundUser != $this->userEmail) {
        Log::info("🔄 Switching user from {$this->userEmail} to {$foundUser}");
        $this->setUser($foundUser);
    }
    
    // ✅ GET ALL MONTH FOLDERS
    $allMonthFolders = $this->getFolderContents($yearPath);
    
    // ✅ Month numbers to process (July = 07, Aug = 08, Sep = 09, Oct = 10, Nov = 11, Dec = 12)
    $monthsToProcess = [
        '07' => '07 July',
        '08' => '08 Aug', 
        '09' => '09 sep',
        '10' => '10 Oct',
        '11' => '11 November',
        '12' => '12 December'
    ];
    
    $results = [
        'found' => 0,
        'skipped' => 0,
        'processed' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    // Create process log
    $log = OneDriveProcessLog::create([
        'run_id' => $runId,
        'country_code' => $country,
        'month_year' => "{$year}-07-to-12",
        'started_at' => now(),
    ]);
    
    // ✅ PROCESS ONLY MONTHS FROM JULY ONWARDS
    foreach ($allMonthFolders as $monthFolder) {
        if (!($monthFolder['folder'] ?? false)) continue;
        
        $monthName = $monthFolder['name'];
        
        // ✅ CHECK IF THIS IS A MONTH WE WANT TO PROCESS (JULY - DECEMBER)
        $isValidMonth = false;
        $monthNumber = null;
        foreach ($monthsToProcess as $monthNum => $validMonth) {
            if (stripos($monthName, $validMonth) !== false) {
                $isValidMonth = true;
                $monthNumber = $monthNum;
                Log::info("✅ Valid month found: {$monthName} (matches: {$validMonth})");
                break;
            }
        }
        
        // ✅ SKIP Jan - June
        if (!$isValidMonth) {
            Log::info("⏭️ Skipping month (not in July-Dec): {$monthName}");
            continue;
        }
        
        Log::info("📁 Processing month folder: {$monthName}");
        
        $monthPath = "{$yearPath}/{$monthName}";
        
        // ✅ GET ALL DATE FOLDERS IN THIS MONTH
        $dateFolders = $this->getFolderContents($monthPath);
        
        foreach ($dateFolders as $dateFolder) {
            if (!($dateFolder['folder'] ?? false)) continue;
            
            $dateFolderName = $dateFolder['name'];
            
            // ✅ Extract day number from date folder name
            if (!preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $dateFolderName, $match)) {
                Log::info("⏭️ Skipping non-date folder: {$dateFolderName}");
                continue;
            }
            
            $day = intval($match[1]);
            
            // ✅ FIXED: Skip dates based on month
            // For July: Skip dates BEFORE 11th (1-10)
            // For August-December: Process ALL dates (1-31)
            $shouldSkip = false;
            if ($monthNumber == '07' && $day < 11) {
                $shouldSkip = true;
                Log::info("⏭️ Skipping date before 11th in July: {$dateFolderName} (Day: {$day})");
            } elseif ($monthNumber != '07' && $day < 1) {
                // This condition is always false for day >= 1
                $shouldSkip = false;
            }
            
            if ($shouldSkip) {
                continue;
            }
            
            // ✅ PROCESS ALL DATES
            $datePath = "{$monthPath}/{$dateFolderName}";
            Log::info("📁 Processing date folder: {$datePath} (Day: {$day}, Month: {$monthNumber})");
            
            // ✅ Get items inside this date folder (booking folders and files)
            $items = $this->getFolderContents($datePath);
            
            if (empty($items)) {
                Log::info("📭 No items in: {$dateFolderName}");
                continue;
            }
            
            Log::info("📊 Found " . count($items) . " items in {$dateFolderName}");
            
            // ✅ Process each item in the date folder
            foreach ($items as $item) {
                // If it's a folder (booking folder like "MY40031 - Saratha")
                if ($item['folder'] ?? false) {
                    $result = $this->processBookingFolder($item, $datePath, $country, $runId);
                    $results['found']++;
                    
                    if ($result['status'] === 'processed') {
                        $results['processed']++;
                    } elseif ($result['status'] === 'skipped') {
                        $results['skipped']++;
                    } else {
                        $results['failed']++;
                    }
                    
                    $results['details'][] = $result;
                }
                // If it's a file directly in date folder
                elseif ($item['file'] ?? false) {
                    $fileName = strtolower($item['name']);
                    if (strpos($fileName, 'tc') !== false && 
                        (strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false)) {
                        $result = $this->processDirectFile($item, $datePath, $country, $runId);
                        $results['found']++;
                        
                        if ($result['status'] === 'processed') {
                            $results['processed']++;
                        } elseif ($result['status'] === 'skipped') {
                            $results['skipped']++;
                        } else {
                            $results['failed']++;
                        }
                        
                        $results['details'][] = $result;
                    }
                }
            }
        }
    }
    
    $log->update([
        'total_found' => $results['found'],
        'total_skipped' => $results['skipped'],
        'total_processed' => $results['processed'],
        'total_failed' => $results['failed'],
        'details' => $results['details'],
        'completed_at' => now(),
    ]);
    
    return [
        'success' => true,
        'run_id' => $runId,
        'message' => "Found: {$results['found']}, Processed: {$results['processed']}, Skipped: {$results['skipped']}, Failed: {$results['failed']}",
        'results' => $results
    ];
}

/**
 * Manual fallback path finder
 */
protected function findPathManually($country = 'MY')
{
    $driveName = $this->countryDrives[$country] ?? 'Malaysia Drive';
    $year = date('Y');
    $month = date('m');
    $monthFolder = $this->monthFolders[$month] ?? '07 July';
    $today = (int)date('d');
    $startDate = max(11, $today);
    
    $basePaths = [
        "Reservation/{$driveName}/{$year}/{$monthFolder}",
        "Reservation/{$driveName}/{$year}",
        "Reservation/{$driveName}",
        "{$driveName}/{$year}/{$monthFolder}",
    ];
    
    foreach ($basePaths as $basePath) {
        $testItems = $this->getFolderContents($basePath);
        if (empty($testItems)) {
            continue;
        }
        
        // If we're at month level, find date folders >= 11
        if (strpos($basePath, $monthFolder) !== false) {
            for ($i = $startDate; $i >= 11; $i--) {
                $dateFolder = str_pad($i, 2, '0', STR_PAD_LEFT) . ' July';
                $path = "{$basePath}/{$dateFolder}";
                $items = $this->getFolderContents($path);
                if (!empty($items)) {
                    return $path;
                }
            }
        }
        
        // If we're at year level, find month and date
        if (strpos($basePath, $year) !== false) {
            $yearItems = $this->getFolderContents($basePath);
            foreach ($yearItems as $item) {
                if (($item['folder'] ?? false) && strpos($item['name'], 'July') !== false) {
                    $monthPath = "{$basePath}/{$item['name']}";
                    for ($i = $startDate; $i >= 11; $i--) {
                        $dateFolder = str_pad($i, 2, '0', STR_PAD_LEFT) . ' July';
                        $path = "{$monthPath}/{$dateFolder}";
                        $items = $this->getFolderContents($path);
                        if (!empty($items)) {
                            return $path;
                        }
                    }
                }
            }
        }
    }
    
    return null;
}
    /**
     * Process a booking folder (contains TC and PNL files)
     */
/**
 * Process a booking folder (contains TC and PNL files)
 */
protected function processBookingFolder($folder, $parentPath, $country, $runId)
{
    $folderName = $folder['name'];
    $folderPath = "{$parentPath}/{$folderName}";
    
    // ✅ Extract invoice number from folder name
    $invoiceNumber = $this->extractInvoiceNumber($folderName);
    
    if (!$invoiceNumber) {
        Log::warning("⚠️ Could not extract invoice number from: {$folderName}");
        return [
            'folder' => $folderName,
            'invoice_number' => null,
            'status' => 'failed',
            'reason' => 'Could not extract invoice number'
        ];
    }
    
    // ✅ Check if already exists in pnl_records
    $existingPnl = PnlRecord::where('invoice_number', $invoiceNumber)->first();
    if ($existingPnl) {
        Log::info("⏭️ Skipping - Already in PnL records: {$invoiceNumber}");
        return [
            'folder' => $folderName,
            'invoice_number' => $invoiceNumber,
            'status' => 'skipped',
            'reason' => 'Already in PnL records (ID: ' . $existingPnl->id . ')'
        ];
    }
    
    // ✅ Get files in folder
    $files = $this->getFolderContents($folderPath);
    
    $tcFile = null;
    $pnlFile = null;
    
    foreach ($files as $file) {
        if ($file['file'] ?? false) {
            $fileName = strtolower($file['name']);
            // ✅ Look for ANY file with 'tc' in name (case insensitive)
            if (strpos($fileName, 'tc') !== false && 
                (strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false)) {
                $tcFile = $file;
                Log::info("✅ Found TC file: {$file['name']} in {$folderName}");
            }
            // Look for PNL files too
            elseif (strpos($fileName, 'pnl') !== false && 
                    (strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false)) {
                $pnlFile = $file;
            }
        }
    }
    
    if (!$tcFile) {
        Log::warning("⚠️ No TC file found in: {$folderName}");
        return [
            'folder' => $folderName,
            'invoice_number' => $invoiceNumber,
            'status' => 'failed',
            'reason' => 'No TC file found (looking for any file with "tc" in name)'
        ];
    }
    
    return $this->processFileData($tcFile, $pnlFile, $folderPath, $folderName, $invoiceNumber, $country, $runId);
}

    /**
     * Process direct TC file (when files are directly in date folder)
     */
    protected function processDirectFile($file, $parentPath, $country, $runId)
    {
        $fileName = $file['name'];
        $filePath = "{$parentPath}/{$fileName}";
        
        // Try to extract invoice number from filename or parent folder
        $invoiceNumber = $this->extractInvoiceNumber($fileName);
        
        if (!$invoiceNumber) {
            // Try parent folder name
            $parentParts = explode('/', $parentPath);
            $folderName = end($parentParts);
            $invoiceNumber = $this->extractInvoiceNumber($folderName);
        }
        
        if (!$invoiceNumber) {
            return [
                'folder' => $fileName,
                'invoice_number' => null,
                'status' => 'failed',
                'reason' => 'Could not extract invoice number'
            ];
        }
        
        // Check if already exists
        $existingPnl = PnlRecord::where('invoice_number', $invoiceNumber)->first();
        if ($existingPnl) {
            return [
                'folder' => $fileName,
                'invoice_number' => $invoiceNumber,
                'status' => 'skipped',
                'reason' => 'Already in PnL records (ID: ' . $existingPnl->id . ')'
            ];
        }
        
        $folderName = $invoiceNumber . '- OneDrive';
        
        return $this->processFileData($file, null, $parentPath, $folderName, $invoiceNumber, $country, $runId);
    }

    /**
     * Process file data and create staging record
     */
/**
 * Process file data and create staging record
 */
protected function processFileData($tcFile, $pnlFile, $filePath, $folderName, $invoiceNumber, $country, $runId)
{
    try {
        // Download TC content
        $tcContent = $this->downloadAndReadDocx($filePath, $tcFile['name']);
        
        if (!$tcContent) {
            return [
                'folder' => $folderName,
                'invoice_number' => $invoiceNumber,
                'status' => 'failed',
                'reason' => 'Failed to read TC file'
            ];
        }
        
        // ✅ Extract the actual date folder from the file path
        $dateFolder = $this->extractDateFolder($filePath);
        $monthFolder = $this->extractMonthFolder($filePath);
        
        // ✅ Extract data using OpenAI - gets tour_ref, agent_name, arrival_date, departure_date
        $extractedData = $this->extractWithOpenAI($tcContent, $folderName, $invoiceNumber);
        
        // ✅ Log what was extracted
        Log::info("📊 Extracted Data for {$invoiceNumber}:");
        Log::info("  - Tour Ref: " . ($extractedData['tour_ref'] ?? 'NULL'));
        Log::info("  - Agent: " . ($extractedData['agent_name'] ?? 'NULL'));
        Log::info("  - Arrival: " . ($extractedData['arrival_date'] ?? 'NULL'));
        Log::info("  - Departure: " . ($extractedData['departure_date'] ?? 'NULL'));
        
        // ✅ Save to staging with tour_ref
        $import = OneDriveImport::create([
            'folder_name' => $folderName,
            'invoice_number' => $invoiceNumber,
            'tour_ref' => $extractedData['tour_ref'] ?? null,  // ✅ THIS IS THE KEY
            'country_code' => $country,
            'month_folder' => $monthFolder ?? $this->monthFolders[date('m')],
            'date_folder' => $dateFolder ?? date('d') . ' July',
            'tc_file_content' => $tcContent,
            'tc_file_path' => $tcFile['name'],
            'pnl_file_path' => $pnlFile ? $pnlFile['name'] : null,
            'extracted_data' => $extractedData,
            'status' => 'pending',
            'processed_at' => null,
        ]);
        
        Log::info("✅ Saved to OneDriveImport with tour_ref: " . ($extractedData['tour_ref'] ?? 'NULL'));
        
        // Process immediately
        $this->processStagingRecord($import->id);
        
        return [
            'folder' => $folderName,
            'invoice_number' => $invoiceNumber,
            'tour_ref' => $extractedData['tour_ref'] ?? null,
            'status' => 'processed',
            'import_id' => $import->id,
            'reason' => 'Saved to staging and processed'
        ];
        
    } catch (\Exception $e) {
        Log::error("Error processing: " . $e->getMessage());
        
        OneDriveImport::create([
            'folder_name' => $folderName,
            'invoice_number' => $invoiceNumber,
            'country_code' => $country,
            'month_folder' => $this->monthFolders[date('m')],
            'date_folder' => date('d') . ' July',
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'processed_at' => now(),
        ]);
        
        return [
            'folder' => $folderName,
            'invoice_number' => $invoiceNumber,
            'status' => 'failed',
            'reason' => $e->getMessage()
        ];
    }
}

/**
 * ✅ Extract date folder from path
 * Example: "Reservation/Malaysia Drive/2026/07 July/11 July" → "11 July"
 */
protected function extractDateFolder($path)
{
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if (preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $part)) {
            return $part;
        }
    }
    return null;
}

/**
 * ✅ Extract month folder from path
 * Example: "Reservation/Malaysia Drive/2026/07 July/11 July" → "07 July"
 */
protected function extractMonthFolder($path)
{
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if (preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $part)) {
            // Check if it's a month (01-12)
            $monthNum = intval($part);
            if ($monthNum >= 1 && $monthNum <= 12) {
                return $part;
            }
        }
    }
    return null;
}

    /**
     * Process a staging record and create P&L
     */
    public function processStagingRecord($importId)
    {
        $import = OneDriveImport::find($importId);
        
        if (!$import) {
            Log::error("Import record not found: {$importId}");
            return false;
        }
        
        if ($import->status != 'pending') {
            Log::info("Import already processed: {$importId} (Status: {$import->status})");
            return false;
        }
        
        try {
            $import->update(['status' => 'processing']);
            
            $data = $import->extracted_data;
            
            // Check again if P&L exists (double-check)
            $existingPnl = PnlRecord::where('invoice_number', $import->invoice_number)->first();
            
            if ($existingPnl) {
                $import->update([
                    'status' => 'skipped',
                    'skip_reason' => 'Already in PnL records (ID: ' . $existingPnl->id . ')',
                    'processed_at' => now(),
                ]);
                return true;
            }
            
            // Create P&L Record
            $record = $this->createPnLRecord($data, $import);
            
            if (!$record) {
                $import->update([
                    'status' => 'failed',
                    'error_message' => 'Failed to create P&L record',
                    'processed_at' => now(),
                ]);
                return false;
            }
            
            // Create P&L Items
            $this->createPnLItems($data, $record);
            
            // Update import status
            $import->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
            
            Log::info("✅ Successfully processed import: {$importId} -> P&L Record: {$record->id}");
            
            // Generate invoice if possible
            $this->generateInvoiceFromRecord($record);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to process staging record: " . $e->getMessage());
            
            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
            
            return false;
        }
    }

    /**
     * Process all pending staging records
     */
    public function processPendingStaging()
    {
        $pending = OneDriveImport::where('status', 'pending')->get();
        
        $results = [
            'total' => $pending->count(),
            'processed' => 0,
            'failed' => 0,
        ];
        
        foreach ($pending as $import) {
            $success = $this->processStagingRecord($import->id);
            
            if ($success) {
                $results['processed']++;
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }

    /**
     * Extract invoice number from folder name
     */
/**
 * Extract invoice number from folder name - FIXED for lowercase
 */
protected function extractInvoiceNumber($folderName)
{
    // Pattern: "my23122- madhu" → "MY23122" (case-insensitive)
    if (preg_match('/^([A-Z]{2})\s+(\d+)/i', $folderName, $match)) {
        return strtoupper($match[1]) . $match[2];
    }
    
    // Pattern: "MY40018- Saratha" → "MY40018"
    if (preg_match('/^([A-Z]{2})(\d+)/i', $folderName, $match)) {
        return strtoupper($match[1]) . $match[2];
    }
    
    // Pattern: "MY40018" → "MY40018"
    if (preg_match('/^([A-Z]{2})(\d{5,})/i', $folderName, $match)) {
        return strtoupper($match[1]) . $match[2];
    }
    
    return null;
}

/**
 * Download and read DOCX file - IMPROVED
 */
protected function downloadAndReadDocx($folderPath, $fileName)
{
    try {
        $remotePath = "{$folderPath}/{$fileName}";
        $url = $this->baseUrl . "/users/{$this->userEmail}/drive/root:/{$remotePath}:/content";
        
        $response = Http::withToken($this->accessToken)
            ->timeout(60)
            ->get($url);
        
        if (!$response->ok()) {
            Log::error("Failed to download file: {$remotePath}");
            return null;
        }
        
        $tempFile = tempnam(sys_get_temp_dir(), 'tc_') . '.docx';
        file_put_contents($tempFile, $response->body());
        
        // Read DOCX
        $phpWord = IOFactory::load($tempFile);
        $text = '';
        
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText() . ' ';
                        }
                    }
                } elseif (method_exists($element, 'getText')) {
                    $text .= $element->getText() . ' ';
                }
            }
        }
        
        @unlink($tempFile);
        
        // ✅ CLEAN THE CONTENT - Remove special characters
        $text = preg_replace('/[^\x20-\x7E]/', ' ', $text); // Remove non-ASCII
        $text = preg_replace('/\s+/', ' ', $text); // Remove extra spaces
        $text = trim($text);
        
        Log::info("📄 Cleaned TC content length: " . strlen($text));
        Log::info("📄 First 500 chars: " . substr($text, 0, 500));
        
        return $text;
        
    } catch (\Exception $e) {
        Log::error("Error reading DOCX: " . $e->getMessage());
        return null;
    }
}


protected function extractWithOpenAI($content, $folderName, $invoiceNumber)
{
    try {
        $openAI = app(\App\Services\OpenAIService::class);
        
        // Try OpenAI first
        $result = $openAI->extractTCData($content, $invoiceNumber, $folderName);
        
        if ($result['success'] && !empty($result['data'])) {
            $data = $result['data'];
            
            // ✅ If OpenAI returns null for everything, try again with a different prompt
            if (empty($data['tour_ref']) && empty($data['agent_name']) && empty($data['arrival_date'])) {
                Log::warning("⚠️ OpenAI returned empty, trying again with more explicit prompt...");
                
                // Try a second time with more explicit instruction
                $result2 = $openAI->extractTCDataWithMoreContext($content, $invoiceNumber, $folderName);
                if ($result2['success'] && !empty($result2['data'])) {
                    $data = $result2['data'];
                }
            }
            
            // ✅ Always set these
            $data['invoice_number'] = $invoiceNumber;
            $data['folder_name'] = $folderName;
            
            Log::info("📊 Final extracted data: " . json_encode($data));
            return $data;
        }
        
        Log::warning("⚠️ OpenAI extraction failed for: {$invoiceNumber}");
        
    } catch (\Exception $e) {
        Log::error("OpenAI extraction failed: " . $e->getMessage());
    }
    
    // ✅ Return with null values but keep invoice number
    return [
        'tour_ref' => null,
        'agent_name' => null,
        'arrival_date' => null,
        'departure_date' => null,
        'invoice_number' => $invoiceNumber,
        'folder_name' => $folderName
    ];
}


    /**
     * Fallback: Extract data using regex
     */
    protected function extractWithRegex($content, $folderName, $invoiceNumber)
    {
        $data = [
            'invoice_number' => $invoiceNumber,
            'folder_name' => $folderName,
            'tour_ref' => null,
            'agent_name' => null,
            'guest_name' => null,
            'travel_start_date' => null,
            'travel_end_date' => null,
            'total_amount' => 0,
            'currency' => 'MYR',
            'pax_count' => 1,
            'file_handler' => null,
            'sales_person' => null,
            'hotel_name' => null,
            'room_type' => null,
            'meal_plan' => null,
            'nights' => null,
        ];
        
        // Tour Ref
        if (preg_match('/Tour\s+Ref\s*[:]\s*([A-Z0-9]+)/i', $content, $match)) {
            $data['tour_ref'] = trim($match[1]);
        }
        
        // Agent
        if (preg_match('/Agent\s*[:]\s*([^\n]+)/i', $content, $match)) {
            $data['agent_name'] = trim($match[1]);
        }
        
        // Guest Name
        if (preg_match('/Guests?\s+Name\s*[:]\s*([^\n]+)/i', $content, $match)) {
            $data['guest_name'] = trim($match[1]);
        }
        
        // Travel Start Date
        if (preg_match('/Arrival\s*Date\s*[:]\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $content, $match)) {
            $data['travel_start_date'] = trim($match[1]);
        }
        
        // Travel End Date
        if (preg_match('/Departure\s*Date\s*[:]\s*(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/i', $content, $match)) {
            $data['travel_end_date'] = trim($match[1]);
        }
        
        // Total Amount
        if (preg_match('/Total\s*Tour\s*Cost\s*[:]\s*(RM|MYR|USD|SGD)\s*([0-9,]+(?:\.[0-9]{1,2})?)/i', $content, $match)) {
            $data['currency'] = strtoupper(trim($match[1]));
            $data['total_amount'] = floatval(str_replace(',', '', $match[2]));
        }
        
        // Pax Count
        if (preg_match('/No\.?\s*of\s*Guests?\s*[:]\s*(\d+)/i', $content, $match)) {
            $data['pax_count'] = intval($match[1]);
        } elseif (preg_match('/(\d+)\s+Adults?/i', $content, $match)) {
            $data['pax_count'] = intval($match[1]);
        }
        
        // File Handler
        if (preg_match('/File\s+Handler\s*[:]\s*([^\n]+)/i', $content, $match)) {
            $data['file_handler'] = trim($match[1]);
        }
        
        // Sales Person
        if (preg_match('/Sales\s+Person\s*[:]\s*([^\n]+)/i', $content, $match)) {
            $data['sales_person'] = trim($match[1]);
        }
        
        // Hotel
        if (preg_match('/Hotel\s*[:]\s*([^\n]+)/i', $content, $match)) {
            $data['hotel_name'] = trim($match[1]);
        }
        
        // Nights
        if (preg_match('/Nights?\s*[:]\s*(\d+)/i', $content, $match)) {
            $data['nights'] = intval($match[1]);
        }
        
        // Meal Plan
        if (preg_match('/Meal\s+Plan\s*[:]\s*([^\n]+)/i', $content, $match)) {
            $data['meal_plan'] = trim($match[1]);
        }
        
        return $data;
    }

    /**
     * Create P&L Record
     */
    protected function createPnLRecord($data, $import)
    {
        try {
            $record = PnlRecord::create([
                'invoice_number' => $import->invoice_number,
                'tour_ref' => $data['tour_ref'] ?? 'NA',
                'agent_name' => $data['agent_name'] ?? null,
                'guest_name' => $data['guest_name'] ?? null,
                'vendor_name' => $data['agent_name'] ?? null,
                'travel_start_date' => $this->normalizeDate($data['travel_start_date'] ?? null),
                'travel_end_date' => $this->normalizeDate($data['travel_end_date'] ?? null),
                'total_amount' => $data['total_amount'] ?? 0,
                'currency' => $data['currency'] ?? 'MYR',
                'pax_count' => $data['pax_count'] ?? 1,
                'file_handler' => $data['file_handler'] ?? null,
                'sales_person' => $data['sales_person'] ?? null,
                'country_code' => $import->country_code,
                'category' => 'Tour Package',
                'status' => 'pending',
                'processing_status' => 'processing',
                'source' => 'onedrive',
                'folder_name' => $import->folder_name,
                'hotel_name' => $data['hotel_name'] ?? null,
                'meal_plan' => $data['meal_plan'] ?? null,
                'nights' => $data['nights'] ?? null,
                'staging_import_id' => $import->id,
            ]);
            
            Log::info("✅ Created P&L record: {$import->invoice_number} (ID: {$record->id})");
            return $record;
            
        } catch (\Exception $e) {
            Log::error("Failed to create P&L record: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create P&L Items
     */
    protected function createPnLItems($data, $record)
    {
        try {
            if (!empty($data['hotel_name'])) {
                PnlItem::create([
                    'pnl_record_id' => $record->id,
                    'type' => 'HOTEL',
                    'service_name' => $data['hotel_name'],
                    'amount_original' => $record->total_amount,
                    'currency' => $record->currency,
                    'start_date' => $record->travel_start_date,
                    'end_date' => $record->travel_end_date,
                    'nights' => $data['nights'] ?? null,
                    'item_details' => json_encode([
                        'meal_plan' => $data['meal_plan'] ?? null,
                        'room_type' => $data['room_type'] ?? null,
                        'pax' => $record->pax_count,
                    ])
                ]);
            }
            
            Log::info("✅ Created P&L items for record: {$record->id}");
            
        } catch (\Exception $e) {
            Log::error("Failed to create P&L items: " . $e->getMessage());
        }
    }

    /**
     * Normalize date format
     */
    protected function normalizeDate($date)
    {
        if (!$date) return null;
        
        try {
            if (strpos($date, '/') !== false) {
                $date = str_replace('/', '-', $date);
            }
            return date('Y-m-d', strtotime($date));
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Get folder contents from OneDrive
     */
    public function getFolderContents($path)
    {
        try {
            $encodedPath = str_replace(' ', '%20', $path);
            $url = $this->baseUrl . "/users/{$this->userEmail}/drive/root:/{$encodedPath}:/children";
            
            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->get($url);
            
            if ($response->ok()) {
                $data = $response->json();
                return $data['value'] ?? [];
            }
            
            Log::warning("Failed to get folder contents: {$path}", ['error' => $response->body()]);
            return [];
            
        } catch (\Exception $e) {
            Log::error("Error getting folder contents: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate invoice from record
     */
    protected function generateInvoiceFromRecord($record)
    {
        try {
            if ($record->invoice_number) {
                $existing = \App\Models\GeneratedInvoice::where('invoice_number', $record->invoice_number)->first();
                if ($existing) {
                    Log::info("⏭️ Invoice already exists: {$record->invoice_number}");
                    return;
                }
            }
            
            $email = \App\Models\IncomingEmail::where('invoice_number', $record->invoice_number)->first();
            
            if (!$email) {
                Log::info("ℹ️ No email found for invoice: {$record->invoice_number}");
                return;
            }
            
            $invoiceService = app(\App\Services\InvoiceGenerationService::class);
            $invoice = $invoiceService->generateFromEmail($email);
            
            if ($invoice) {
                Log::info("✅ Generated invoice: {$invoice->invoice_number} from OneDrive record");
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to generate invoice: " . $e->getMessage());
        }
    }
}