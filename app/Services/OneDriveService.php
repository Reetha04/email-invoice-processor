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
use App\Models\GeneratedInvoice;  

class OneDriveService
{
    public $accessToken;
    protected $userEmail;
    protected $baseUrl = 'https://graph.microsoft.com/v1.0';
  protected $sriLankaDriveId = 'b!50OxHDBzR0OL6moo_OLbEPPv-pKecbJNtUhLzvZUuX6Y6XRiW_09So2E3yephyiW';
    protected $countryDrives = [
        'MY' => 'Malaysia Drive',
        'SG' => 'Singapore Drive',
        'VN' => 'VN OPERATION',
        'LK' => 'SL Share Drive_',
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
    
    // ✅ For Sri Lanka, get month folders
    if ($country === 'LK') {
        $allMonthFolders = $pathFinder->getFolderContents($yearPath);
        
        if (empty($allMonthFolders)) {
            Log::warning("⚠️ No month folders found for Sri Lanka: {$yearPath}");
            return [
                'success' => true,
                'message' => 'No month folders found',
                'run_id' => $runId,
                'results' => ['found' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0]
            ];
        }
        
        // ✅ SORT MONTHS - July to December (7 to 12)
        $monthOrder = [
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 
            'Oct' => 10, 'Nov' => 11, 'Dec' => 12
        ];
        
        usort($allMonthFolders, function($a, $b) use ($monthOrder) {
            $monthA = 99;
            $monthB = 99;
            
            foreach ($monthOrder as $name => $num) {
                if (stripos($a['name'], $name) !== false) {
                    $monthA = $num;
                }
                if (stripos($b['name'], $name) !== false) {
                    $monthB = $num;
                }
            }
            
            return $monthA - $monthB;
        });
        
        Log::info("📅 Months sorted: " . implode(', ', array_column($allMonthFolders, 'name')));
    } else {
        // ✅ For other countries, get month folders from the year path
        $allMonthFolders = $this->getFolderContents($yearPath);
    }
    
    // ✅ Get country-specific month mapping
    $monthMap = $this->monthFolders;
    
    // ✅ DYNAMIC: Determine which months to process based on country and year
    $currentYear = date('Y');
    $currentMonth = date('m');
    $currentDay = date('d');
    
    $monthsToProcess = [];
    
    // Check if the yearPath contains a specific year
    $pathYear = null;
    if (preg_match('/\/(\d{4})$/', $yearPath, $match)) {
        $pathYear = $match[1];
    }
    
    // If it's a future year (e.g., 2027), process ALL months
    if ($pathYear && $pathYear > $currentYear) {
        Log::info("📅 Future year detected: {$pathYear} - Processing ALL months");
        $monthsToProcess = [
            '01' => $monthMap['01'] ?? '01 January',
            '02' => $monthMap['02'] ?? '02 February',
            '03' => $monthMap['03'] ?? '03 March',
            '04' => $monthMap['04'] ?? '04 April',
            '05' => $monthMap['05'] ?? '05 MAY',
            '06' => $monthMap['06'] ?? '06 June',
            '07' => $monthMap['07'] ?? '07 July',
            '08' => $monthMap['08'] ?? '08 Aug',
            '09' => $monthMap['09'] ?? '09 Sep',
            '10' => $monthMap['10'] ?? '10 Oct',
            '11' => $monthMap['11'] ?? '11 November',
            '12' => $monthMap['12'] ?? '12 December',
        ];
    }
    // ✅ For VIETNAM (VN) - Process July to December with dates >= 11
    elseif ($country === 'VN') {
        Log::info("📅 Vietnam: Processing July to December (from July 11 onwards)");
        $monthsToProcess = [
            '07' => $monthMap['07'] ?? '07 July',
            '08' => $monthMap['08'] ?? '08 Aug',
            '09' => $monthMap['09'] ?? '09 Sep',
            '10' => $monthMap['10'] ?? '10 Oct',
            '11' => $monthMap['11'] ?? '11 November',
            '12' => $monthMap['12'] ?? '12 December',
        ];
    }
    // For current year, process July to December (for MY, SG)
    elseif ($country === 'MY' || $country === 'SG') {
        Log::info("📅 Current year: {$pathYear} - Processing July to December (for {$country})");
        $monthsToProcess = [
            '07' => $monthMap['07'] ?? '07 July',
            '08' => $monthMap['08'] ?? '08 Aug',
            '09' => $monthMap['09'] ?? '09 Sep',
            '10' => $monthMap['10'] ?? '10 Oct',
            '11' => $monthMap['11'] ?? '11 November',
            '12' => $monthMap['12'] ?? '12 December',
        ];
    }
    // For other countries (if any), process ALL months
    else {
        Log::info("📅 Current year: {$pathYear} - Processing ALL months (for {$country})");
        $monthsToProcess = [
            '01' => $monthMap['01'] ?? '01 January',
            '02' => $monthMap['02'] ?? '02 February',
            '03' => $monthMap['03'] ?? '03 March',
            '04' => $monthMap['04'] ?? '04 April',
            '05' => $monthMap['05'] ?? '05 MAY',
            '06' => $monthMap['06'] ?? '06 June',
            '07' => $monthMap['07'] ?? '07 July',
            '08' => $monthMap['08'] ?? '08 Aug',
            '09' => $monthMap['09'] ?? '09 Sep',
            '10' => $monthMap['10'] ?? '10 Oct',
            '11' => $monthMap['11'] ?? '11 November',
            '12' => $monthMap['12'] ?? '12 December',
        ];
    }
    
    $results = [
        'found' => 0,
        'skipped' => 0,
        'processed' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    // ✅ Generate month_year based on what's being processed
    $monthRange = implode('-', array_keys($monthsToProcess));
    if (count($monthsToProcess) == 12) {
        $monthRange = 'all';
    } elseif (count($monthsToProcess) == 6) {
        $monthRange = '07-to-12';
    }
    
    $log = OneDriveProcessLog::create([
        'run_id' => $runId,
        'country_code' => $country,
        'month_year' => "{$year}-{$monthRange}",
        'started_at' => now(),
    ]);
    
    // ✅ PROCESS MONTHS
    foreach ($allMonthFolders as $monthFolder) {
        if (!($monthFolder['folder'] ?? false)) continue;
        
        $monthName = $monthFolder['name'];
        
        // ✅ CHECK IF THIS MONTH IS IN OUR PROCESSING LIST
        $isValidMonth = false;
        $monthNumber = null;
        
        foreach ($monthsToProcess as $monthNum => $validMonth) {
            if (stripos($validMonth, $monthName) !== false) {
                $isValidMonth = true;
                $monthNumber = $monthNum;
                Log::info("✅ Valid month found: {$monthName} (matches: {$validMonth})");
                break;
            }
        }
        
        if (!$isValidMonth) {
            Log::info("⏭️ Skipping month (not in processing list): {$monthName}");
            continue;
        }
        
        Log::info("📁 Processing month folder: {$monthName}");
        
        $monthPath = "{$yearPath}/{$monthName}";
        
        // ✅ GET ALL DATE FOLDERS IN THIS MONTH
        $dateFolders = $this->getFolderContents($monthPath);
        
        // ✅ Sort date folders by day number
        usort($dateFolders, function($a, $b) {
            preg_match('/^(\d{2})/', $a['name'], $matchA);
            preg_match('/^(\d{2})/', $b['name'], $matchB);
            
            $dayA = intval($matchA[1] ?? 0);
            $dayB = intval($matchB[1] ?? 0);
            
            return $dayA - $dayB;
        });
        
        foreach ($dateFolders as $dateFolder) {
            if (!($dateFolder['folder'] ?? false)) continue;
            
            $dateFolderName = $dateFolder['name'];
            
            // ✅ Extract day number from date folder name
            if (!preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $dateFolderName, $match)) {
                Log::info("⏭️ Skipping non-date folder: {$dateFolderName}");
                continue;
            }
            
            $day = intval($match[1]);
            
            // ✅ SKIP LOGIC: Check if we should skip this date
            
            // ✅ For Sri Lanka (LK) - Skip dates before 11th in July
            $shouldSkip = false;
            if ($country === 'LK') {
                if ($monthNumber == '07' && $day < 11) {
                    $shouldSkip = true;
                    Log::info("⏭️ LK: Skipping date before 11th in July: {$dateFolderName} (Day: {$day})");
                }
            }
            
            // ✅ For Vietnam (VN) - Skip dates BEFORE July 11, but process from July 11 onwards
            if ($country === 'VN') {
                // Only apply skip for July month
                if ($monthNumber == '07' && $day < 11) {
                    $shouldSkip = true;
                    Log::info("⏭️ VN: Skipping date before 11th in July: {$dateFolderName} (Day: {$day})");
                } else {
                    // ✅ For August, September, October, November, December - Process ALL dates
                    Log::info("✅ VN: Processing date {$dateFolderName} (Month: {$monthNumber}, Day: {$day})");
                }
            }
            
            // ✅ For MY and SG - Skip dates before 11th in July
            if ($country === 'MY' || $country === 'SG') {
                if ($monthNumber == '07' && $day < 11) {
                    $shouldSkip = true;
                    Log::info("⏭️ {$country}: Skipping date before 11th in July: {$dateFolderName} (Day: {$day})");
                }
            }
            
            if ($shouldSkip) {
                continue;
            }
            
            // ✅ PROCESS ALL DATES FROM JULY 11 ONWARDS
            $datePath = "{$monthPath}/{$dateFolderName}";
            Log::info("📁 Processing date folder: {$datePath} (Day: {$day}, Month: {$monthNumber})");
            
            // ✅ Get items inside this date folder
            $items = $this->getFolderContents($datePath);
            
            if (empty($items)) {
                Log::info("📭 No items in: {$dateFolderName}");
                continue;
            }
            
            Log::info("📊 Found " . count($items) . " items in {$dateFolderName}");
            
            // ✅ Process each item in the date folder
            foreach ($items as $item) {
                // If it's a folder (booking folder)
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
                    
                    // ✅ For LK: accept any .docx/.doc file; for others, require 'tc' in name
                    $isValidFile = false;
                    if ($country === 'LK') {
                        $isValidFile = (strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false);
                    } else {
                        $isValidFile = (strpos($fileName, 'tc') !== false && 
                                        (strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false));
                    }
                    
                    if ($isValidFile) {
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

public function findPnLFolderWithUser($country = 'MY')
{
    try {
        $driveNames = [
            'MY' => 'Malaysia Drive',
            'SG' => 'Singapore Drive',
            'VN' => 'VN OPERATION',
            'LK' => 'Sri Lanka Drive',
        ];
        $driveName = $driveNames[$country] ?? 'Malaysia Drive';
        $year = date('Y');
        
        // ✅ Try direct path first
        $directPath = "Reservation/{$driveName}/{$year}";
        
        $geethaEmails = [
            'geetha.lakshmi@aahaas.com',
            'geetha_lakshmi@aahaas.com',
        ];
        
        foreach ($geethaEmails as $email) {
            Log::info("🔍 Trying with user: {$email} at path: {$directPath}");
            $this->setUser($email);
            
            // ✅ Use a quick check instead of full listing
            $items = $this->getFolderContents($directPath);
            if (!empty($items)) {
                Log::info("✅ Found year path with user: {$email}: {$directPath}");
                return [
                    'path' => $directPath,
                    'user' => $email
                ];
            }
        }
        
        // ✅ Try without Reservation prefix
        $fallbackPath = "{$driveName}/{$year}";
        Log::info("🔍 Trying fallback path: {$fallbackPath}");
        
        $this->setUser(env('ONEDRIVE_USER', 'accounts@aahaas.com'));
        $items = $this->getFolderContents($fallbackPath);
        if (!empty($items)) {
            Log::info("✅ Found year path: {$fallbackPath}");
            return [
                'path' => $fallbackPath,
                'user' => $this->userEmail
            ];
        }
        
        Log::warning("⚠️ No folder found for country: {$country}");
        return null;
        
    } catch (\Exception $e) {
        Log::error("Path finder error: " . $e->getMessage());
        return null;
    }
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
protected function processBookingFolder($folder, $parentPath, $country, $runId)
{
    $folderName = $folder['name'];
    $folderPath = "{$parentPath}/{$folderName}";

    $invoiceNumber = $this->extractInvoiceNumber($folderName);
    if (!$invoiceNumber) {
        return ['folder' => $folderName, 'invoice_number' => null, 'status' => 'failed', 'reason' => 'Could not extract invoice number'];
    }

    // Check if already exists
    $existingPnl = PnlRecord::where('invoice_number', $invoiceNumber)->first();
    if ($existingPnl) {
        return ['folder' => $folderName, 'invoice_number' => $invoiceNumber, 'status' => 'skipped', 'reason' => 'Already in PnL records'];
    }

    $files = $this->getFolderContents($folderPath);

    $tcFile = null;
    $pnlFile = null;

    foreach ($files as $file) {
        if (!($file['file'] ?? false)) continue;
        $fileName = strtolower($file['name']);

        // TC file: contains 'tc' in name or is .docx and not PNL
        if ((strpos($fileName, 'tc') !== false || $country === 'LK') && 
            (strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false)) {
            // Check if it's NOT a PNL file (PNL files often have 'pnl' or 'P&L')
            if (strpos($fileName, 'pnl') === false && strpos($fileName, 'p&l') === false) {
                $tcFile = $file;
                Log::info("✅ Found TC file: {$file['name']}");
            }
        }

        // PNL file: contains 'pnl' or 'P&L' or is a PDF with IS number
        if (strpos($fileName, 'pnl') !== false || strpos($fileName, 'p&l') !== false || 
            (strpos($fileName, '.pdf') !== false && preg_match('/IS\d+/i', $fileName))) {
            $pnlFile = $file;
            Log::info("✅ Found PNL file: {$file['name']}");
        }
    }

    // If no TC file found, fallback to any .docx (for LK)
    if (!$tcFile && $country === 'LK') {
        foreach ($files as $file) {
            if ($file['file'] ?? false) {
                $fileName = strtolower($file['name']);
                if ((strpos($fileName, '.docx') !== false || strpos($fileName, '.doc') !== false) &&
                    strpos($fileName, 'pnl') === false && strpos($fileName, 'p&l') === false) {
                    $tcFile = $file;
                    Log::info("✅ Found TC file (fallback): {$file['name']}");
                    break;
                }
            }
        }
    }

    if (!$tcFile && !$pnlFile) {
        Log::warning("⚠️ No TC or PNL file found in: {$folderName}");
        return ['folder' => $folderName, 'invoice_number' => $invoiceNumber, 'status' => 'failed', 'reason' => 'No TC or PNL file found'];
    }

    // Process TC file first (for invoice record)
    $tcResult = null;
    if ($tcFile) {
        $tcResult = $this->processFileData($tcFile, null, $folderPath, $folderName, $invoiceNumber, $country, $runId);
        // This creates the PnL record and also processes items from TC (if no PNL file exists)
    }

    // If PNL file exists, we will process it separately to extract items
    if ($pnlFile) {
        // We need the record ID from the TC processing (if TC was processed)
        $record = null;
        if ($tcResult && isset($tcResult['record_id'])) {
            $record = PnlRecord::find($tcResult['record_id']);
        } else {
            // If TC didn't create record, we may need to create one from PNL file
            // but usually TC is the main source for header data.
            // We'll handle it by creating a minimal record from PNL data if TC fails.
            $record = $this->createPnlRecordFromPNL($pnlFile, $folderPath, $folderName, $invoiceNumber, $country, $runId);
        }

        if ($record) {
            // Parse PNL file and insert items
            $this->processPnlFile($pnlFile, $folderPath, $record, $country);
        }
    }

    // Return result from TC processing (or a combined result)
    return $tcResult ?? ['folder' => $folderName, 'invoice_number' => $invoiceNumber, 'status' => 'processed', 'reason' => 'PNL processed'];
}
protected function createPnlRecordFromPNL($pnlFile, $folderPath, $folderName, $invoiceNumber, $country, $runId)
{
    // Download and read PNL file to get basic data
    $pnlContent = $this->downloadAndReadPnlFile($folderPath, $pnlFile['name'], $country);
    if (!$pnlContent) return null;
    
    $text = $pnlContent['text'];
    
    // Extract basic fields from PNL
    $agentName = $this->extractAgentFromPNL($text);
    $guestName = $this->extractGuestFromPNL($text);
    $totalAmount = $this->extractTotalAmountFromPNL($text);
    $pax = $this->extractPaxFromPNL($text);
    $nights = $this->extractNightsFromPNL($text);
     $agentName = $data['agent_name'] ?? null;
        if (empty($agentName)) {
            // Try to get from folder name
            $agentName = $this->extractFileHandlerFromFolderName($import->folder_name);
        }
        if (empty($agentName)) {
            $agentName = 'Unknown Agent'; // ✅ DEFAULT VALUE
        }
        
        $guestName = $data['guest_name'] ?? null;
        if (empty($guestName)) {
            $guestName = 'Unknown Guest'; // ✅ DEFAULT VALUE
        }
    // Create record
    $record = PnlRecord::create([
        'invoice_number' => $invoiceNumber,
        'tour_ref' => $this->extractTourRefFromPNL($text),
        'agent_name' => $agentName,
        'guest_name' => $guestName,
        'vendor_name' => $agentName,
        'amount' => $totalAmount,
        'currency' => 'USD',
        'pax_count' => $pax,
        'country_code' => $country,
        'category' => 'Tour Package',
        'status' => 'pending',
        'processing_status' => 'pending',
        'source' => 'onedrive',
        'folder_name' => $folderName,
        'nights' => $nights,
        'staging_import_id' => null,
        'message_id' => 'ONEDRIVE_PNL_' . uniqid(),
        'subject' => $folderName,
        'body' => $text,
        'body_hash' => md5($text),
        'received_at' => now(),
        'from_email' => 'onedrive@aahaas.com',
        'from_name' => 'OneDrive Import',
        'read_status' => 'read',
        'is_tour_confirmation' => false,
        'has_attachments' => false,
    ]);
    
    Log::info("✅ Created PnL record from PNL file: {$invoiceNumber}");
    return $record;
}
protected function extractAgentFromPNL($text)
{
    if (preg_match('/Agent:\s*([^\n]+)/i', $text, $match)) {
        return trim($match[1]);
    }
    return null;
}

protected function extractGuestFromPNL($text)
{
    if (preg_match('/Guests?\s+Name:\s*([^\n]+)/i', $text, $match)) {
        return trim($match[1]);
    }
    return null;
}

protected function extractPaxFromPNL($text)
{
    if (preg_match('/No\.\s*Pax:\s*(\d+)/i', $text, $match)) {
        return intval($match[1]);
    }
    if (preg_match('/No\.\s*Adult:\s*(\d+)/i', $text, $match)) {
        $adult = intval($match[1]);
        $child = 0;
        if (preg_match('/No\.\s*Child:\s*(\d+)/i', $text, $match2)) {
            $child = intval($match2[1]);
        }
        return $adult + $child;
    }
    return 1;
}

protected function extractNightsFromPNL($text)
{
    if (preg_match('/No\.\s*Night:\s*(\d+)/i', $text, $match)) {
        return intval($match[1]);
    }
    return null;
}

protected function extractTourRefFromPNL($text)
{
    if (preg_match('/Tour\s*(?:No|Ref):\s*#?(\d+)/i', $text, $match)) {
        return $match[1] . 'CNTL';
    }
    return null;
}
protected function processPnlFile($pnlFile, $folderPath, $record, $country)
{
    try {
        // Download and read content (supports both docx and pdf)
        $pnlContent = $this->downloadAndReadPnlFile($folderPath, $pnlFile['name'], $country);
        
        if (!$pnlContent) {
            Log::error("Failed to read PNL file: {$pnlFile['name']}");
            return;
        }

        $textContent = $pnlContent['text'] ?? '';

        // Use SriLankaPnLParser to extract items
        $lkParser = new \App\Services\SriLankaPnLParser();
        // We need to temporarily set record->body to PNL content for parser
        $record->body = $textContent;
        $record->currency = $record->currency ?? 'USD';
        $record->amount = $record->amount ?? $this->extractTotalAmountFromPNL($textContent);

        $lkParser->parseAndSaveItems($record);
        Log::info("✅ PNL items parsed and saved for record: {$record->id}");
        
        // Also update total amount if not set
        if ($record->amount == 0) {
            $total = $this->extractTotalAmountFromPNL($textContent);
            if ($total > 0) {
                $record->amount = $total;
                $record->save();
            }
        }

    } catch (\Exception $e) {
        Log::error("Error processing PNL file: " . $e->getMessage());
    }
}

protected function downloadAndReadPnlFile($folderPath, $fileName, $country)
{
    // Use downloadAndReadDocx for docx files
    if (strpos($fileName, '.pdf') !== false) {
        // For PDF, we need to download and extract text
        return $this->downloadAndReadPdf($folderPath, $fileName, $country);
    } else {
        return $this->downloadAndReadDocx($folderPath, $fileName, $country);
    }
}

protected function downloadAndReadPdf($folderPath, $fileName, $country)
{
    try {
        $useDriveId = ($country === 'LK') || strpos($folderPath, 'SL Share Drive') !== false;
        $remotePath = "{$folderPath}/{$fileName}";
        $encodedPath = str_replace(' ', '%20', $remotePath);
        
        if ($useDriveId) {
            $url = $this->baseUrl . "/drives/{$this->sriLankaDriveId}/root:/{$encodedPath}:/content";
        } else {
            $url = $this->baseUrl . "/users/{$this->userEmail}/drive/root:/{$encodedPath}:/content";
        }
        
        Log::info("📥 Downloading PDF: {$url}");
        
        $response = Http::withToken($this->accessToken)
            ->timeout(60)
            ->get($url);
        
        if (!$response->ok()) {
            Log::error("Failed to download PDF: {$remotePath}");
            return null;
        }
        
        $tempFile = tempnam(sys_get_temp_dir(), 'pnl_') . '.pdf';
        file_put_contents($tempFile, $response->body());
        
        // Parse PDF using a library (e.g., Smalot\PdfParser\Parser)
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($tempFile);
        $text = $pdf->getText();
        
        @unlink($tempFile);
        
        Log::info("📄 Extracted PDF content length: " . strlen($text));
        
        // Return in same format as docx extraction
        return [
            'text' => $text,
            'tables' => [] // PDF tables are not extracted, but we can parse text
        ];
        
    } catch (\Exception $e) {
        Log::error("Error reading PDF: " . $e->getMessage());
        return null;
    }
}

protected function extractTotalAmountFromPNL($text)
{
    // Use patterns similar to SriLankaPnLParser::extractTotalAmount
    if (preg_match('/Total\s+Tour\s+Cost\s*[:]?\s*\$?\s*([\d,]+\.\d{2})/i', $text, $match)) {
        return floatval(str_replace(',', '', $match[1]));
    }
    if (preg_match('/Total\s+Mega\s+Cost\s*[:]?\s*\$?\s*([\d,]+\.\d{2})/i', $text, $match)) {
        return floatval(str_replace(',', '', $match[1]));
    }
    return 0;
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
protected function processFileData($tcFile, $pnlFile, $filePath, $folderName, $invoiceNumber, $country, $runId)
{
    try {
        // Download TC content
        $docxContent = $this->downloadAndReadDocx($filePath, $tcFile['name'], $country);
        
        if (!$docxContent) {
            return [
                'folder' => $folderName,
                'invoice_number' => $invoiceNumber,
                'status' => 'failed',
                'reason' => 'Failed to read TC file'
            ];
        }
        
        $dateFolder = $this->extractDateFolder($filePath);
        $monthFolder = $this->extractMonthFolder($filePath);
        
        Log::info("📁 Path: {$filePath}");
        Log::info("📅 Date Folder from path: {$dateFolder}");
        Log::info("📅 Month Folder from path: {$monthFolder}");
        
        $this->dateFolder = $dateFolder;
        $textContent = $docxContent['text'] ?? '';
        
        // ✅✅✅ FIX: Direct OpenAI call for ALL countries
        $openAI = app(\App\Services\OpenAIService::class);
        
        // Step 1: Extract Total Tour Cost using OpenAI
        $totalResult = $openAI->extractTotalTourCostOnly($textContent);
        $totalAmount = 0;
        $currency = 'MYR';
        
        if ($totalResult['success'] && isset($totalResult['data']['total_amount'])) {
            $totalAmount = $totalResult['data']['total_amount'];
            $currency = $totalResult['data']['currency'] ?? 'MYR';
            Log::info("💰 OpenAI extracted total_amount: {$totalAmount} {$currency}");
        } else {
            // ✅ Fallback: Use regex to find amount
            $totalAmount = $this->extractTotalAmountFromText($textContent);
            Log::info("💰 Fallback total_amount: {$totalAmount}");
        }
        
        // Step 2: Extract other fields using OpenAI
        $extractedData = [];
        
        if ($country === 'MY') {
            $result = $openAI->extractMalaysiaTCData($textContent, $invoiceNumber, $folderName);
            if ($result['success'] && !empty($result['data'])) {
                $extractedData = $result['data'];
            } else {
                // Fallback: Use regex for Malaysia
                $extractedData = $this->extractMalaysiaTCDataRegex($textContent, $folderName, $invoiceNumber);
            }
        } elseif ($country === 'VN') {
            $result = $openAI->extractVietnamTCData($textContent, $invoiceNumber, $folderName);
            if ($result['success'] && !empty($result['data'])) {
                $extractedData = $result['data'];
            }
        } elseif ($country === 'LK') {
            $result = $openAI->extractLKTCData($textContent, $invoiceNumber, $folderName);
            if ($result['success'] && !empty($result['data'])) {
                $extractedData = $result['data'];
            }
        } else {
            $result = $openAI->extractTCDataFull($textContent, $invoiceNumber, $folderName);
            if ($result['success'] && !empty($result['data'])) {
                $extractedData = $result['data'];
            }
        }
        
        // ✅ FORCE total_amount from OpenAI or fallback
        $extractedData['total_amount'] = $totalAmount;
        $extractedData['currency'] = ($country === 'MY') ? 'MYR' : (($country === 'VN' || $country === 'LK') ? 'USD' : 'MYR');
        $extractedData['invoice_number'] = $invoiceNumber;
        $extractedData['folder_name'] = $folderName;
        
        // ✅ If file handler missing, try from folder name
        if (empty($extractedData['file_handler'])) {
            $extractedData['file_handler'] = $this->extractFileHandlerFromFolderName($folderName);
            if ($extractedData['file_handler']) {
                Log::info("📁 File Handler from folder name: " . $extractedData['file_handler']);
            }
        }
        
        $extractedData['date_folder_from_path'] = $dateFolder;
        $extractedData['month_folder_from_path'] = $monthFolder;
        
        // Log what was extracted
        Log::info("📊 Extracted Data for {$invoiceNumber}:");
        Log::info("  - Tour Ref: " . ($extractedData['tour_ref'] ?? 'NULL'));
        Log::info("  - Agent: " . ($extractedData['agent_name'] ?? 'NULL'));
        Log::info("  - Arrival: " . ($extractedData['arrival_date'] ?? 'NULL'));
        Log::info("  - Departure: " . ($extractedData['departure_date'] ?? 'NULL'));
        Log::info("  - Nights: " . ($extractedData['nights'] ?? 'NULL'));
        Log::info("  - Total Amount: " . ($extractedData['total_amount'] ?? 'NULL'));
        Log::info("  - Currency: " . ($extractedData['currency'] ?? 'NULL'));
        
        // ✅✅✅ FIX: Create import record FIRST
        $import = OneDriveImport::create([
            'folder_name' => $folderName,
            'invoice_number' => $invoiceNumber,
            'tour_ref' => $extractedData['tour_ref'] ?? null,
            'country_code' => $country,
            'month_folder' => $monthFolder,
            'date_folder' => $dateFolder,
            'tc_file_content' => $docxContent['text'] ?? '',
            'tc_file_path' => $tcFile['name'],
            'pnl_file_path' => $pnlFile ? $pnlFile['name'] : null,
            'extracted_data' => $extractedData,
            'status' => 'pending',
            'processed_at' => null,
            'pax_count' => $extractedData['guest_count'] ?? null,
            'total_amount' => $extractedData['total_amount'] ?? null,
            'currency' => $extractedData['currency'] ?? 'MYR',
        ]);
        
        Log::info("✅ Saved to OneDriveImport with ID: {$import->id}");
        Log::info("💰 Saved with total_amount: " . ($extractedData['total_amount'] ?? 'NULL'));
        
        // ✅ Process the import record
        $this->processStagingRecord($import->id);
        
        // ✅ Get the created record
        $record = PnlRecord::where('invoice_number', $invoiceNumber)->first();
        $recordId = $record ? $record->id : null;
        
        return [
            'folder' => $folderName,
            'invoice_number' => $invoiceNumber,
            'tour_ref' => $extractedData['tour_ref'] ?? null,
            'status' => 'processed',
            'import_id' => $import->id,
            'record_id' => $recordId, 
            'reason' => 'Saved to staging and processed'
        ];
        
    } catch (\Exception $e) {
        Log::error("Error processing: " . $e->getMessage());
        
        // ✅ Try to create a failed import record
        try {
            OneDriveImport::create([
                'folder_name' => $folderName,
                'invoice_number' => $invoiceNumber,
                'country_code' => $country,
                'month_folder' => $this->monthFolders[date('m')] ?? 'Unknown',
                'date_folder' => date('d') . ' July',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
        } catch (\Exception $inner) {
            Log::error("Failed to create import record: " . $inner->getMessage());
        }
        
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
        // ✅ Match "11 July" format (day + month)
        if (preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $part, $match)) {
            $day = intval($match[1]);
            // ✅ Only return if it's a valid day (1-31)
            if ($day >= 1 && $day <= 31) {
                return $part;
            }
        }
    }
    return null;
}

protected function extractMonthFolder($path)
{
    $parts = explode('/', $path);
    
    // ✅ First try: Look for "01 Apr" format (day + month abbreviation)
    foreach ($parts as $part) {
        // Match "01 Apr" or "30 Apr" format
        if (preg_match('/^\d{2}\s+([A-Za-z]+)$/', $part, $match)) {
            $monthName = $match[1];
            // Check if it's a valid month name/abbreviation
            $monthNumber = $this->getMonthNumber($monthName);
            if ($monthNumber) {
                // Return in standard format: "01 Apr" or "30 Apr"
                return $part;
            }
        }
    }
    
    // ✅ Second try: Look for "07 July" format (month number + month name)
    foreach ($parts as $part) {
        if (preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $part, $match)) {
            $monthNum = intval($match[1]);
            if ($monthNum >= 1 && $monthNum <= 12) {
                return $part;
            }
        }
    }
    
    return null;
}

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
        
        // ✅ FORCE total_amount from import
        $data['total_amount'] = $import->total_amount ?? $data['total_amount'] ?? 0;
        
        // ✅ If still 0, extract from TC content
        if ($data['total_amount'] == 0 && $import->tc_file_content) {
            if (preg_match('/Total\s+Tour\s+Cost\s*[:|\s]+(RM|MYR|USD|SGD)?\s*([0-9,]+\.\d{2})/i', $import->tc_file_content, $match)) {
                $data['total_amount'] = floatval(str_replace(',', '', $match[2]));
                Log::info("💰 Extracted total from TC: {$data['total_amount']}");
            }
        }
        
        Log::info("💰 Processing with total_amount: {$data['total_amount']}");
        
        // Check again if P&L exists
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
         $this->generateInvoiceFromTCData($data, $import, $record);
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
        
Log::info("✅ Successfully processed import: {$importId} -> P&L Record: {$record->id} with amount: {$record->amount}");
        // Generate invoice
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
protected function generateInvoiceFromTCData($data, $import, $record)
{
    try {
        $existing = GeneratedInvoice::where('invoice_number', $import->invoice_number)->first();
        if ($existing) {
            Log::info("⏭️ Invoice already exists: {$import->invoice_number}");
            return;
        }
        
        // ✅ Extract numeric guest count
        $guestCount = $data['guest_count'] ?? 1;
        if (is_string($guestCount)) {
            if (preg_match('/(\d+)/', $guestCount, $match)) {
                $guestCount = intval($match[1]);
            } else {
                $guestCount = 1;
            }
        } elseif (!is_numeric($guestCount)) {
            $guestCount = 1;
        }
        $guestCount = (int) $guestCount;
        
        Log::info("👤 Guest Count extracted: {$guestCount}");
        $fileHandler = $data['file_handler'] ?? null;
        
        // ✅ If still null, try from folder name
        if (!$fileHandler) {
            $fileHandler = $this->extractFileHandlerFromFolderName($import->folder_name);
        }
        
        Log::info("👤 File Handler: " . ($fileHandler ?? 'NULL'));
        // ✅ Build email data
        $email = \App\Models\IncomingEmail::create([
            'message_id' => 'ONEDRIVE_' . $import->id . '_' . time(),
            'from_email' => 'onedrive@aahaas.com',
            'from_name' => 'OneDrive Import',
            'subject' => $import->folder_name,
            'body' => $import->tc_file_content ?? '',
            'body_preview' => substr($import->tc_file_content ?? '', 0, 500),
            'received_at' => now(),
            'agent_name' => $data['agent_name'] ?? null,
            'guest_name' => $data['guest_name'] ?? null,
            'tour_ref' => $data['tour_ref'] ?? 'NA',
            'invoice_number' => $import->invoice_number,
            'file_handler' => $data['file_handler'] ?? null,
            'travel_start_date' => $data['arrival_date'] ?? null,
            'travel_end_date' => $data['departure_date'] ?? null,
            'number_of_guests' => $guestCount,    // ✅ Integer
            'pax_count' => $guestCount,           // ✅ Integer
            'total_amount' => $import->total_amount ?? $data['total_amount'] ?? 0,
            'currency' => $import->currency ?? $data['currency'] ?? 'USD',
            'is_tour_confirmation' => true,
            'processing_status' => 'pending',
            'read_status' => 'read',
            'has_attachments' => false,
        ]);
        
        Log::info("📧 Created email record for invoice: " . $email->id);
        
        $invoiceService = app(\App\Services\InvoiceGenerationService::class);
        $invoice = $invoiceService->generateFromEmail($email);
        
        if ($invoice) {
            Log::info("✅ Generated invoice from TC: {$invoice->invoice_number}");
            // ❌ MAIL COMMENTED
            return $invoice;
        }
        
    } catch (\Exception $e) {
        Log::error("Failed to generate invoice from TC: " . $e->getMessage());
    }
    
    return null;
}
/**
 * ✅ Extract file handler from folder name
 * Pattern: "IS48475 - Ammar Anees" → "Ammar Anees"
 */
/**
 * ✅ Extract file handler from folder name - IMPROVED for Malaysia
 * Pattern: "MY40031- saratha" → "Saratha"
 *          "MY40031 - Saratha" → "Saratha"
 *          "MY23122- madhu" → "Madhu"
 */
protected function extractFileHandlerFromFolderName($folderName)
{
    if (empty($folderName)) {
        return null;
    }
    
    // Pattern: "MY40031- saratha" or "MY40031 - Saratha" → extract after " - " or "-"
    if (preg_match('/^[A-Z]{2}\d+\s*[-_]\s*(.+)$/i', $folderName, $match)) {
        $handler = trim($match[1]);
        // Remove CANCELLED or extra text
        $handler = preg_replace('/\s*CANCELLED\s*/i', '', $handler);
        $handler = preg_replace('/\s*CANCELLED\s*-\s*/i', '', $handler);
        $handler = preg_replace('/\s*-\s*OneDrive\s*$/i', '', $handler);
        $handler = preg_replace('/\s*Confirmation\s*$/i', '', $handler);
        $handler = preg_replace('/\s*cancel\s*$/i', '', $handler, 1);
        
        // Capitalize first letter of each word
        $handler = ucwords(strtolower($handler));
        
        return $handler;
    }
    
    // Pattern: "MY40031_saratha" → extract after "_"
    if (preg_match('/^[A-Z]{2}\d+_\s*(.+)$/i', $folderName, $match)) {
        $handler = trim($match[1]);
        $handler = ucwords(strtolower($handler));
        return $handler;
    }
    
    return null;
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
    
    // Pattern: "MY 40027- Saratha" → "MY40027" (with space)
    if (preg_match('/^([A-Z]{2})\s+(\d+)/i', $folderName, $match)) {
        return strtoupper($match[1]) . $match[2];
    }
    
    return null;
}

protected function downloadAndReadDocx($folderPath, $fileName, $country = null)
{
    try {
        $useDriveId = ($country === 'LK') || strpos($folderPath, 'SL Share Drive') !== false;
        $remotePath = "{$folderPath}/{$fileName}";
        $encodedPath = str_replace(' ', '%20', $remotePath);
        
        if ($useDriveId) {
            $url = $this->baseUrl . "/drives/{$this->sriLankaDriveId}/root:/{$encodedPath}:/content";
        } else {
            $url = $this->baseUrl . "/users/{$this->userEmail}/drive/root:/{$encodedPath}:/content";
        }
        
        Log::info("📥 Downloading: {$url}");
        
        $response = Http::withToken($this->accessToken)
            ->timeout(60)
            ->get($url);
        
        if (!$response->ok()) {
            Log::error("Failed to download file: {$remotePath}");
            return null;
        }
        
        $tempFile = tempnam(sys_get_temp_dir(), 'tc_') . '.docx';
        file_put_contents($tempFile, $response->body());
        
        $phpWord = IOFactory::load($tempFile);
        $result = $this->extractDocxContent($phpWord);
        
        @unlink($tempFile);
        
        Log::info("📄 Extracted content length: " . strlen($result['text']));
        Log::info("📊 Found " . count($result['tables']) . " tables");
        
        return $result;
        
    } catch (\Exception $e) {
        Log::error("Error reading DOCX: " . $e->getMessage());
        return null;
    }
}
/**
 * ✅ Extract content from DOCX including tables - IMPROVED
 */
protected function extractDocxContent($phpWord)
{
    $text = '';
    $tables = [];
    
    try {
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                
                // 1. Handle Tables
                if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    $tableData = $this->extractTableData($element);
                    if (!empty($tableData)) {
                        $tables[] = $tableData;
                        $text .= $this->tableToText($tableData) . ' ';
                    }
                    continue;
                }
                
                // 2. Text elements
                try {
                    $elementText = $this->extractElementText($element);
                    if (!empty($elementText)) {
                        $text .= $elementText . ' ';
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
    } catch (\Exception $e) {
        Log::warning("⚠️ DOCX extraction warning: " . $e->getMessage());
    }
    
    // Clean text
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    Log::info("📄 Extracted text length: " . strlen($text));
    Log::info("📄 Extracted tables: " . count($tables));
    
    return [
        'text' => $text,
        'tables' => $tables
    ];
}
/**
 * ✅ Extract data from a table
 */
/**
 * ✅ SAFE: Extract text from any element
 */
protected function extractElementText($element)
{
    // If it's a Text element
    if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
        return $element->getText() ?? '';
    }
    
    // If it's a TextRun
    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
        $text = '';
        foreach ($element->getElements() as $child) {
            $text .= $this->extractElementText($child);
        }
        return $text;
    }
    
    // If it's a Paragraph
    if ($element instanceof \PhpOffice\PhpWord\Element\Paragraph) {
        $text = '';
        foreach ($element->getElements() as $child) {
            $text .= $this->extractElementText($child);
        }
        return $text;
    }
    
    // If it has a getText method
    if (method_exists($element, 'getText')) {
        try {
            $text = $element->getText();
            return is_string($text) ? $text : '';
        } catch (\Exception $e) {
            return '';
        }
    }
    
    // If it has getElements method (generic container)
    if (method_exists($element, 'getElements')) {
        $text = '';
        try {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractElementText($child);
            }
        } catch (\Exception $e) {
            // Skip
        }
        return $text;
    }
    
    return '';
}
/**
 * ✅ Extract data from a table - ALWAYS extract all rows
 */
protected function extractTableData($table)
{
    $data = [];
    
    foreach ($table->getRows() as $row) {
        $cells = $row->getCells();
        $rowData = [];
        
        foreach ($cells as $cell) {
            $cellText = '';
            // Use extractElementText to handle all element types inside the cell
            foreach ($cell->getElements() as $element) {
                $cellText .= $this->extractElementText($element) . ' ';
            }
            $rowData[] = trim($cellText);
        }
        
        // Only add rows that have at least one non-empty cell
        $hasData = false;
        foreach ($rowData as $cell) {
            if (!empty($cell) && $cell !== '-' && $cell !== '|') {
                $hasData = true;
                break;
            }
        }
        
        if ($hasData) {
            $data[] = $rowData;
        }
    }
    
    return $data;
}

/**
 * ✅ Convert table to text - join all cells with spaces
 */
protected function tableToText($tableData)
{
    $text = '';
    foreach ($tableData as $row) {
        $text .= implode(' ', $row) . ' ';
    }
    return $text;
}

/**
 * Extract Malaysia TC Data - USING OPENAI ON FULL DOCX CONTENT
 */
protected function extractMalaysiaTCData($docxContent, $folderName, $invoiceNumber)
{
    $text = $docxContent['text'] ?? '';
    
    // ✅ Log the content length
    Log::info("📄 Malaysia TC content length: " . strlen($text));
    Log::info("📄 Content preview: " . substr($text, 0, 500));
    
    // ✅ TRY OPENAI FIRST - with full text including tables
    try {
        $openAI = app(\App\Services\OpenAIService::class);
        $result = $openAI->extractMalaysiaTCData($text, $invoiceNumber, $folderName);
        
        if ($result['success'] && !empty($result['data'])) {
            $data = $result['data'];
            
            // ✅ Ensure currency is MYR for Malaysia
            if (empty($data['currency'])) {
                $data['currency'] = 'MYR';
            }
            
            // ✅ If total_amount is still null or 0, try to extract from text
            if (empty($data['total_amount']) || $data['total_amount'] == 0) {
                $data['total_amount'] = $this->extractTotalAmountFromText($text);
                Log::info("💰 Extracted total from text: {$data['total_amount']}");
            }
            
            Log::info("✅ OpenAI Malaysia extraction successful for: {$invoiceNumber}");
            Log::info("📊 Extracted: " . json_encode($data));
            return $data;
        }
    } catch (\Exception $e) {
        Log::error("OpenAI extraction failed: " . $e->getMessage());
    }
    
    // ✅ FALLBACK: Try to extract directly from tables and text
    Log::warning("⚠️ OpenAI failed, using fallback for: {$invoiceNumber}");
    return $this->extractMalaysiaTCDataFallback($docxContent, $folderName, $invoiceNumber);
}

/**
 * ✅ FALLBACK: Extract Malaysia TC Data - INCLUDING TABLES
 */
protected function extractMalaysiaTCDataFallback($docxContent, $folderName, $invoiceNumber)
{
    $text = $docxContent['text'] ?? '';
    $tables = $docxContent['tables'] ?? [];
    
    $data = [
        'tour_ref' => null,
        'agent_name' => null,
        'file_handler' => null,
        'sales_person' => null,
        'guest_id' => null,
        'guest_name' => null,
        'guest_count' => 1,
        'arrival_date' => null,
        'departure_date' => null,
        'nights' => null,
        'hotel_name' => null,
        'city' => null,
        'meal_plan' => null,
        'total_amount' => 0,
        'currency' => 'MYR'
    ];
    
    // ✅ 1. Extract from Tables first (most reliable)
    foreach ($tables as $tableData) {
        foreach ($tableData as $row) {
            // Look for Total Tour Cost in table
            foreach ($row as $key => $value) {
                if (stripos($value, 'Total Tour Cost') !== false) {
                    // Check if amount is in same row or next cell
                    if (isset($row[array_search($key, array_keys($row)) + 1])) {
                        $amountStr = $row[array_search($key, array_keys($row)) + 1];
                        if (preg_match('/([\d,]+\.\d{2})/', $amountStr, $match)) {
                            $data['total_amount'] = floatval(str_replace(',', '', $match[1]));
                            Log::info("💰 Found Total in table: {$data['total_amount']}");
                        }
                    }
                }
            }
            
            // Extract hotel/city/nights from table
            if (isset($row['hotel']) || isset($row['city']) || isset($row['nights'])) {
                if (!empty($row['hotel']) && empty($data['hotel_name'])) {
                    $data['hotel_name'] = $row['hotel'];
                }
                if (!empty($row['city']) && empty($data['city'])) {
                    $data['city'] = $row['city'];
                }
                if (!empty($row['nights']) && empty($data['nights'])) {
                    $data['nights'] = intval($row['nights']);
                }
                if (!empty($row['meal type']) && empty($data['meal_plan'])) {
                    $data['meal_plan'] = $row['meal type'];
                }
            }
        }
    }
    
    // ✅ 2. If total_amount still 0, extract from text
    if ($data['total_amount'] == 0) {
        $data['total_amount'] = $this->extractTotalAmountFromText($text);
        Log::info("💰 Fallback total from text: {$data['total_amount']}");
    }
    
    // ✅ 3. Extract other fields from text
    $cleanText = preg_replace('/\s+/', ' ', $text);
    
    // Tour Ref
    if (preg_match('/Tour\s+Ref\s*[:|\s]+([A-Z0-9]+)/i', $cleanText, $match)) {
        $data['tour_ref'] = trim($match[1]);
    }
    
    // File Handler
    if (preg_match('/File\s+Handler\s*[:|\s]+([^\n,]+)/i', $cleanText, $match)) {
        $data['file_handler'] = trim($match[1]);
        $data['sales_person'] = $data['file_handler'];
    }
    
    // Agent
    if (preg_match('/Agent\s*[:|\s]+([^\n,]+)/i', $cleanText, $match)) {
        $agent = trim($match[1]);
        if (strlen($agent) < 30 && !preg_match('/name\s+revised/i', $agent)) {
            $data['agent_name'] = $agent;
        }
    }
    
    // Guest Name
    if (preg_match('/Guests?\s+Name\s*[:|\s]+([^\n,]+)/i', $cleanText, $match)) {
        $data['guest_name'] = trim($match[1]);
    }
    
    // Guest Count
    if (preg_match('/No\.?\s*of\s*Guests?\s*[:|\s]+(\d+)\s*Adults?/i', $cleanText, $match)) {
        $data['guest_count'] = intval($match[1]);
    }
    
    // Dates
    if (preg_match('/Arrival\s+Date\s*[:|\s]+(\d{1,2})\s+([A-Za-z]{3,}),?\s*(\d{4})/i', $cleanText, $match)) {
        $day = intval($match[1]);
        $month = $this->getMonthNumber($match[2]);
        $year = intval($match[3]);
        if ($month) {
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
        }
    }
    
    if (preg_match('/Departure\s+Date\s*[:|\s]+(\d{1,2})\s+([A-Za-z]{3,}),?\s*(\d{4})/i', $cleanText, $match)) {
        $day = intval($match[1]);
        $month = $this->getMonthNumber($match[2]);
        $year = intval($match[3]);
        if ($month) {
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
        }
    }
    
    // Calculate nights from dates
    if ($data['arrival_date'] && $data['departure_date'] && empty($data['nights'])) {
        $start = strtotime($data['arrival_date']);
        $end = strtotime($data['departure_date']);
        if ($start && $end) {
            $data['nights'] = round(($end - $start) / (60 * 60 * 24));
        }
    }
    
    // Guest ID (Booking ID)
    if (preg_match('/Booking\s+ID\s*[:|\s]+([A-Z0-9]+)/i', $cleanText, $match)) {
        $data['guest_id'] = trim($match[1]);
    }
    
    Log::info("📊 Malaysia Fallback extracted: " . json_encode($data));
    return $data;
}
/**
 * ✅ Detect currency from text
 */
protected function detectCurrency($text)
{
    if (stripos($text, 'RM') !== false || stripos($text, 'MYR') !== false) {
        return 'MYR';
    }
    if (stripos($text, 'SGD') !== false || stripos($text, 'S$') !== false) {
        return 'SGD';
    }
    if (stripos($text, 'USD') !== false || stripos($text, '$') !== false) {
        return 'USD';
    }
    return 'MYR'; // Default for Malaysia
}

/**
 * ✅ Clean date string to Y-m-d format
 */
protected function cleanDate($dateStr)
{
    if (empty($dateStr)) return null;
    
    // Remove extra spaces
    $dateStr = trim($dateStr);
    
    // Try multiple formats
    $formats = [
        'Y-m-d',
        'd-m-Y',
        'd/m/Y',
        'Y/m/d',
        'd M Y',
        'M d, Y',
        'd-M-Y',
        'd/M/Y',
        'Y-M-d',
        'Y/M/d',
        'm/d/Y',
        'd/m/Y',
    ];
    
    foreach ($formats as $format) {
        $date = \DateTime::createFromFormat($format, $dateStr);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }
    
    // Try strtotime as fallback
    $timestamp = strtotime($dateStr);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}

/**
 * ✅ Extract from text labels
 */
protected function extractFromLabels($text, &$data)
{
    // Tour Ref - only if not already found
    if (empty($data['tour_ref'])) {
        if (preg_match('/Tour\s+Ref\s*[:|\s]+([A-Z0-9]{5,})/i', $text, $match)) {
            $data['tour_ref'] = trim($match[1]);
        }
    }
    
    // Agent
    if (empty($data['agent_name'])) {
        if (preg_match('/Agent\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $agent = trim($match[1]);
            if (!preg_match('/name\s+revised/i', $agent) && strlen($agent) < 50) {
                $data['agent_name'] = $agent;
            }
        }
    }
    
    // Guest Name
    if (empty($data['guest_name'])) {
        if (preg_match('/Guests?\s+Name\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $data['guest_name'] = trim($match[1]);
        }
    }
    
    // Guest Count
    if (empty($data['guest_count'])) {
        if (preg_match('/No\.?\s*of\s*Guests?\s*[:|\s]+(\d+)\s*Adults?/i', $text, $match)) {
            $data['guest_count'] = intval($match[1]);
        } elseif (preg_match('/(\d+)\s*Adults?/i', $text, $match)) {
            $data['guest_count'] = intval($match[1]);
        }
    }
    
    // Meal Plan
    if (empty($data['meal_plan'])) {
        if (preg_match('/Meal\s+Plan\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $meal = trim($match[1]);
            if (strlen($meal) < 20) {
                $data['meal_plan'] = $meal;
            }
        }
    }
    
    // File Handler
    if (empty($data['file_handler'])) {
        if (preg_match('/File\s+Handler\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $data['file_handler'] = trim($match[1]);
        }
    }
    
    // Flight
    if (empty($data['flight'])) {
        if (preg_match('/Flight\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $data['flight'] = trim($match[1]);
        }
    }
    
    // Chauffeur Contact
    if (empty($data['chauffeur_contact'])) {
        if (preg_match('/Chauffeur\s+contact\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $data['chauffeur_contact'] = trim($match[1]);
        }
    }
    
    // Arrival Date - only if not already found
    if (empty($data['arrival_date'])) {
        if (preg_match('/Arrival\s*Date\s*[:|\s]+(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/i', $text, $match)) {
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", $match[1], $match[2], $match[3]);
        } elseif (preg_match('/Arrival\s*Date\s*[:|\s]+(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/i', $text, $match)) {
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", $match[3], $match[1], $match[2]);
        }
    }
    
    // Departure Date - only if not already found
    if (empty($data['departure_date'])) {
        if (preg_match('/Departure\s*Date\s*[:|\s]+(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/i', $text, $match)) {
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $match[1], $match[2], $match[3]);
        } elseif (preg_match('/Departure\s*Date\s*[:|\s]+(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/i', $text, $match)) {
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $match[3], $match[1], $match[2]);
        }
    }
    
    // Emergency Contacts
    if (empty($data['emergency_contacts'])) {
        if (preg_match_all('/([A-Za-z\s]+)\s*\(([+\d\s]+)\)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $data['emergency_contacts'][] = [
                    'name' => trim($match[1]),
                    'phone' => trim($match[2])
                ];
            }
        }
    }
}

/**
 * ✅ Fallback regex extraction
 */
protected function extractWithFallbackRegex($text, &$data)
{
    // Tour Ref - if still null
    if (empty($data['tour_ref'])) {
        if (preg_match('/([A-Z]{0,2}\d{5,})/', $text, $match)) {
            $data['tour_ref'] = $match[1];
        }
    }
    
    // Agent - if still null
    if (empty($data['agent_name'])) {
        if (preg_match('/\b(FIT|GLOBAL\s*JOURNEYS|MAKEMYTRIP|HOLIDAYS|TRAVEL|TOURS)\b/i', $text, $match)) {
            $data['agent_name'] = strtoupper($match[1]);
        }
    }
    
    // City - if still null
    if (empty($data['city'])) {
        if (preg_match('/(Kuala\s*Lumpur|KualaLumpur|Penang|Johor|Lang|Malacca|Cameron)/i', $text, $match)) {
            $data['city'] = $match[1];
        }
    }
    
    // Hotel - if still null
    if (empty($data['hotel_name'])) {
        if (preg_match('/Own\s+Arrangement/i', $text)) {
            $data['hotel_name'] = 'Own Arrangement';
        } elseif (preg_match('/Hotel\s*[:|\s]+([^\n]+)/i', $text, $match)) {
            $data['hotel_name'] = trim($match[1]);
        }
    }
    
    // Nights - if still null
    if (empty($data['nights'])) {
        if (preg_match('/(\d+)\s*Nights?/i', $text, $match)) {
            $data['nights'] = intval($match[1]);
        } elseif (preg_match('/\b(\d{1,2})\s*days?/i', $text, $match)) {
            $nights = intval($match[1]) - 1;
            if ($nights > 0 && $nights < 31) {
                $data['nights'] = $nights;
            }
        }
    }
}

/**
 * ✅ Format dates
 */
protected function formatDates(&$data)
{
    // If no departure date but we have arrival date and nights
    if ($data['arrival_date'] && empty($data['departure_date']) && $data['nights']) {
        $data['departure_date'] = date('Y-m-d', strtotime($data['arrival_date'] . ' + ' . $data['nights'] . ' days'));
        Log::info("✅ Calculated DEPARTURE DATE from arrival + nights: {$data['departure_date']}");
    }
    
    // If no arrival date but we have date folder
    if (empty($data['arrival_date'])) {
        $dateFolder = $this->dateFolder ?? null;
        if ($dateFolder && preg_match('/^(\d{2})\s+([A-Za-z]+)$/', $dateFolder, $match)) {
            $day = intval($match[1]);
            $month = $this->getMonthNumber($match[2]);
            $year = date('Y');
            if ($month) {
                $data['arrival_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
                Log::info("✅ Set ARRIVAL DATE from folder: {$data['arrival_date']}");
            }
        }
    }
}

/**
 * ✅ Get month number
 */
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


/**
 * ✅ Extract from tables
 */
protected function extractFromTables($tables, &$data)
{
    foreach ($tables as $tableData) {
        foreach ($tableData as $row) {
            // Look for hotel/city/nights pattern
            if (isset($row['city']) || isset($row['hotel']) || isset($row['nights'])) {
                if (!empty($row['city'])) {
                    $data['city'] = $row['city'];
                }
                if (!empty($row['hotel'])) {
                    $data['hotel_name'] = $row['hotel'];
                }
                if (!empty($row['nights'])) {
                    $data['nights'] = intval($row['nights']);
                }
                if (!empty($row['room type'])) {
                    $data['room_type'] = $row['room type'];
                }
                if (!empty($row['meal type'])) {
                    $data['meal_plan'] = $row['meal type'];
                }
                // If we found table data, break
                break 2;
            }
            
            // Also check for "Own Arrangement" in any column
            foreach ($row as $key => $value) {
                if (stripos($value, 'Own Arrangement') !== false) {
                    $data['hotel_name'] = 'Own Arrangement';
                    // Try to find city from other columns
                    foreach ($row as $k => $v) {
                        if (!empty($v) && stripos($v, 'Own Arrangement') === false && 
                            (stripos($v, 'Kuala') !== false || stripos($v, 'Lumpur') !== false)) {
                            $data['city'] = $v;
                        }
                        if (!empty($v) && is_numeric($v) && intval($v) > 0 && intval($v) < 31) {
                            $data['nights'] = intval($v);
                        }
                    }
                    break 2;
                }
            }
        }
    }
}



protected function extractWithOpenAI($content, $folderName, $invoiceNumber)
{
    try {
        $openAI = app(\App\Services\OpenAIService::class);
        
        // ✅ DETECT MALAYSIA
        $isMalaysia = (stripos($content, 'RM') !== false || 
                      stripos($content, 'MYR') !== false ||
                      stripos($folderName, 'MY') !== false ||
                      stripos($content, 'Kuala lumpur') !== false ||
                      stripos($content, 'Malaysia') !== false);
        
        // ✅ DETECT VIETNAM
        $isVietnam = (stripos($content, 'VN') !== false || 
                      stripos($folderName, 'VN') !== false ||
                      stripos($content, 'Vietnam') !== false ||
                      stripos($content, 'Danang') !== false ||
                      stripos($content, 'Hanoi') !== false ||
                      stripos($content, 'Da Nang') !== false);
        
        // ✅ DETECT SRI LANKA
        $isLK = (stripos($content, 'Tour Ref') !== false && 
                 (stripos($content, 'IS') !== false || 
                  stripos($content, 'Pick Your Trails') !== false ||
                  stripos($content, 'SL Share Drive') !== false));
        
        // ✅ Get total amount first
        $totalResult = $openAI->extractTotalTourCostOnly($content);
        $totalAmount = null;
        $currency = 'MYR';
        
        if ($totalResult['success'] && isset($totalResult['data']['total_amount'])) {
            $totalAmount = $totalResult['data']['total_amount'];
            $currency = $totalResult['data']['currency'] ?? 'MYR';
            Log::info("💰 OpenAI extracted total_amount: {$totalAmount} {$currency}");
        }
        
        // ✅ For MALAYSIA - use specific extraction
        if ($isMalaysia) {
            Log::info("🔍 Detected Malaysia format for: {$invoiceNumber}");
            $result = $openAI->extractMalaysiaTCData($content, $invoiceNumber, $folderName);
            
            if ($result['success'] && !empty($result['data'])) {
                $data = $result['data'];
                if ($totalAmount !== null) {
                    $data['total_amount'] = $totalAmount;
                    $data['currency'] = 'MYR';
                }
                $data['invoice_number'] = $invoiceNumber;
                $data['folder_name'] = $folderName;
                
                Log::info("📊 Malaysia Final extracted data: " . json_encode($data));
                return $data;
            }
            
            // ✅ FALLBACK: Use regex for Malaysia
            Log::warning("⚠️ OpenAI Malaysia extraction failed, using regex fallback");
            $data = $this->extractMalaysiaTCDataRegex($content, $folderName, $invoiceNumber);
            if ($totalAmount !== null) {
                $data['total_amount'] = $totalAmount;
            }
            $data['invoice_number'] = $invoiceNumber;
            $data['folder_name'] = $folderName;
            return $data;
        }
        
        // ✅ For VIETNAM - use specific extraction
        if ($isVietnam) {
            Log::info("🔍 Detected Vietnam format for: {$invoiceNumber}");
            $result = $openAI->extractVietnamTCData($content, $invoiceNumber, $folderName);
            
            if ($result['success'] && !empty($result['data'])) {
                $data = $result['data'];
                if ($totalAmount !== null) {
                    $data['total_amount'] = $totalAmount;
                    $data['currency'] = 'USD';
                }
                $data['invoice_number'] = $invoiceNumber;
                $data['folder_name'] = $folderName;
                
                Log::info("📊 Vietnam Final extracted data: " . json_encode($data));
                return $data;
            }
            
            // ✅ FALLBACK: Use regex for Vietnam
            Log::warning("⚠️ OpenAI Vietnam extraction failed, using regex fallback");
            $data = $this->extractVietnamDataRegex($content, $folderName, $invoiceNumber);
            if ($totalAmount !== null) {
                $data['total_amount'] = $totalAmount;
            }
            $data['invoice_number'] = $invoiceNumber;
            $data['folder_name'] = $folderName;
            return $data;
        }
        
        // ✅ For LK, use specific extraction
        if ($isLK) {
            Log::info("🔍 Detected Sri Lanka format for: {$invoiceNumber}");
            $result = $openAI->extractLKTCData($content, $invoiceNumber, $folderName);
            
            if ($result['success'] && !empty($result['data'])) {
                $data = $result['data'];
                if ($totalAmount !== null) {
                    $data['total_amount'] = $totalAmount;
                    $data['currency'] = 'USD';
                }
                $data['invoice_number'] = $invoiceNumber;
                $data['folder_name'] = $folderName;
                
                Log::info("📊 LK Final extracted data: " . json_encode($data));
                return $data;
            }
        }
        
        // ✅ General extraction (fallback)
        $result = $openAI->extractTCDataFull($content, $invoiceNumber, $folderName);
        
        if ($result['success'] && !empty($result['data'])) {
            $data = $result['data'];
            if ($totalAmount !== null) {
                $data['total_amount'] = $totalAmount;
                if ($isMalaysia) {
                    $data['currency'] = 'MYR';
                } elseif ($isVietnam) {
                    $data['currency'] = 'USD';
                } else {
                    $data['currency'] = $currency;
                }
            }
            $data['invoice_number'] = $invoiceNumber;
            $data['folder_name'] = $folderName;
            
            Log::info("📊 Final extracted data: " . json_encode($data));
            return $data;
        }
        
        // ✅ Final fallback: Regex
        return [
            'tour_ref' => $invoiceNumber,
            'agent_name' => null,
            'arrival_date' => null,
            'departure_date' => null,
            'total_amount' => $this->extractTotalAmountFromText($content),
            'currency' => 'MYR',
            'invoice_number' => $invoiceNumber,
            'folder_name' => $folderName,
            'hotels' => [],
            'transport_items' => [],
            'meal_items' => [],
        ];
        
    } catch (\Exception $e) {
        Log::error("OpenAI extraction failed: " . $e->getMessage());
        
        return [
            'tour_ref' => $invoiceNumber,
            'agent_name' => null,
            'arrival_date' => null,
            'departure_date' => null,
            'total_amount' => 0,
            'currency' => 'MYR',
            'invoice_number' => $invoiceNumber,
            'folder_name' => $folderName,
        ];
    }
}
/**
 * ✅ Extract Malaysia TC Data using Regex - FALLBACK
 */
protected function extractMalaysiaTCDataRegex($text, $folderName, $invoiceNumber)
{
    $data = [
        'tour_ref' => null,
        'agent_name' => null,
        'file_handler' => null,
        'sales_person' => null,
        'guest_id' => null,
        'guest_name' => null,
        'guest_count' => 1,
        'arrival_date' => null,
        'departure_date' => null,
        'nights' => null,
        'hotel_name' => null,
        'city' => null,
        'meal_plan' => null,
        'total_amount' => 0,
        'currency' => 'MYR'
    ];
    
    $cleanText = preg_replace('/\s+/', ' ', $text);
    
    // ✅ Extract Total Tour Cost - MULTIPLE PATTERNS
    $patterns = [
        '/Total\s+Tour\s+Cost\s*(?:USD|MYR|RM)?\s*([\d,]+\.\d{2})/i',
        '/Total\s+Tour\s+Cost\s*\$?\s*([\d,]+\.\d{2})/i',
        '/Total\s+Tour\s+Cost\s*([\d,]+\.\d{2})/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $cleanText, $match)) {
            $data['total_amount'] = floatval(str_replace(',', '', $match[1]));
            Log::info("💰 Regex found Total Amount: {$data['total_amount']}");
            break;
        }
    }
    
    // ✅ If still 0, try to find any USD amount
    if ($data['total_amount'] == 0) {
        if (preg_match('/\$\s*([\d,]+\.\d{2})/', $cleanText, $match)) {
            $data['total_amount'] = floatval(str_replace(',', '', $match[1]));
            Log::info("💰 Regex found dollar amount: {$data['total_amount']}");
        }
    }
    
    // ✅ Extract other fields
    if (preg_match('/Tour\s+Ref\s*[:|\s]+([A-Z0-9]+)/i', $cleanText, $match)) {
        $data['tour_ref'] = trim($match[1]);
    }
    
    if (preg_match('/File\s+Handler\s*[:|\s]+([^\n,]+)/i', $cleanText, $match)) {
        $data['file_handler'] = trim($match[1]);
        $data['sales_person'] = $data['file_handler'];
    }
    
    if (preg_match('/Agent\s*[:|\s]+([^\n,]+)/i', $cleanText, $match)) {
        $agent = trim($match[1]);
        if (strlen($agent) < 30) {
            $data['agent_name'] = $agent;
        }
    }
    
    if (preg_match('/Guests?\s+Name\s*[:|\s]+([^\n,]+)/i', $cleanText, $match)) {
        $data['guest_name'] = trim($match[1]);
    }
    
    if (preg_match('/No\.?\s*of\s*Guests?\s*[:|\s]+(\d+)\s*Adults?/i', $cleanText, $match)) {
        $data['guest_count'] = intval($match[1]);
    }
    
    if (preg_match('/Arrival\s+Date\s*[:|\s]+(\d{1,2})\s+([A-Za-z]{3,}),?\s*(\d{4})/i', $cleanText, $match)) {
        $day = intval($match[1]);
        $month = $this->getMonthNumber($match[2]);
        $year = intval($match[3]);
        if ($month) {
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
        }
    }
    
    if (preg_match('/Departure\s+Date\s*[:|\s]+(\d{1,2})\s+([A-Za-z]{3,}),?\s*(\d{4})/i', $cleanText, $match)) {
        $day = intval($match[1]);
        $month = $this->getMonthNumber($match[2]);
        $year = intval($match[3]);
        if ($month) {
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
        }
    }
    
    if (preg_match('/Booking\s+ID\s*[:|\s]+([A-Z0-9]+)/i', $cleanText, $match)) {
        $data['guest_id'] = trim($match[1]);
    }
    
    // Calculate nights
    if ($data['arrival_date'] && $data['departure_date']) {
        $start = strtotime($data['arrival_date']);
        $end = strtotime($data['departure_date']);
        if ($start && $end) {
            $data['nights'] = round(($end - $start) / (60 * 60 * 24));
        }
    }
    
    Log::info("📊 Malaysia Regex extracted: " . json_encode($data));
    return $data;
}
protected function extractTotalAmountFromText($text)
{
    $cleanText = preg_replace('/\s+/', ' ', $text);
    $cleanText = str_replace("\n", ' ', $cleanText);
    
    Log::info("🔍 Searching for Total Tour Cost in text length: " . strlen($cleanText));
    
    // ✅ Try ALL possible patterns
    $patterns = [
        '/Total\s+Tour\s+Cost\s*(?:USD|MYR|RM)?\s*([\d,]+\.\d{2})/i',
        '/Total\s+Tour\s+Cost\s*\$?\s*([\d,]+\.\d{2})/i',
        '/Total\s+Tour\s+Cost\s*([\d,]+\.\d{2})/i',
        '/Total\s+Tour\s+Cost.*?([\d,]+\.\d{2})/is',
        '/Total\s+Tour\s+Cost.*?(?:USD|MYR|RM)?\s*([\d,]+\.\d{2})/is',
        '/\[Total\s+Tour\s+Cost\].*?([\d,]+\.\d{2})/is',
        '/\[USD\s+([\d,]+\.\d{2})\]/i',
        '/\*\*Total Tour Cost\*\*.*?([\d,]+\.\d{2})/is',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $cleanText, $match)) {
            $amount = floatval(str_replace(',', '', $match[1]));
            if ($amount > 0) {
                Log::info("💰 Found Total Tour Cost: {$amount}");
                return $amount;
            }
        }
    }
    
    // ✅ Fallback: Find any USD amount that is likely the total
    if (preg_match_all('/USD\s*([\d,]+\.\d{2})/i', $cleanText, $matches)) {
        $amounts = array_map(function($val) {
            return floatval(str_replace(',', '', $val));
        }, $matches[1]);
        
        $amounts = array_filter($amounts, function($val) {
            return $val > 10;
        });
        
        if (!empty($amounts)) {
            $max = max($amounts);
            Log::info("💰 Found largest USD amount: {$max}");
            return $max;
        }
    }
    
    // ✅ Fallback: Find any dollar amount
    if (preg_match_all('/\$\s*([\d,]+\.\d{2})/', $cleanText, $matches)) {
        $amounts = array_map(function($val) {
            return floatval(str_replace(',', '', $val));
        }, $matches[1]);
        
        $amounts = array_filter($amounts, function($val) {
            return $val > 10;
        });
        
        if (!empty($amounts)) {
            $max = max($amounts);
            Log::info("💰 Found largest dollar amount: {$max}");
            return $max;
        }
    }
    
    Log::warning("⚠️ No Total Tour Cost found in text");
    return 0;
}
/**
 * ✅ Extract currency using regex
 */
protected function extractCurrencyFromText($text)
{
    if (preg_match('/Total\s+Tour\s+Cost\s*[:]?\s*([$]?)\s*([\d,]+\.\d{2})\s*(USD|MYR|SGD)?/i', $text, $match)) {
        if (!empty($match[3])) {
            return strtoupper($match[3]);
        }
        if ($match[1] === '$') {
            return 'USD';
        }
    }
    
    // Look for $ symbol
    if (strpos($text, '$') !== false) {
        return 'USD';
    }
    
    // Look for MYR
    if (stripos($text, 'MYR') !== false) {
        return 'MYR';
    }
    
    return 'USD'; // Default for LK
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

protected function createPnLRecord($data, $import)
{
    try {
        // ✅ Get total_amount from extracted data
        $totalAmount = $data['total_amount'] ?? 0;
        
        // ✅ If still 0, try to get from import
        if ($totalAmount == 0 && $import->total_amount) {
            $totalAmount = $import->total_amount;
        }
        
        // ✅ If still 0, try to extract from TC content
        if ($totalAmount == 0) {
            $tcContent = $import->tc_file_content ?? '';
            $totalAmount = $this->extractTotalAmountFromText($tcContent);
        }
        
  $currency = $data['currency'] ?? 'USD';
        
        if ($import->country_code == 'MY') {
            $tcContent = $import->tc_file_content ?? '';
            if (stripos($tcContent, 'RM') !== false || stripos($tcContent, 'MYR') !== false) {
                $currency = 'MYR';
            } else {
                $currency = 'MYR'; // Default for Malaysia
            }
        } elseif ($import->country_code == 'VN') {
            $currency = 'USD'; // Vietnam always USD
        } elseif ($import->country_code == 'LK') {
            $currency = 'USD'; // Sri Lanka always USD
        } elseif ($import->country_code == 'SG') {
            $currency = 'SGD'; // Singapore always SGD
        }
        Log::info("💰 Final Total Amount for {$import->invoice_number}: {$totalAmount}");
        
        // ✅ Get the TC content for category detection
        $tcContent = $import->tc_file_content ?? '';
        
        // ✅ Build categories from the actual content
        $categories = [];
        
        // ✅ Check for Hotels/Cruises in content
        if (stripos($tcContent, 'Hotels/Cruises') !== false || 
            stripos($tcContent, 'Hotels') !== false) {
            $categories[] = 'Hotels/Cruises';
        }
        
        // ✅ Check for Transport
        if (stripos($tcContent, 'Transport') !== false) {
            $categories[] = 'Transport';
        }
        
        // ✅ Check for Attraction
        if (stripos($tcContent, 'Attraction') !== false) {
            $categories[] = 'Attraction';
        }
        
        // ✅ Check for Tour Transfers
        if (stripos($tcContent, 'Tour Transfers') !== false) {
            $categories[] = 'Tour Transfers';
        }
        
        // ✅ Check for Meals
        if (stripos($tcContent, 'Meals') !== false) {
            $categories[] = 'Meals';
        }
        
        // ✅ Check for Other Rates
        if (stripos($tcContent, 'Other Rates') !== false) {
            $categories[] = 'Other Rates';
        }
        
        // ✅ If no categories found, default to 'Tour Package'
        if (empty($categories)) {
            $categories[] = 'Tour Package';
        }
        
        // ✅ Convert to string for 'category' field
        $categoryString = implode(', ', $categories);
        
        Log::info("📊 Detected Categories from content: " . json_encode($categories));
        Log::info("📊 Category String: " . $categoryString);
         $salesPerson = $data['sales_person'] ?? null;
        $guestId = $data['guest_id'] ?? null;
        
        // ✅ If sales person not extracted, try from folder name
        if (!$salesPerson) {
            $salesPerson = $this->extractFileHandlerFromFolderName($import->folder_name);
        }
        
        // ✅ If guest ID not extracted, try from content
        if (!$guestId) {
            $openAI = app(\App\Services\OpenAIService::class);
            $guestId = $openAI->extractGuestIdFromText($tcContent);
        }
        $record = PnlRecord::create([
            'invoice_number' => $import->invoice_number,
            'tour_ref' => $data['tour_ref'] ?? 'NA',
            'agent_name' => $data['agent_name'] ?? null,
            'guest_name' => $data['guest_name'] ?? null,
            'vendor_name' => $data['agent_name'] ?? null,
            'travel_start_date' => $this->normalizeDate($data['arrival_date'] ?? null),
            'travel_end_date' => $this->normalizeDate($data['departure_date'] ?? null),
            
            'amount' => $totalAmount,
            'currency' => $currency,
            'pax_count' => $data['guest_count'] ?? 1,
            'file_handler' => $data['file_handler'] ?? null,
            'sales_person' => $data['sales_person'] ?? null,
            'country_code' => $import->country_code,
            
            // ✅✅✅ FIX: Store both formats with detected categories
            'category' => $categoryString,        // String format
            'categories' => $categories,          // JSON array format
            
            'status' => 'pending',
            'processing_status' => 'processing',
            'source' => 'onedrive',
            'folder_name' => $import->folder_name,
            'hotel_name' => $data['hotel_name'] ?? null,
            'meal_plan' => $data['meal_plan'] ?? null,
            'nights' => $data['nights'] ?? null,
            'staging_import_id' => $import->id,
            
            'message_id' => 'ONEDRIVE_' . $import->id . '_' . time(),
            'subject' => $import->folder_name,
            'body' => $tcContent,
            'body_hash' => md5($tcContent),
            'received_at' => now(),
            'from_email' => 'onedrive@aahaas.com',
            'from_name' => 'OneDrive Import',
            'read_status' => 'read',
            'is_tour_confirmation' => true,
            'has_attachments' => false,
        ]);
        
        Log::info("✅ Created P&L record: {$import->invoice_number} (ID: {$record->id})");
        Log::info("💰 Amount: {$totalAmount}, Categories: " . json_encode($categories));
        return $record;
        
    } catch (\Exception $e) {
        Log::error("Failed to create P&L record: " . $e->getMessage());
        return null;
    }
}
/**
 * Create P&L Items - DIRECT PARSING FROM TC CONTENT
 */
protected function createPnLItems($data, $record)
{
    try {
        
       if ($record->country_code === 'LK') {
            // ✅ Use the SriLankaPnLParser for LK TC/P&L files
            $lkParser = new \App\Services\SriLankaPnLParser();
            
            // Get the TC content from the import
            $import = \App\Models\OneDriveImport::find($record->staging_import_id);
            if ($import && $import->tc_file_content) {
                $record->body = $import->tc_file_content; // Set body for parser
            }
            
            $lkParser->parseAndSaveItems($record);
            Log::info("✅ LK P&L items parsed and saved for record: {$record->id}");
            return;
        }
        $itemsCreated = 0;
        
        // Get the full TC content from the import record
        $import = \App\Models\OneDriveImport::find($record->staging_import_id);
        if (!$import) {
            Log::warning("⚠️ No import record found for P&L items: {$record->id}");
            return;
        }
        
        $tcContent = $import->tc_file_content;
        $totalAmount = $record->total_amount ?? 0;
        $currency = $record->currency ?? 'MYR';
        
        Log::info("📄 Creating P&L items for: {$record->invoice_number}");
        Log::info("📄 TC Content length: " . strlen($tcContent));
        
        // ✅ 1. Create HOTEL item (if hotel_name exists and is valid)
        if (!empty($data['hotel_name']) && !is_numeric($data['hotel_name']) && $data['hotel_name'] !== '4' && $data['hotel_name'] !== '3' && $data['hotel_name'] !== '2' && $data['hotel_name'] !== '1') {
            PnlItem::create([
                'pnl_record_id' => $record->id,
                'type' => 'HOTEL',
                'service_name' => $data['hotel_name'],
                'amount_original' => $totalAmount,
                'currency' => $currency,
                'start_date' => $record->travel_start_date,
                'end_date' => $record->travel_end_date,
                'nights' => $data['nights'] ?? null,
                'item_details' => json_encode([
                    'meal_plan' => $data['meal_plan'] ?? null,
                    'room_type' => $data['room_type'] ?? null,
                    'pax' => $record->pax_count,
                ])
            ]);
            $itemsCreated++;
            Log::info("✅ Created HOTEL item: {$data['hotel_name']}");
        }
        
        // ✅ 2. Parse Transport items from TC content
        if (preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $tcContent, $sectionMatch)) {
            $section = $sectionMatch[1];
            $lines = explode("\n", $section);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Skip headers and totals
                if (preg_match('/EXPENSE|DISTANCE|DAYS|RATE|TOTAL|Total Transport|Meal Transport/i', $line)) continue;
                
                // Pattern: "Transport (PVT)Kuala Lumpur International Airport - Kuala Lumpur 90.00"
                if (preg_match('/^([A-Za-z\s\(\)\-]+?)\s+([\d,]+\.\d{2})$/', $line, $match)) {
                    $serviceName = trim($match[1]);
                    $amount = floatval(str_replace(',', '', $match[2]));
                    
                    if ($amount > 0 && !empty($serviceName) && !preg_match('/Total|TOTAL/i', $serviceName)) {
                        PnlItem::create([
                            'pnl_record_id' => $record->id,
                            'type' => 'TRANSPORT',
                            'service_name' => $serviceName,
                            'amount_original' => $amount,
                            'currency' => $currency,
                            'start_date' => $record->travel_start_date,
                            'end_date' => $record->travel_end_date,
                            'item_details' => json_encode(['remarks' => $serviceName])
                        ]);
                        $itemsCreated++;
                        Log::info("✅ Created TRANSPORT item: {$serviceName} - {$amount}");
                    }
                }
                // Pattern with pipe: | Transport ... | 90.00 |
                elseif (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d,]+\.\d{2})\s*\|/', $line, $match)) {
                    $serviceName = trim($match[1]);
                    $amount = floatval(str_replace(',', '', $match[2]));
                    
                    if ($amount > 0 && !empty($serviceName) && !preg_match('/Total|TOTAL/i', $serviceName)) {
                        PnlItem::create([
                            'pnl_record_id' => $record->id,
                            'type' => 'TRANSPORT',
                            'service_name' => $serviceName,
                            'amount_original' => $amount,
                            'currency' => $currency,
                            'start_date' => $record->travel_start_date,
                            'end_date' => $record->travel_end_date,
                            'item_details' => json_encode(['remarks' => $serviceName])
                        ]);
                        $itemsCreated++;
                        Log::info("✅ Created TRANSPORT item: {$serviceName} - {$amount}");
                    }
                }
            }
        }
        
        // ✅ 3. Parse Attraction items from TC content
        if (preg_match('/Attraction(.*?)(?:Tour Transfers|Meals|Other Rates|$)/is', $tcContent, $sectionMatch)) {
            $section = $sectionMatch[1];
            $lines = explode("\n", $section);
            
            $adultCount = $data['guest_count'] ?? 1;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Skip headers and totals
                if (preg_match('/#Day|City|Attraction|Adult|Child|Total|DAY/i', $line)) continue;
                
                // Pattern: "Day 1 Kuala Lumpur Putrajaya Sightseeing Joy Cruiser Ticket only 30"
                if (preg_match('/Day\s*\d+\s+([A-Za-z\s]+)\s+([A-Za-z\s]+?)\s+(\d+)$/i', $line, $match)) {
                    $city = trim($match[1]);
                    $attraction = trim($match[2]);
                    $rate = floatval($match[3]);
                    $amount = $rate * $adultCount;
                    
                    if ($amount > 0) {
                        PnlItem::create([
                            'pnl_record_id' => $record->id,
                            'type' => 'ATTRACTION',
                            'service_name' => $attraction,
                            'amount_original' => $amount,
                            'currency' => $currency,
                            'start_date' => $record->travel_start_date,
                            'end_date' => $record->travel_end_date,
                            'item_details' => json_encode([
                                'remarks' => "{$city} - {$attraction}",
                                'rate' => $rate,
                                'pax' => $adultCount
                            ])
                        ]);
                        $itemsCreated++;
                        Log::info("✅ Created ATTRACTION item: {$attraction} - {$amount}");
                    }
                }
            }
        }
        
        // ✅ 4. Parse Tour Transfer items from TC content
        if (preg_match('/Tour Transfers(.*?)(?:Attraction|Meals|Other Rates|$)/is', $tcContent, $sectionMatch)) {
            $section = $sectionMatch[1];
            $lines = explode("\n", $section);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Skip headers and totals
                if (preg_match('/#Day|City|Attraction|Adult|Child|Transfer|Total/i', $line)) continue;
                
                // Pattern: "Day 1 Enroute Breakfast and Putrajaya Sightseeing Joy Cruiser Ticket 20"
                if (preg_match('/Day\s*\d+\s+([A-Za-z\s]+?)\s+(\d+)$/i', $line, $match)) {
                    $serviceName = trim($match[1]);
                    $amount = floatval($match[2]);
                    
                    if ($amount > 0 && !empty($serviceName)) {
                        PnlItem::create([
                            'pnl_record_id' => $record->id,
                            'type' => 'TOUR TRANSFER',
                            'service_name' => $serviceName,
                            'amount_original' => $amount,
                            'currency' => $currency,
                            'start_date' => $record->travel_start_date,
                            'end_date' => $record->travel_end_date,
                            'item_details' => json_encode(['remarks' => $serviceName])
                        ]);
                        $itemsCreated++;
                        Log::info("✅ Created TOUR TRANSFER item: {$serviceName} - {$amount}");
                    }
                }
            }
        }
        
        // ✅ 5. Parse Meals items from TC content
        if (preg_match('/Meals(.*?)(?:Transport|Other Rates|$)/is', $tcContent, $sectionMatch)) {
            $section = $sectionMatch[1];
            $lines = explode("\n", $section);
            
            $adultCount = $data['guest_count'] ?? 1;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Skip headers and totals
                if (preg_match('/Pax|Rate|Total|Total Meal/i', $line)) continue;
                
                // Pattern: "Breakfast on arrival day (Putrajaya) 30"
                if (preg_match('/^([A-Za-z\s\(\)]+?)\s+(\d+)$/i', $line, $match)) {
                    $serviceName = trim($match[1]);
                    $rate = floatval($match[2]);
                    $amount = $rate * $adultCount;
                    
                    if ($amount > 0 && !empty($serviceName)) {
                        PnlItem::create([
                            'pnl_record_id' => $record->id,
                            'type' => 'MEALS',
                            'service_name' => $serviceName,
                            'amount_original' => $amount,
                            'currency' => $currency,
                            'start_date' => $record->travel_start_date,
                            'end_date' => $record->travel_end_date,
                            'item_details' => json_encode(['remarks' => $serviceName, 'pax' => $adultCount])
                        ]);
                        $itemsCreated++;
                        Log::info("✅ Created MEALS item: {$serviceName} - {$amount}");
                    }
                }
            }
        }
        
        Log::info("✅ Created {$itemsCreated} P&L items for record: {$record->id}");
        
    } catch (\Exception $e) {
        Log::error("Failed to create P&L items: " . $e->getMessage());
        Log::error($e->getTraceAsString());
    }
}

/**
 * ✅ Extract Transport items from TC data
 */
protected function extractTransportFromTC($data)
{
    $items = [];
    $text = $data['tc_content'] ?? '';
    
    if (empty($text)) return $items;
    
    // Look for Transport section
    if (preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip headers and totals
            if (preg_match('/EXPENSE|DISTANCE|DAYS|RATE|TOTAL|Total Transport/i', $line)) continue;
            
            // Pattern: "Transport (PVT)Kuala Lumpur International Airport - Kuala Lumpur 90.00"
            if (preg_match('/^([A-Za-z\s\(\)\-]+?)\s+([\d,]+\.\d{2})$/', $line, $match)) {
                $serviceName = trim($match[1]);
                $amount = floatval(str_replace(',', '', $match[2]));
                
                if ($amount > 0 && !empty($serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => ['remarks' => $serviceName]
                    ];
                }
            }
            // Pattern: "| Transport (PVT)Kuala Lumpur International Airport - Kuala Lumpur | 90.00 |"
            elseif (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d,]+\.\d{2})\s*\|/', $line, $match)) {
                $serviceName = trim($match[1]);
                $amount = floatval(str_replace(',', '', $match[2]));
                
                if ($amount > 0 && !empty($serviceName) && 
                    !preg_match('/Total|TOTAL/i', $serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => ['remarks' => $serviceName]
                    ];
                }
            }
        }
    }
    
    return $items;
}

/**
 * ✅ Extract Attraction items from TC data
 */
protected function extractAttractionFromTC($data)
{
    $items = [];
    $text = $data['tc_content'] ?? '';
    
    if (empty($text)) return $items;
    
    // Look for Attraction section
    if (preg_match('/Attraction(.*?)(?:Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);
        
        // Get adult count from data
        $adultCount = $data['guest_count'] ?? 2;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip headers and totals
            if (preg_match('/#Day|City|Attraction|Adult|Child|Total/i', $line)) continue;
            
            // Pattern: "Day 1 Kuala Lumpur Putrajaya Sightseeing Joy Cruiser Ticket only 30"
            if (preg_match('/Day\s*\d+\s+([A-Za-z\s]+)\s+([A-Za-z\s]+?)\s+(\d+)$/i', $line, $match)) {
                $city = trim($match[1]);
                $attraction = trim($match[2]);
                $rate = floatval($match[3]);
                $amount = $rate * $adultCount;
                
                if ($amount > 0) {
                    $items[] = [
                        'service_name' => $attraction,
                        'amount' => $amount,
                        'details' => [
                            'remarks' => "{$city} - {$attraction}",
                            'rate' => $rate,
                            'pax' => $adultCount
                        ]
                    ];
                }
            }
            // Pattern with pipe: | Day 1 | Kuala Lumpur | Putrajaya Sightseeing | 30 |
            elseif (preg_match('/\|\s*Day\s*\d+\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([\d,]+\.?\d*)\s*\|/', $line, $match)) {
                $city = trim($match[1]);
                $attraction = trim($match[2]);
                $rate = floatval(str_replace(',', '', $match[3]));
                $amount = $rate * $adultCount;
                
                if ($amount > 0) {
                    $items[] = [
                        'service_name' => $attraction,
                        'amount' => $amount,
                        'details' => [
                            'remarks' => "{$city} - {$attraction}",
                            'rate' => $rate,
                            'pax' => $adultCount
                        ]
                    ];
                }
            }
        }
    }
    
    return $items;
}

/**
 * ✅ Extract Tour Transfer items from TC data
 */
protected function extractTourTransferFromTC($data)
{
    $items = [];
    $text = $data['tc_content'] ?? '';
    
    if (empty($text)) return $items;
    
    // Look for Tour Transfers section
    if (preg_match('/Tour Transfers(.*?)(?:Attraction|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);
        
        $adultCount = $data['guest_count'] ?? 2;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip headers and totals
            if (preg_match('/#Day|City|Attraction|Adult|Child|Transfer|Total/i', $line)) continue;
            
            // Pattern: "Day 1 Enroute Breakfast and Putrajaya Sightseeing Joy Cruiser Ticket 20"
            if (preg_match('/Day\s*\d+\s+([A-Za-z\s]+?)\s+(\d+)$/i', $line, $match)) {
                $serviceName = trim($match[1]);
                $rate = floatval($match[2]);
                $amount = $rate * $adultCount;
                
                if ($amount > 0) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => ['remarks' => $serviceName]
                    ];
                }
            }
        }
    }
    
    return $items;
}

/**
 * ✅ Extract Meals items from TC data
 */
protected function extractMealsFromTC($data)
{
    $items = [];
    $text = $data['tc_content'] ?? '';
    
    if (empty($text)) return $items;
    
    // Look for Meals section
    if (preg_match('/Meals(.*?)(?:Transport|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);
        
        $adultCount = $data['guest_count'] ?? 2;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Skip headers and totals
            if (preg_match('/Pax|Rate|Total|Total Meal/i', $line)) continue;
            
            // Pattern: "Breakfast on arrival day (Putrajaya) 30"
            if (preg_match('/^([A-Za-z\s\(\)]+?)\s+(\d+)$/i', $line, $match)) {
                $serviceName = trim($match[1]);
                $rate = floatval($match[2]);
                $amount = $rate * $adultCount;
                
                if ($amount > 0) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => ['remarks' => $serviceName]
                    ];
                }
            }
            // Pattern with pipe: | Breakfast on arrival day | 30 |
            elseif (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d,]+\.?\d*)\s*\|/', $line, $match)) {
                $serviceName = trim($match[1]);
                $rate = floatval(str_replace(',', '', $match[2]));
                $amount = $rate * $adultCount;
                
                if ($amount > 0 && !preg_match('/Total|TOTAL/i', $serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => ['remarks' => $serviceName]
                    ];
                }
            }
        }
    }
    
    return $items;
}

protected function normalizeDate($date)
{
    if (!$date) {
        return null;
    }
    
    // If it's already in Y-m-d format, return as is
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    
    try {
        // Try to parse any date format
        $timestamp = strtotime($date);
        if ($timestamp && $timestamp > 0) {
            return date('Y-m-d', $timestamp);
        }
        return null;
    } catch (\Exception $e) {
        Log::error("Date normalization failed: " . $e->getMessage());
        return null;
    }
}
public function getFolderContents($path)
{
    try {
        // ✅ Clean the path - remove any leading/trailing slashes
        $cleanPath = trim($path, '/');
        $encodedPath = str_replace(' ', '%20', $cleanPath);
        
        // ✅ For Sri Lanka, use drive ID
        if (strpos($cleanPath, 'SL Share Drive') !== false || 
            strpos($cleanPath, 'SL Share Drive_') !== false) {
            
            Log::info("🔍 Using Drive ID for Sri Lanka: {$cleanPath}");
            
            $graphUrl = $this->baseUrl . "/drives/{$this->sriLankaDriveId}/root:/{$encodedPath}:/children";
            Log::info("📡 Graph URL: {$graphUrl}");
            
            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->get($graphUrl);
            
            if ($response->ok()) {
                $data = $response->json();
                Log::info("✅ Found " . count($data['value'] ?? []) . " items");
                return $data['value'] ?? [];
            }
            
            Log::warning("⚠️ Drive API failed: " . $response->status());
            Log::warning("Response: " . substr($response->body(), 0, 200));
            
            // ✅ Fallback: Try without the drive ID (for debugging)
            $fallbackUrl = $this->baseUrl . "/users/{$this->userEmail}/drive/root:/{$encodedPath}:/children";
            Log::info("📡 Fallback URL: {$fallbackUrl}");
            
            $fallbackResponse = Http::withToken($this->accessToken)
                ->timeout(30)
                ->get($fallbackUrl);
            
            if ($fallbackResponse->ok()) {
                Log::info("✅ Fallback API success!");
                return $fallbackResponse->json()['value'] ?? [];
            }
            
            return [];
        }
        
        // ✅ Default: Try user drive
        $url = $this->baseUrl . "/users/{$this->userEmail}/drive/root:/{$encodedPath}:/children";
        
        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->get($url);
        
        if ($response->ok()) {
            return $response->json()['value'] ?? [];
        }
        
        Log::warning("⚠️ Failed to get folder contents: {$path}");
        return [];
        
    } catch (\Exception $e) {
        Log::error("Error getting folder contents: " . $e->getMessage());
        return [];
    }
}

  /**
 * Generate invoice from record - UPDATED
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
        
        // ✅ FIRST: Try to find matching email
        $email = \App\Models\IncomingEmail::where('invoice_number', $record->invoice_number)->first();
        
        if ($email) {
            // ✅ Generate from email if found
            $invoiceService = app(\App\Services\InvoiceGenerationService::class);
            $invoice = $invoiceService->generateFromEmail($email);
            
            if ($invoice) {
                Log::info("✅ Generated invoice from email: {$invoice->invoice_number}");
                return;
            }
        }
        
        // ✅ SECOND: Generate directly from PnL Record (NEW!)
        Log::info("ℹ️ No email found, generating invoice directly from PnL record: {$record->invoice_number}");
        
        $invoice = $this->generateInvoiceFromPnLRecord($record);
        
        if ($invoice) {
            Log::info("✅ Generated invoice from PnL record: {$invoice->invoice_number}");
        }
        
    } catch (\Exception $e) {
        Log::error("Failed to generate invoice: " . $e->getMessage());
    }
}

/**
 * ✅ NEW: Generate invoice directly from PnL Record
 */
protected function generateInvoiceFromPnLRecord($record)
{
    try {
        // Get extracted data from staging import
        $import = \App\Models\OneDriveImport::where('invoice_number', $record->invoice_number)
            ->where('country_code', $record->country_code)
            ->first();
        
        if (!$import) {
            Log::warning("⚠️ No staging import found for: {$record->invoice_number}");
            return null;
        }
        
        $data = $import->extracted_data;
        
        // ✅ Build invoice data from PnL record
        $invoiceData = [
            'invoice_number' => $record->invoice_number,
            'tour_ref' => $record->tour_ref ?? $data['tour_ref'] ?? 'NA',
            'agent_name' => $record->agent_name ?? $data['agent_name'] ?? 'Unknown',
            'guest_name' => $record->guest_name ?? $data['guest_name'] ?? 'N/A',
            'travel_start_date' => $record->travel_start_date ?? $data['arrival_date'] ?? null,
            'travel_end_date' => $record->travel_end_date ?? $data['departure_date'] ?? null,
            'total_amount' => $record->total_amount ?? $data['total_amount'] ?? 0,
            'currency' => $record->currency ?? $data['currency'] ?? 'MYR',
            'pax_count' => $record->pax_count ?? $data['guest_count'] ?? 1,
            'country_code' => $record->country_code,
            'hotel_name' => $record->hotel_name ?? $data['hotel_name'] ?? null,
            'meal_plan' => $record->meal_plan ?? $data['meal_plan'] ?? null,
            'nights' => $record->nights ?? $data['nights'] ?? null,
            'folder_name' => $record->folder_name ?? $import->folder_name,
        ];
        
        Log::info("📝 Creating invoice from PnL record: " . json_encode($invoiceData));
        
        // ✅ Create invoice directly
        $invoice = \App\Models\GeneratedInvoice::create([
            'invoice_number' => $invoiceData['invoice_number'],
            'tour_ref' => $invoiceData['tour_ref'],
            'agent_name' => $invoiceData['agent_name'],
            'guest_name' => $invoiceData['guest_name'],
            'travel_start_date' => $invoiceData['travel_start_date'],
            'travel_end_date' => $invoiceData['travel_end_date'],
            'total_amount' => $invoiceData['total_amount'],
            'currency' => $invoiceData['currency'],
            'pax_count' => $invoiceData['pax_count'],
            'country_code' => $invoiceData['country_code'],
            'hotel_name' => $invoiceData['hotel_name'],
            'meal_plan' => $invoiceData['meal_plan'],
            'nights' => $invoiceData['nights'],
            'folder_name' => $invoiceData['folder_name'],
            'status' => 'pending',
            'source' => 'onedrive',
            'pnl_record_id' => $record->id,
            'staging_import_id' => $import->id,
              'email_id' => null,
        ]);
        
        Log::info("✅ Created invoice: {$invoice->invoice_number} (ID: {$invoice->id})");
        
        // ✅ Send invoice email
        // $this->sendInvoiceEmail($invoice);
        
        return $invoice;
        
    } catch (\Exception $e) {
        Log::error("Failed to generate invoice from PnL record: " . $e->getMessage());
        return null;
    }
}

/**
 * ✅ Send invoice email
 */
// protected function sendInvoiceEmail($invoice)
// {
//     try {
//         $emailType = 'credit';
        
//         if ($invoice->is_revision) {
//             $emailType = 'revision';
//         } elseif ($invoice->invoice_type == 'non_credit') {
//             $emailType = 'non_credit';
//         }
        
//         // Send email
//         \Illuminate\Support\Facades\Mail::to('kevinraj@aahaas.com')
//             ->cc('raja.lakshmi@aahaas.com')
//             ->send(new \App\Mail\InvoiceMail($invoice, $emailType));
        
//         Log::info("📧 Invoice email sent for: {$invoice->invoice_number}");
        
//     } catch (\Exception $e) {
//         Log::error("❌ Failed to send invoice email: " . $e->getMessage());
//     }
// }

/**
 * Create P&L items for Sri Lanka format
 */
protected function createPnLItemsForLK($data, $record)
{
    // Use record's body instead of fetching import
    $tcContent = $record->body ?? '';
    $currency = $record->currency ?? 'USD';
    $totalAmount = $record->amount ?? 0;

    Log::info("📄 Creating LK P&L items for: {$record->invoice_number} (body length: " . strlen($tcContent) . ")");

    // ✅ 1. Extract Hotels
    $hotels = $this->extractLKHotels($tcContent);
    foreach ($hotels as $hotel) {
        PnlItem::create([
            'pnl_record_id' => $record->id,
            'type' => 'HOTEL',
            'service_name' => $hotel['name'],
            'amount_original' => $hotel['amount'],
            'currency' => $currency,
            'start_date' => $record->travel_start_date,
            'end_date' => $record->travel_end_date,
            'nights' => $hotel['nights'] ?? null,
            'item_details' => json_encode(['remarks' => $hotel['name']])
        ]);
        Log::info("✅ LK HOTEL: {$hotel['name']} - \${$hotel['amount']}");
    }

    // ✅ 2. Extract Transport
    $transportItems = $this->extractLKTransport($tcContent);
    foreach ($transportItems as $item) {
        PnlItem::create([
            'pnl_record_id' => $record->id,
            'type' => 'TRANSPORT',
            'service_name' => $item['service_name'],
            'amount_original' => $item['amount'],
            'currency' => $currency,
            'start_date' => $record->travel_start_date,
            'end_date' => $record->travel_end_date,
            'item_details' => json_encode([
                'remarks' => $item['details']['remarks'] ?? $item['service_name'],
                'distance_days' => $item['details']['distance_days'] ?? null,
                'rate' => $item['details']['rate'] ?? null,
            ])
        ]);
        Log::info("✅ LK TRANSPORT: {$item['service_name']} - \${$item['amount']}");
    }

    // ✅ 3. Extract Other Rates (if any)
    $otherItems = $this->extractLKOtherRates($tcContent);
    foreach ($otherItems as $item) {
        PnlItem::create([
            'pnl_record_id' => $record->id,
            'type' => 'OTHER RATES',
            'service_name' => $item['service_name'],
            'amount_original' => $item['amount'],
            'currency' => $currency,
            'start_date' => $record->travel_start_date,
            'end_date' => $record->travel_end_date,
            'item_details' => json_encode(['remarks' => $item['details']['remarks'] ?? $item['service_name']])
        ]);
        Log::info("✅ LK OTHER RATES: {$item['service_name']} - \${$item['amount']}");
    }

    // ✅ 4. Fallback: if no items and totalAmount > 0, create a single item
    if (empty($hotels) && empty($transportItems) && empty($otherItems) && $totalAmount > 0) {
        PnlItem::create([
            'pnl_record_id' => $record->id,
            'type' => 'TOUR PACKAGE',
            'service_name' => 'Total Tour Package',
            'amount_original' => $totalAmount,
            'currency' => $currency,
            'start_date' => $record->travel_start_date,
            'end_date' => $record->travel_end_date,
            'item_details' => json_encode(['remarks' => 'Total package cost'])
        ]);
        Log::info("✅ LK FALLBACK: Total Tour Package - \${$totalAmount}");
    } else {
        Log::info("✅ LK items created: Hotels=" . count($hotels) . ", Transport=" . count($transportItems) . ", Other=" . count($otherItems));
    }
}

/**
 * Extract hotels from Sri Lanka TC content (Hotels/Cruises section)
 */
protected function extractLKHotels($text)
{
    $hotels = [];

    // Look for "Hotels/Cruises" section
    if (preg_match('/Hotels\/Cruises(.*?)(?:Transport|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip header lines
            if (preg_match('/Best|Westem|Total|Hotel|Name/i', $line)) continue;

            // Try to match: "Best Western 50.00"
            if (preg_match('/^([A-Za-z\s]+)\s+([\d,]+\.?\d*)$/', $line, $match)) {
                $name = trim($match[1]);
                $amount = floatval(str_replace(',', '', $match[2]));
                if ($amount > 0 && !empty($name) && !is_numeric($name)) {
                    $hotels[] = [
                        'name' => $name,
                        'amount' => $amount,
                        'nights' => null,
                    ];
                }
            }
            // Pipe format: | Best Western | 50.00 |
            elseif (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d,]+\.?\d*)\s*\|/', $line, $match)) {
                $name = trim($match[1]);
                $amount = floatval(str_replace(',', '', $match[2]));
                if ($amount > 0 && !empty($name) && !is_numeric($name)) {
                    $hotels[] = [
                        'name' => $name,
                        'amount' => $amount,
                        'nights' => null,
                    ];
                }
            }
        }
    }

    return $hotels;
}

/**
 * Extract transport items from Sri Lanka TC content
 */
protected function extractLKTransport($text)
{
    $items = [];

    // Look for Transport section
    if (preg_match('/Transport(.*?)(?:Attraction|Tour Transfers|Meals|Other Rates|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip header lines
            if (preg_match('/EXPENSE|DISTANCE|DAYS|RATE|TOTAL|Travel|Bata|Paging|Highway|Driver|Guide|Water/i', $line)) continue;

            // Pattern: Travel 980 8 1 1 5 0 0 0 0 330.90
            // Columns: Service, Distance, Days, Rate, ... Total
            if (preg_match('/^([A-Za-z\s]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d,]+\.?\d*)$/', $line, $match)) {
                $serviceName = trim($match[1]);
                $distance = floatval($match[2]);
                $days = floatval($match[3]);
                $rate = floatval($match[4]);
                $amount = floatval(str_replace(',', '', end($match)));

                if ($amount > 0 && !empty($serviceName) && !preg_match('/total|transport/i', $serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => [
                            'remarks' => "{$serviceName} - {$distance} KM / {$days} Days",
                            'distance_days' => $distance,
                            'rate' => $rate,
                        ]
                    ];
                }
            }
            // Pipe format: | Travel | 980 | 8 | 1 | 1 | 5 | 0 | 0 | 0 | 0 | 330.90 |
            elseif (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d,]+\.?\d*)\s*\|/', $line, $match)) {
                $serviceName = trim($match[1]);
                $distance = floatval($match[2]);
                $days = floatval($match[3]);
                $rate = floatval($match[4]);
                $amount = floatval(str_replace(',', '', end($match)));

                if ($amount > 0 && !empty($serviceName) && !preg_match('/total|transport/i', $serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => [
                            'remarks' => "{$serviceName} - {$distance} KM / {$days} Days",
                            'distance_days' => $distance,
                            'rate' => $rate,
                        ]
                    ];
                }
            }
        }
    }

    return $items;
}

/**
 * Extract other rates from Sri Lanka TC content (if any)
 */
protected function extractLKOtherRates($text)
{
    $items = [];

    // Look for "Other Rates" section
    if (preg_match('/Other Rates(.*?)(?:Attraction|Tour Transfers|Meals|Transport|$)/is', $text, $sectionMatch)) {
        $section = $sectionMatch[1];
        $lines = explode("\n", $section);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip header lines
            if (preg_match('/PAX|RATE|TOTAL|Item|Name/i', $line)) continue;

            // Try pipe format: | Service Name | Pax | Rate | Total |
            if (preg_match('/\|\s*([^|]+?)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d,]+\.?\d*)\s*\|/', $line, $match)) {
                $serviceName = trim($match[1]);
                $pax = floatval($match[2]);
                $rate = floatval($match[3]);
                $amount = floatval(str_replace(',', '', $match[4]));

                if ($amount > 0 && !empty($serviceName) && !preg_match('/total/i', $serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => [
                            'remarks' => "Pax: {$pax}, Rate: {$rate}",
                            'pax' => $pax,
                            'rate' => $rate,
                        ]
                    ];
                }
            }
            // Fallback: service name followed by amount
            elseif (preg_match('/^([A-Za-z\s]+)\s+([\d,]+\.?\d*)$/', $line, $match)) {
                $serviceName = trim($match[1]);
                $amount = floatval(str_replace(',', '', $match[2]));
                if ($amount > 0 && !empty($serviceName) && !preg_match('/total|rate|pax/i', $serviceName)) {
                    $items[] = [
                        'service_name' => $serviceName,
                        'amount' => $amount,
                        'details' => ['remarks' => $serviceName]
                    ];
                }
            }
        }
    }

    return $items;
}
/**
 * ✅ Extract Vietnam data using regex - FALLBACK for complex documents
 */
protected function extractVietnamDataRegex($content, $folderName, $invoiceNumber)
{
    $data = [
        'tour_ref' => $invoiceNumber,
        'agent_name' => null,
        'file_handler' => null,
        'sales_person' => null,
        'guest_id' => null,
        'guest_name' => null,
        'guest_count' => 1,
        'arrival_date' => null,
        'departure_date' => null,
        'nights' => null,
        'hotel_name' => null,
        'city' => null,
        'meal_plan' => null,
        'total_amount' => 0,
        'currency' => 'USD',
        'confirmation_number' => null,
        'flight_details' => []
    ];
    
    // Clean content
    $cleanContent = preg_replace('/\s+/', ' ', $content);
    $cleanContent = str_replace("\n", ' ', $cleanContent);
    
    // 1. ✅ Extract Total Tour Cost
    if (preg_match('/Total\s+Tour\s+Cost\s*USD?\s*([\d,]+\.\d{2})/i', $cleanContent, $match)) {
        $data['total_amount'] = floatval(str_replace(',', '', $match[1]));
        Log::info("💰 Found Total Tour Cost: {$data['total_amount']} USD");
    } elseif (preg_match('/Total\s+Tour\s+Cost\s*\$?\s*([\d,]+\.\d{2})/i', $cleanContent, $match)) {
        $data['total_amount'] = floatval(str_replace(',', '', $match[1]));
        Log::info("💰 Found Total Tour Cost: {$data['total_amount']} USD");
    } elseif (preg_match('/Total\s+Tour\s+Cost\s*USD?\s*([\d,]+)/i', $cleanContent, $match)) {
        $data['total_amount'] = floatval(str_replace(',', '', $match[1]));
        Log::info("💰 Found Total Tour Cost: {$data['total_amount']} USD");
    }
    
    // 2. ✅ Extract File Handler
    if (preg_match('/File\s+Handler\s*[:|\s]+([^\n,]+)/i', $cleanContent, $match)) {
        $data['file_handler'] = trim($match[1]);
        $data['sales_person'] = $data['file_handler'];
        Log::info("👤 Found File Handler: {$data['file_handler']}");
    }
    
    // If file handler not found, try from folder name
    if (!$data['file_handler']) {
        $fileHandler = $this->extractFileHandlerFromFolderName($folderName);
        if ($fileHandler) {
            $data['file_handler'] = $fileHandler;
            $data['sales_person'] = $fileHandler;
            Log::info("👤 File Handler from folder: {$fileHandler}");
        }
    }
    
    // 3. ✅ Extract Agent Name
    if (preg_match('/Agent\s*[:|\s]+([^\n,]+)/i', $cleanContent, $match)) {
        $agent = trim($match[1]);
        if (strlen($agent) < 30) {
            $data['agent_name'] = $agent;
            Log::info("🏢 Found Agent: {$data['agent_name']}");
        }
    }
    
    // 4. ✅ Extract Guest ID (MMT Booking ID)
    if (preg_match('/MMT\s*[-]\s*Booking\s*ID\s*[:|\s]+([A-Z0-9]+)/i', $cleanContent, $match)) {
        $data['guest_id'] = trim($match[1]);
        Log::info("🆔 Found MMT Booking ID: {$data['guest_id']}");
    }
    
    // 5. ✅ Extract Confirmation Number
    if (preg_match('/Confirmation\s+Number\s*[:|\s]+([A-Z0-9]+)/i', $cleanContent, $match)) {
        $data['confirmation_number'] = trim($match[1]);
        $data['tour_ref'] = $data['confirmation_number'];
        Log::info("📋 Found Confirmation Number: {$data['confirmation_number']}");
    }
    
    // 6. ✅ Extract Guest Name
    if (preg_match('/Guests?\s+Name\s*[:|\s]+([^\n,]+)/i', $cleanContent, $match)) {
        $data['guest_name'] = trim($match[1]);
        Log::info("👤 Found Guest Name: {$data['guest_name']}");
    } elseif (preg_match('/Lead\s+Passenger\s+Name\s*[:|\s]+([^\n,]+)/i', $cleanContent, $match)) {
        $data['guest_name'] = trim($match[1]);
        Log::info("👤 Found Lead Passenger: {$data['guest_name']}");
    }
    
    // 7. ✅ Extract Guest Count
    if (preg_match('/Total\s+Passenger\s*[:|\s]+(\d+)\s*Adults?/i', $cleanContent, $match)) {
        $data['guest_count'] = intval($match[1]);
        Log::info("👥 Found Guest Count: {$data['guest_count']}");
    } elseif (preg_match('/No\.?\s*of\s*Guests?\s*[:|\s]+(\d+)\s*Adults?/i', $cleanContent, $match)) {
        $data['guest_count'] = intval($match[1]);
        Log::info("👥 Found Guest Count: {$data['guest_count']}");
    }
    
    // 8. ✅ Extract Arrival Date
    if (preg_match('/Arrival\s+Date\s*[:|\s]+(\d{1,2})\s+([A-Za-z]{3}),?\s*(\d{4})/i', $cleanContent, $match)) {
        $day = intval($match[1]);
        $month = $this->getMonthNumber($match[2]);
        $year = intval($match[3]);
        if ($month) {
            $data['arrival_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
            Log::info("📅 Found Arrival Date: {$data['arrival_date']}");
        }
    }
    
    // 9. ✅ Extract Departure Date
    if (preg_match('/Departure\s+Date\s*[:|\s]+(\d{1,2})\s+([A-Za-z]{3}),?\s*(\d{4})/i', $cleanContent, $match)) {
        $day = intval($match[1]);
        $month = $this->getMonthNumber($match[2]);
        $year = intval($match[3]);
        if ($month) {
            $data['departure_date'] = sprintf("%04d-%02d-%02d", $year, $month, $day);
            Log::info("📅 Found Departure Date: {$data['departure_date']}");
        }
    }
    
    // 10. ✅ Calculate Nights
    if ($data['arrival_date'] && $data['departure_date']) {
        $start = strtotime($data['arrival_date']);
        $end = strtotime($data['departure_date']);
        if ($start && $end) {
            $data['nights'] = round(($end - $start) / (60 * 60 * 24));
            Log::info("🌙 Calculated Nights: {$data['nights']}");
        }
    }
    
    // 11. ✅ Extract Hotel Names (first hotel from the list)
    // Pattern: City name followed by Hotel name
    if (preg_match('/Hanoi\s+([A-Za-z\s\-]+Hotel\s+[A-Za-z\s\-]+)/i', $cleanContent, $match)) {
        $data['hotel_name'] = trim($match[1]);
        $data['city'] = 'Hanoi';
        Log::info("🏨 Found Hotel: {$data['hotel_name']} in {$data['city']}");
    } elseif (preg_match('/Da\s+Nang\s+([A-Za-z\s\-]+Hotel\s+[A-Za-z\s\-]+)/i', $cleanContent, $match)) {
        $data['hotel_name'] = trim($match[1]);
        $data['city'] = 'Da Nang';
        Log::info("🏨 Found Hotel: {$data['hotel_name']} in {$data['city']}");
    } elseif (preg_match('/Ho\s+Chi\s+Minh\s+City\s+([A-Za-z\s\-]+Hotel\s+[A-Za-z\s\-]+)/i', $cleanContent, $match)) {
        $data['hotel_name'] = trim($match[1]);
        $data['city'] = 'Ho Chi Minh City';
        Log::info("🏨 Found Hotel: {$data['hotel_name']} in {$data['city']}");
    }
    
    // 12. ✅ Extract City (if not found from hotel)
    if (!$data['city']) {
        if (preg_match('/\b(Hanoi|Da Nang|Ho Chi Minh City|HCMC|Danang)\b/i', $cleanContent, $match)) {
            $data['city'] = trim($match[1]);
            Log::info("📍 Found City: {$data['city']}");
        }
    }
    
    Log::info("📊 Vietnam Regex extracted: " . json_encode($data));
    return $data;
}
}