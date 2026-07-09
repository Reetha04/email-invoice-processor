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

    /**
     * ✅ Find folder path and return user who has access
     */
/**
 * ✅ Find folder path and return user who has access - Return YEAR PATH
 */
public function findPnLFolderWithUser($country = 'MY')
{
    try {
        $geethaEmails = [
            'geetha.lakshmi@aahaas.com',
            'geetha_lakshmi@aahaas.com',
        ];
        
        foreach ($geethaEmails as $email) {
            Log::info("🔍 Trying with user: {$email}");
            $this->setUser($email);
            
            // ✅ Find the YEAR path (not a specific month)
            $path = $this->findYearPath($country);
            if ($path) {
                Log::info("✅ Found year path with user: {$email}");
                return [
                    'path' => $path,  // Returns: Reservation/Malaysia Drive/2026
                    'user' => $email
                ];
            }
        }
        
        // Fallback
        $this->setUser(env('ONEDRIVE_USER', 'accounts@aahaas.com'));
        $path = $this->findYearPath($country);
        if ($path) {
            return [
                'path' => $path,
                'user' => $this->userEmail
            ];
        }
        
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
            
            return [];
            
        } catch (\Exception $e) {
            Log::error("Error getting folder contents for {$this->userEmail}: " . $e->getMessage());
            return [];
        }
    }
}