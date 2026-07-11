<?php
// app/Services/OneDrivePathFinder.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneDrivePathFinder
{
    protected $accessToken;
    protected $userEmail;
    protected $baseUrl = 'https://graph.microsoft.com/v1.0';
    protected $sriLankaDriveId = 'b!50OxHDBzR0OL6moo_OLbEPPv-pKecbJNtUhLzvZUuX6Y6XRiW_09So2E3yephyiW';
    
    protected $sriLankaSiteId = 'aahaas.sharepoint.com,1cb143e7-7330-4347-8bea-6a28fce2db10,92faeff3-719e-4db2-b548-4bcef654b97e';

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
            return false;
        } catch (\Exception $e) {
            Log::error('OneDrive auth failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set user and re-authenticate
     */
    public function setUser($email)
    {
        $this->userEmail = $email;
        $this->authenticate();
        return $this;
    }

 public function findPnLFolderWithUser($country = 'MY')
    {
        try {
            $driveNames = [
                'MY' => 'Malaysia Drive',
                'SG' => 'Singapore Drive',
                'VN' => 'VN OPERATION',
                'LK' => 'SL Share Drive_',
            ];
            $driveName = $driveNames[$country] ?? 'Malaysia Drive';
            $year = date('Y');
            
            $countryConfigs = [
                'MY' => [
                    'emails' => ['geetha.lakshmi@aahaas.com', 'geetha_lakshmi@aahaas.com', 'accounts@aahaas.com'],
                    'base_path' => 'Reservation',
                    'is_sharepoint' => false,
                ],
                'SG' => [
                    'emails' => ['geetha.lakshmi@aahaas.com', 'geetha_lakshmi@aahaas.com', 'accounts@aahaas.com'],
                    'base_path' => 'Reservation',
                    'is_sharepoint' => false,
                ],
                'VN' => [
                    'emails' => ['pradeep.Reservation@aahaas.com', 'pradeep_reservation@aahaas.com', 'pradeep@aahaas.com'],
                    'base_path' => '',
                    'is_sharepoint' => false,
                ],
                'LK' => [
                    'emails' => ['accounts@aahaas.com'],
                    'base_path' => 'SL Share Drive_',
                    'is_sharepoint' => true,
                    'use_drive_id' => true,
                ],
            ];
            
            $config = $countryConfigs[$country] ?? $countryConfigs['MY'];
            $emails = $config['emails'];
            
            // ✅ For Sri Lanka, use the direct path with drive ID
            if ($country === 'LK') {
                $directPath = "{$config['base_path']}/{$year}"; // "SL Share Drive_/2026"
                
                Log::info("🔍 Trying Sri Lanka with drive ID: {$directPath}");
                $items = $this->getFolderContents($directPath);
                
                if (!empty($items)) {
                    Log::info("✅ Found year path with drive ID: {$directPath}");
                    return [
                        'path' => $directPath,
                        'user' => $this->userEmail,
                        'drive_id' => $this->sriLankaDriveId,
                    ];
                }
            }
            
            // ✅ For other countries
            foreach ($emails as $email) {
                $basePath = $config['base_path'];
                $directPath = "{$basePath}/{$driveName}/{$year}";
                
                Log::info("🔍 Trying with user: {$email} at path: {$directPath}");
                $this->setUser($email);
                
                $items = $this->getFolderContents($directPath);
                if (!empty($items)) {
                    Log::info("✅ Found year path with user: {$email}: {$directPath}");
                    return [
                        'path' => $directPath,
                        'user' => $email
                    ];
                }
            }
            
            Log::warning("⚠️ No folder found for country: {$country}");
            return null;
            
        } catch (\Exception $e) {
            Log::error("Path finder error: " . $e->getMessage());
            return null;
        }
    }
/**
 * Find the YEAR path (e.g., Reservation/Malaysia Drive/2026)
 */
protected function findYearPath($country)
{
    $driveNames = [
        'MY' => 'Malaysia Drive',
        'SG' => 'Singapore Drive',
        'VN' => 'Vietnam Drive',
        'LK' => 'Sri Lanka Drive',
    ];
    $driveName = $driveNames[$country] ?? 'Malaysia Drive';
    $year = date('Y');
    
    $paths = [
        "Reservation/{$driveName}/{$year}",
        "Reservation/{$driveName}",
        "{$driveName}/{$year}",
        "{$driveName}",
    ];
    
    foreach ($paths as $path) {
        $items = $this->getFolderContents($path);
        
        if (!empty($items)) {
            Log::info("✅ Found year path: {$path}");
            return $path;
        }
    }
    
    return null;
}
/**
 * Find the month path (e.g., Reservation/Malaysia Drive/2026/07 July)
 */
protected function findMonthPath($country)
{
    $driveNames = [
        'MY' => 'Malaysia Drive',
        'SG' => 'Singapore Drive',
        'VN' => 'Vietnam Drive',
        'LK' => 'Sri Lanka Drive',
    ];
    $driveName = $driveNames[$country] ?? 'Malaysia Drive';
    $year = date('Y');
    $month = date('m');
    
    $monthNames = [
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
    $monthName = $monthNames[$month] ?? '07 July';
    
    // ✅ CORRECT PATHS - Include the month folder
    $paths = [
        "Reservation/{$driveName}/{$year}/{$monthName}",
        "Reservation/{$driveName}/{$year}",
        "Reservation/{$driveName}",
        "{$driveName}/{$year}/{$monthName}",
    ];
    
    foreach ($paths as $path) {
        $items = $this->getFolderContents($path);
        
        if (!empty($items)) {
            Log::info("✅ Found month path: {$path}");
            return $path;
        }
    }
    
    return null;
}
    /**
     * Direct path search
     */
    protected function findDirectPath($country)
    {
        $driveNames = [
            'MY' => 'Malaysia Drive',
            'SG' => 'Singapore Drive',
            'VN' => 'Vietnam Drive',
            'LK' => 'Sri Lanka Drive',
        ];
        $driveName = $driveNames[$country] ?? 'Malaysia Drive';
        $year = date('Y');
        $month = date('m');
        
        $monthNames = [
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
        $monthName = $monthNames[$month] ?? '07 July';
        
        $today = (int)date('d');
        $startDate = max(11, $today);
        
        $paths = [
            "Reservation/{$driveName}/{$year}/{$monthName}/" . $startDate . ' July',
            "Reservation/{$driveName}/{$year}/{$monthName}/11 July",
            "Reservation/{$driveName}/{$year}/{$monthName}",
            "Reservation/{$driveName}/{$year}",
            "Reservation/{$driveName}",
            "{$driveName}/{$year}/{$monthName}/" . $startDate . ' July',
            "{$driveName}/{$year}/{$monthName}/11 July",
            "{$driveName}/{$year}/{$monthName}",
            "{$driveName}/{$year}",
            "{$driveName}",
        ];
        
        foreach ($paths as $path) {
            $items = $this->getFolderContents($path);
            
            if (!empty($items)) {
                Log::info("✅ Found: {$path}");
                return $path;
            }
        }
        
        return null;
    }

// In OneDrivePathFinder.php



 public function getFolderContents($path)
    {
        try {
            $encodedPath = str_replace(' ', '%20', $path);
            
            // ✅ For Sri Lanka, use drive ID
            if (strpos($path, 'SL Share Drive') !== false) {
                Log::info("🔍 Using Drive ID for Sri Lanka: {$path}");
                
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
}