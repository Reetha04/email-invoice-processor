<?php
// app/Services/ExchangeRateService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ExchangeRateServiceLKR
{
    protected $baseUrl = 'https://api.exchangerate-api.com/v4/latest/USD';
    protected $cbslUrl = 'https://www.cbsl.gov.lk/api/exchange-rates';
    
    /**
     * Get exchange rate for USD to LKR
     */
    public function getUSDtoLKR()
    {
        // Try cache first
        return Cache::remember('exchange_rate_usd_lkr', 3600, function () {
            // Try CBSL first
            $rate = $this->getFromCBSL();
            
            // If CBSL fails, try fallback API
            if (!$rate) {
                $rate = $this->getFromFallbackAPI();
            }
            
            // If still fails, use default rate
            if (!$rate) {
                $rate = 330.28; // Default fallback
                Log::warning("Using default exchange rate: {$rate}");
            }
            
            Log::info("Exchange rate USD to LKR: {$rate}");
            return $rate;
        });
    }
    
    /**
     * Get exchange rate from CBSL Sri Lanka
     */
    protected function getFromCBSL()
    {
        try {
            // CBSL API endpoint for daily rates
            $response = Http::timeout(10)->get($this->cbslUrl);
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Look for USD rate
                if (isset($data['rates']) && is_array($data['rates'])) {
                    foreach ($data['rates'] as $rate) {
                        if (isset($rate['currency']) && strtoupper($rate['currency']) === 'USD') {
                            // Get the selling rate or buying rate
                            $sellRate = $rate['sell_rate'] ?? $rate['rate'] ?? null;
                            if ($sellRate) {
                                Log::info("CBSL exchange rate: {$sellRate}");
                                return floatval($sellRate);
                            }
                        }
                    }
                }
            }
            
            // If CBSL returns HTML or different format, try alternative
            Log::warning("CBSL API returned non-JSON response, trying alternative...");
            return $this->getFromCBSLScrape();
            
        } catch (\Exception $e) {
            Log::error("CBSL API error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Alternative: Scrape CBSL exchange rate page
     */
    protected function getFromCBSLScrape()
    {
        try {
            $url = 'https://www.cbsl.gov.lk/en/rates-and-indicators/exchange-rates/daily-buy-and-sell-exchange-rates';
            $response = Http::timeout(10)->get($url);
            
            if ($response->successful()) {
                $html = $response->body();
                
                // Look for USD rate in the table
                if (preg_match('/USD\s*<\/td>\s*<td[^>]*>([\d.]+)\s*<\/td>\s*<td[^>]*>([\d.]+)/i', $html, $match)) {
                    $sellRate = floatval($match[2]);
                    Log::info("CBSL scraped exchange rate: {$sellRate}");
                    return $sellRate;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("CBSL scrape error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Fallback API for exchange rate
     */
    protected function getFromFallbackAPI()
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl);
            
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['rates']['LKR'])) {
                    $rate = floatval($data['rates']['LKR']);
                    Log::info("Fallback exchange rate: {$rate}");
                    return $rate;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Fallback API error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Manually set exchange rate
     */
    public function setRate($rate)
    {
        Cache::put('exchange_rate_usd_lkr', floatval($rate), 3600);
        Log::info("Exchange rate manually set to: {$rate}");
        return $this;
    }
    
    /**
     * Clear cached exchange rate
     */
    public function clearCache()
    {
        Cache::forget('exchange_rate_usd_lkr');
        Log::info("Exchange rate cache cleared");
        return $this;
    }
}