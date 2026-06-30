<?php

namespace App\Services;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PnLDetailedExcelService
{
    private $exchangeRates = [
        'LK' => 330,
        'VN' => 25500,
        'SG' => 1,
        'MY' => 1,
    ];

    private $currencySymbols = [
        'LK' => 'LKR',
        'VN' => 'VND', 
        'SG' => 'SGD',
        'MY' => 'MYR',
    ];

    /**
     * Safe number formatting - converts string to float first
     */
    private function safeNumberFormat($value, $decimals = 2)
    {
        return number_format(floatval($value), $decimals);
    }

    /**
     * Get currency symbol for country code
     */
    public function getCurrencySymbol($countryCode)
    {
        return $this->currencySymbols[$countryCode] ?? 'USD';
    }

    /**
     * Generate Detailed P&L Excel for a record
     */
    public function generateDetailedPnL(PnlRecord $record)
    {
        try {
            $countryCode = $record->country_code ?? 'VN';
            $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
            $currencySymbol = $this->currencySymbols[$countryCode] ?? 'USD';
            
            // ✅ Only show local currency for SG and MY
            $showLocalCurrency = in_array($countryCode, ['SG', 'MY']);
            
            // Create new spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('PnL Details');
            
            // Set row counter
            $row = 1;
            
            // ========== SECTION 1: HEADER ==========
            $sheet->setCellValue("A{$row}", 'Profit & Loss Statement');
            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 16],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $row += 2;
            
            // Tour Details
            $sheet->setCellValue("A{$row}", 'Tour Reference:');
            $sheet->setCellValue("B{$row}", $record->tour_ref ?? '-');
            $sheet->setCellValue("D{$row}", 'Invoice Number:');
            $sheet->setCellValue("E{$row}", $record->invoice_number ?? '-');
            $row++;
            
            $sheet->setCellValue("A{$row}", 'Agent:');
            $sheet->setCellValue("B{$row}", $record->agent_name ?? '-');
            $sheet->setCellValue("D{$row}", 'Client:');
            $sheet->setCellValue("E{$row}", $record->vendor_name ?? '-');
            $row++;
            
            $sheet->setCellValue("A{$row}", 'Pax:');
            $sheet->setCellValue("B{$row}", $record->total_pax ?? 0);
            $sheet->setCellValue("D{$row}", 'Nights:');
            $sheet->setCellValue("E{$row}", $record->total_nights ?? 0);
            $row += 2;
            
            // ========== SECTION 2: HOTELS ==========
            $hotelItems = $record->items->where('type', 'HOTEL');
            
            if ($hotelItems->isNotEmpty()) {
                $sheet->setCellValue("A{$row}", 'HOTELS' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''));
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                ]);
                $row++;
                
                // Hotel Headers
                $hotelHeaders = ['Hotel Name', 'Room Type', 'Meal Plan', 'No. of Rooms', 'No. of Nights', 'Cost Per Night', 'Total Cost' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''), ''];
                $col = 'A';
                foreach ($hotelHeaders as $header) {
                    $sheet->setCellValue($col . $row, $header);
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF2']],
                    ]);
                    $col++;
                }
                $row++;
                
                foreach ($hotelItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $nights = $itemDetails['nights'] ?? 1;
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    $costPerNight = $nights > 0 ? abs($displayAmount) / $nights : abs($displayAmount);
                    
                    $sheet->setCellValue("A{$row}", $item->hotel_name ?? $item->service_name);
                    $sheet->setCellValue("B{$row}", 'Standard');
                    $sheet->setCellValue("C{$row}", 'BB');
                    $sheet->setCellValue("D{$row}", 1);
                    $sheet->setCellValue("E{$row}", $nights);
                    $sheet->setCellValue("F{$row}", $this->safeNumberFormat($costPerNight, 2));
                    $sheet->setCellValue("G{$row}", $this->safeNumberFormat(abs($displayAmount), 2));
                    
                    // Format as currency
                    $sheet->getStyle("F{$row}:G{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                    $row++;
                }
                
                // Hotel Grand Total
                $hotelTotal = $hotelItems->sum(function($item) use ($showLocalCurrency, $exchangeRate) {
                    $amount = abs(floatval($item->amount_original));
                    return $showLocalCurrency ? $amount * $exchangeRate : $amount;
                });
                $sheet->setCellValue("F{$row}", 'Grand Total');
                $sheet->setCellValue("G{$row}", $this->safeNumberFormat($hotelTotal, 2));
                $sheet->getStyle("F{$row}:G{$row}")->applyFromArray(['font' => ['bold' => true]]);
                $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                $row += 2;
            }
            
            // ========== SECTION 3: PRODUCTS/ATTRACTIONS ==========
            $attractionItems = $record->items->where('type', 'ATTRACTION');
            $tourTransferItems = $record->items->where('type', 'TOUR TRANSFER');
            $productItems = $attractionItems->merge($tourTransferItems);

            if ($productItems->isNotEmpty()) {
                $sheet->setCellValue("A{$row}", 'PRODUCTS & ATTRACTIONS' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''));
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '28A745']],
                ]);
                $row++;
                
                // Product Headers
                $productHeaders = ['Name of Product', 'Adult Count', 'Adult Rate', 'Child Count', 'Child Rate', 'No. of Package', 'Package Cost', 'Total' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : '')];
                $col = 'A';
                foreach ($productHeaders as $header) {
                    $sheet->setCellValue($col . $row, $header);
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF2']],
                    ]);
                    $col++;
                }
                $row++;
                
                foreach ($productItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $adultCount = $itemDetails['adult_count'] ?? $record->adult_count ?? 0;
                    $childCount = $itemDetails['child_count'] ?? $record->child_count ?? 0;
                    $adultRate = floatval($itemDetails['adult_rate'] ?? 0);
                    $childRate = floatval($itemDetails['child_rate'] ?? 0);
                    $transferAmount = floatval($itemDetails['transfer_amount'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    $pax = $itemDetails['pax'] ?? $record->total_pax ?? 0;
                    
                    if ($item->type === 'TOUR TRANSFER') {
                        if ($adultRate > 0 && $pax > 0) {
                            $sheet->setCellValue("A{$row}", $item->service_name);
                            $sheet->setCellValue("B{$row}", $adultCount);
                            $sheet->setCellValue("C{$row}", $this->safeNumberFormat($adultRate, 2));
                            $sheet->setCellValue("D{$row}", $childCount);
                            $sheet->setCellValue("E{$row}", $this->safeNumberFormat($childRate, 2));
                            $sheet->setCellValue("F{$row}", 0);
                            $sheet->setCellValue("G{$row}", 0);
                            $sheet->setCellValue("H{$row}", $this->safeNumberFormat(abs($displayAmount), 2));
                        } else if ($transferAmount > 0) {
                            $sheet->setCellValue("A{$row}", $item->service_name);
                            $sheet->setCellValue("B{$row}", 0);
                            $sheet->setCellValue("C{$row}", 0);
                            $sheet->setCellValue("D{$row}", 0);
                            $sheet->setCellValue("E{$row}", 0);
                            $sheet->setCellValue("F{$row}", 1);
                            $sheet->setCellValue("G{$row}", $this->safeNumberFormat($showLocalCurrency ? $transferAmount * $exchangeRate : $transferAmount, 2));
                            $sheet->setCellValue("H{$row}", $this->safeNumberFormat($showLocalCurrency ? $transferAmount * $exchangeRate : $transferAmount, 2));
                        }
                    } 
                    else if ($item->type === 'ATTRACTION') {
                        $sheet->setCellValue("A{$row}", $item->service_name);
                        $sheet->setCellValue("B{$row}", $adultCount);
                        $sheet->setCellValue("C{$row}", $this->safeNumberFormat($adultRate, 2));
                        $sheet->setCellValue("D{$row}", $childCount);
                        $sheet->setCellValue("E{$row}", $this->safeNumberFormat($childRate, 2));
                        $sheet->setCellValue("F{$row}", 0);
                        $sheet->setCellValue("G{$row}", 0);
                        $sheet->setCellValue("H{$row}", $this->safeNumberFormat(abs($displayAmount), 2));
                    }
                    
                    $sheet->getStyle("C{$row}:H{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                    $row++;
                }
                
                // Product Grand Total
                $productTotal = $productItems->sum(function($item) use ($showLocalCurrency, $exchangeRate) {
                    $amount = abs(floatval($item->amount_original));
                    return $showLocalCurrency ? $amount * $exchangeRate : $amount;
                });
                $sheet->setCellValue("G{$row}", 'Grand Total');
                $sheet->setCellValue("H{$row}", $this->safeNumberFormat($productTotal, 2));
                $sheet->getStyle("G{$row}:H{$row}")->applyFromArray(['font' => ['bold' => true]]);
                $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                $row += 2;
            }
            
            // ========== SECTION 4: OTHER RATES ==========
            $otherRateItems = $record->items->where('type', 'OTHER RATES');
            
            if ($otherRateItems->isNotEmpty()) {
                $sheet->setCellValue("A{$row}", 'OTHER RATES' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''));
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6F42C1']],
                ]);
                $row++;
                
                $otherHeaders = ['Service Name', 'Pax Type', 'Pax Count', 'Rate', 'Total' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''), '', '', ''];
                $col = 'A';
                foreach ($otherHeaders as $header) {
                    $sheet->setCellValue($col . $row, $header);
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF2']],
                    ]);
                    $col++;
                }
                $row++;
                
                foreach ($otherRateItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $paxType = $itemDetails['pax_type'] ?? 'adult';
                    $paxCount = $itemDetails['pax'] ?? 0;
                    $rate = floatval($itemDetails['rate'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    
                    $sheet->setCellValue("A{$row}", $item->service_name);
                    $sheet->setCellValue("B{$row}", ucfirst($paxType));
                    $sheet->setCellValue("C{$row}", $paxCount);
                    $sheet->setCellValue("D{$row}", $this->safeNumberFormat($rate, 2));
                    $sheet->setCellValue("E{$row}", $this->safeNumberFormat(abs($displayAmount), 2));
                    
                    $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                    $row++;
                }
                
                $otherTotal = $otherRateItems->sum(function($item) use ($showLocalCurrency, $exchangeRate) {
                    $amount = abs(floatval($item->amount_original));
                    return $showLocalCurrency ? $amount * $exchangeRate : $amount;
                });
                $sheet->setCellValue("D{$row}", 'Grand Total');
                $sheet->setCellValue("E{$row}", $this->safeNumberFormat($otherTotal, 2));
                $sheet->getStyle("D{$row}:E{$row}")->applyFromArray(['font' => ['bold' => true]]);
                $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                $row += 2;
            }
            
            // ========== SECTION 5: TRANSPORT ==========
            $transportItems = $record->items->where('type', 'TRANSPORT');

            if ($transportItems->isNotEmpty()) {
                $sheet->setCellValue("A{$row}", 'TRANSPORT' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''));
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC3545']],
                ]);
                $row++;
                
                $transportHeaders = ['Description', 'Unit', 'Count', 'Rate', 'Total' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''), '', '', ''];
                $col = 'A';
                foreach ($transportHeaders as $header) {
                    $sheet->setCellValue($col . $row, $header);
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF2']],
                    ]);
                    $col++;
                }
                $row++;
                
                $transportOrder = [
                    'Travel' => 1,
                    'Bata' => 2,
                    'Paging' => 3,
                    'Highway Charges' => 4,
                    'Driver Accomodation' => 5,
                    'Driver Accommodation' => 5,
                    'Guide Fee' => 6,
                    'Water Bottles' => 7,
                    'Other Cost' => 8,
                ];
                
                $sortedTransportItems = $transportItems->sortBy(function($item) use ($transportOrder) {
                    $serviceName = $item->service_name;
                    foreach ($transportOrder as $key => $order) {
                        if (stripos($serviceName, $key) !== false) {
                            return $order;
                        }
                    }
                    return 999;
                });
                
                foreach ($sortedTransportItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $unit = $itemDetails['unit_type'] ?? 'Days';
                    $count = $itemDetails['distance_days'] ?? 1;
                    $rate = floatval($itemDetails['rate'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    
                    $sheet->setCellValue("A{$row}", $item->service_name);
                    $sheet->setCellValue("B{$row}", $unit);
                    $sheet->setCellValue("C{$row}", $count);
                    $sheet->setCellValue("D{$row}", number_format($rate, 4));
                    $sheet->setCellValue("E{$row}", $this->safeNumberFormat(abs($displayAmount), 2));
                    
                    $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                    $row++;
                }
                
                $transportTotal = $transportItems->sum(function($item) use ($showLocalCurrency, $exchangeRate) {
                    $amount = abs(floatval($item->amount_original));
                    return $showLocalCurrency ? $amount * $exchangeRate : $amount;
                });
                $sheet->setCellValue("D{$row}", 'Grand Total');
                $sheet->setCellValue("E{$row}", $this->safeNumberFormat($transportTotal, 2));
                $sheet->getStyle("D{$row}:E{$row}")->applyFromArray(['font' => ['bold' => true]]);
                $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                $row += 2;
            }
            
            // ========== SECTION 6: MEALS ==========
            $mealItems = $record->items->where('type', 'MEALS');
            
            if ($mealItems->isNotEmpty()) {
                $sheet->setCellValue("A{$row}", 'MEALS' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''));
                $sheet->mergeCells("A{$row}:H{$row}");
                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '000000']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFC107']],
                ]);
                $row++;
                
                $mealHeaders = ['Meals', 'Meal Type', 'Adult Count', 'Adult Rate', 'Child Count', 'Child Rate', 'Total' . ($showLocalCurrency ? ' (' . $currencySymbol . ')' : ''), ''];
                $col = 'A';
                foreach ($mealHeaders as $header) {
                    $sheet->setCellValue($col . $row, $header);
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF2']],
                    ]);
                    $col++;
                }
                $row++;
                
                $pax = $record->total_pax ?? 0;
                foreach ($mealItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $adultRate = floatval($itemDetails['adult_rate'] ?? 0);
                    $childRate = floatval($itemDetails['child_rate'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    
                    $sheet->setCellValue("A{$row}", $item->service_name);
                    $sheet->setCellValue("B{$row}", 'Dinner');
                    $sheet->setCellValue("C{$row}", $pax);
                    $sheet->setCellValue("D{$row}", $this->safeNumberFormat($adultRate, 2));
                    $sheet->setCellValue("E{$row}", 0);
                    $sheet->setCellValue("F{$row}", $this->safeNumberFormat($childRate, 2));
                    $sheet->setCellValue("G{$row}", $this->safeNumberFormat(abs($displayAmount), 2));
                    
                    $sheet->getStyle("D{$row}:G{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                    $row++;
                }
                
                $mealTotal = $mealItems->sum(function($item) use ($showLocalCurrency, $exchangeRate) {
                    $amount = abs(floatval($item->amount_original));
                    return $showLocalCurrency ? $amount * $exchangeRate : $amount;
                });
                $sheet->setCellValue("F{$row}", 'Grand Total');
                $sheet->setCellValue("G{$row}", $this->safeNumberFormat($mealTotal, 2));
                $sheet->getStyle("F{$row}:G{$row}")->applyFromArray(['font' => ['bold' => true]]);
                $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
                $row += 2;
            }
            
            // ========== SECTION 7: SUMMARY ==========
            $sheet->setCellValue("A{$row}", 'SUMMARY');
            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6C757D']],
            ]);
            $row++;
            
            $invoiceTotal = floatval($record->amount ?? 0);
            $totalCost = $record->items->where('type', '!=', 'INVOICE')->sum(function($item) {
                return abs(floatval($item->amount_original));
            });
            $profitLoss = $invoiceTotal - $totalCost;
            
            $displayInvoiceTotal = $showLocalCurrency ? $invoiceTotal * $exchangeRate : $invoiceTotal;
            $displayTotalCost = $showLocalCurrency ? $totalCost * $exchangeRate : $totalCost;
            $displayProfitLoss = $showLocalCurrency ? $profitLoss * $exchangeRate : $profitLoss;
            
            $displayCurrency = $showLocalCurrency ? $currencySymbol : 'USD';
            
            $sheet->setCellValue("A{$row}", 'Total Revenue (' . $displayCurrency . ')');
            $sheet->setCellValue("B{$row}", $this->safeNumberFormat($displayInvoiceTotal, 2));
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
            $row++;
            
            $sheet->setCellValue("A{$row}", 'Total Cost (' . $displayCurrency . ')');
            $sheet->setCellValue("B{$row}", $this->safeNumberFormat($displayTotalCost, 2));
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
            $row++;
            
            $sheet->setCellValue("A{$row}", 'Profit / Loss (' . $displayCurrency . ')');
            $sheet->setCellValue("B{$row}", $this->safeNumberFormat($displayProfitLoss, 2));
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD);
            if ($profitLoss >= 0) {
                $sheet->getStyle("A{$row}:B{$row}")->getFont()->getColor()->setRGB('28A745');
            } else {
                $sheet->getStyle("A{$row}:B{$row}")->getFont()->getColor()->setRGB('DC3545');
            }
            $row++;
            
            $sheet->setCellValue("A{$row}", 'Exchange Rate (1 USD)');
            $sheet->setCellValue("B{$row}", $exchangeRate);
            $row++;
            
            // ========== AUTO SIZE ==========
            foreach (range('A', 'H') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            
            // ========== SAVE ==========
            $filename = "detailed_pnl_" . ($record->tour_ref ?? $record->id) . "_" . date('Y-m-d_His') . ".xlsx";
            $tempFile = tempnam(sys_get_temp_dir(), 'pnl_detailed_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);
            
            return [
                'success' => true,
                'file_path' => $tempFile,
                'filename' => $filename,
                'total_revenue' => $displayInvoiceTotal,
                'total_cost' => $displayTotalCost,
                'profit_loss' => $displayProfitLoss,
            ];
            
        } catch (\Exception $e) {
            Log::error('Detailed PnL generation failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get HTML preview of Detailed P&L
     */
    public function getDetailedPreview($record)
    {
        try {
            $countryCode = $record->country_code ?? 'VN';
            $currencySymbol = $this->currencySymbols[$countryCode] ?? 'USD';
            $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
            
            // ✅ Only show local currency for SG and MY
            $showLocalCurrency = in_array($countryCode, ['SG', 'MY']);
            $displayCurrency = $showLocalCurrency ? $currencySymbol : 'USD';
            
            $html = '<div class="table-responsive">';
            
            // ========== HEADER ==========
            $html .= '<div class="text-center mb-4">';
            $html .= '<h2>Profit & Loss Statement (' . $displayCurrency . ')</h2>';
            $html .= '<hr>';
            $html .= '</div>';
            
            // ========== TOUR DETAILS ==========
            $html .= '<table class="table table-bordered table-sm mb-4">';
            $html .= '<tr><th style="width:150px;">Tour Reference</th><td>' . ($record->tour_ref ?? '-') . '</td>';
            $html .= '<th style="width:150px;">Invoice Number</th><td>' . ($record->invoice_number ?? '-') . '</td></tr>';
            $html .= '<tr><th>Agent</th><td>' . ($record->agent_name ?? '-') . '</td>';
            $html .= '<th>Client</th><td>' . ($record->vendor_name ?? '-') . '</td></tr>';
            $html .= '<tr><th>Pax</th><td>' . ($record->total_pax ?? 0) . '</td>';
            $html .= '<th>Nights</th><td>' . ($record->total_nights ?? 0) . '</td></tr>';
            $html .= '</table>';
            
            // ========== HOTELS ==========
            $hotelItems = $record->items->where('type', 'HOTEL');
            if ($hotelItems->isNotEmpty()) {
                $html .= '<h4 class="mt-4" style="background:#4472C4;color:#fff;padding:8px;">HOTELS</h4>';
                $html .= '<table class="table table-bordered table-striped table-sm">';
                $html .= '<thead><tr><th>Hotel Name</th><th>Room Type</th><th>Meal Plan</th><th>Rooms</th><th>Nights</th><th>Cost/Night</th><th>Total</th></tr></thead><tbody>';
                foreach ($hotelItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $nights = $itemDetails['nights'] ?? 1;
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    $costPerNight = $nights > 0 ? abs($displayAmount) / $nights : abs($displayAmount);
                    $html .= '<tr>';
                    $html .= '<td>' . ($item->hotel_name ?? $item->service_name) . '</td>';
                    $html .= '<td>Standard</td>';
                    $html .= '<td>BB</td>';
                    $html .= '<td>1</td>';
                    $html .= '<td>' . $nights . '</td>';
                    $html .= '<td>' . $this->formatCurrency($costPerNight, $displayCurrency) . '</td>';
                    $html .= '<td>' . $this->formatCurrency(abs($displayAmount), $displayCurrency) . '</td>';
                    $html .= '</tr>';
                }
                $hotelTotal = $hotelItems->sum(fn($item) => abs(floatval($item->amount_original)) * ($showLocalCurrency ? $exchangeRate : 1));
                $html .= '<tr class="fw-bold"><td colspan="6">Grand Total</td><td>' . $this->formatCurrency($hotelTotal, $displayCurrency) . '</td></tr>';
                $html .= '</tbody></table>';
            }
            
            // ========== PRODUCTS & ATTRACTIONS ==========
            $productItems = $record->items->whereIn('type', ['ATTRACTION', 'TOUR TRANSFER']);
            if ($productItems->isNotEmpty()) {
                $html .= '<h4 class="mt-4" style="background:#28A745;color:#fff;padding:8px;">PRODUCTS & ATTRACTIONS</h4>';
                $html .= '<table class="table table-bordered table-striped table-sm">';
                $html .= '<thead><tr><th>Name</th><th>Adult Count</th><th>Adult Rate</th><th>Child Count</th><th>Child Rate</th><th>Package</th><th>Package Cost</th><th>Total</th></tr></thead><tbody>';
                
                foreach ($productItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $adultCount = $itemDetails['adult_count'] ?? $record->adult_count ?? 0;
                    $childCount = $itemDetails['child_count'] ?? $record->child_count ?? 0;
                    $adultRate = floatval($itemDetails['adult_rate'] ?? 0);
                    $childRate = floatval($itemDetails['child_rate'] ?? 0);
                    $transferAmount = floatval($itemDetails['transfer_amount'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    $pax = $itemDetails['pax'] ?? $record->total_pax ?? 0;
                    
                    $html .= '<tr>';
                    $html .= '<td>' . ($item->service_name ?? '-') . '</td>';
                    
                    if ($item->type === 'TOUR TRANSFER') {
                        if ($adultRate > 0 && $pax > 0) {
                            $html .= '<td>' . $adultCount . '</td>';
                            $html .= '<td>' . $this->formatCurrency($adultRate, $displayCurrency) . '</td>';
                            $html .= '<td>' . $childCount . '</td>';
                            $html .= '<td>' . $this->formatCurrency($childRate, $displayCurrency) . '</td>';
                            $html .= '<td>0</td>';
                            $html .= '<td>0</td>';
                            $html .= '<td>' . $this->formatCurrency(abs($displayAmount), $displayCurrency) . '</td>';
                        } else if ($transferAmount > 0) {
                            $html .= '<td>0</td><td>0</td><td>0</td><td>0</td><td>1</td>';
                            $html .= '<td>' . $this->formatCurrency($showLocalCurrency ? $transferAmount * $exchangeRate : $transferAmount, $displayCurrency) . '</td>';
                            $html .= '<td>' . $this->formatCurrency($showLocalCurrency ? $transferAmount * $exchangeRate : $transferAmount, $displayCurrency) . '</td>';
                        }
                    } 
                    else if ($item->type === 'ATTRACTION') {
                        $html .= '<td>' . $adultCount . '</td>';
                        $html .= '<td>' . $this->formatCurrency($adultRate, $displayCurrency) . '</td>';
                        $html .= '<td>' . $childCount . '</td>';
                        $html .= '<td>' . $this->formatCurrency($childRate, $displayCurrency) . '</td>';
                        $html .= '<td>0</td>';
                        $html .= '<td>0</td>';
                        $html .= '<td>' . $this->formatCurrency(abs($displayAmount), $displayCurrency) . '</td>';
                    }
                    
                    $html .= '</tr>';
                }
                
                $productTotal = $productItems->sum(fn($item) => abs(floatval($item->amount_original)) * ($showLocalCurrency ? $exchangeRate : 1));
                $html .= '<tr class="fw-bold"><td colspan="7">Grand Total</td><td>' . $this->formatCurrency($productTotal, $displayCurrency) . '</td></tr>';
                $html .= '</tbody></table>';
            }
            
            // ========== OTHER RATES ==========
            $otherRateItems = $record->items->where('type', 'OTHER RATES');
            if ($otherRateItems->isNotEmpty()) {
                $html .= '<h4 class="mt-4" style="background:#6F42C1;color:#fff;padding:8px;">OTHER RATES</h4>';
                $html .= '<table class="table table-bordered table-striped table-sm">';
                $html .= '<thead><tr><th>Service Name</th><th>Pax Type</th><th>Pax Count</th><th>Rate</th><th>Total</th></tr></thead><tbody>';
                foreach ($otherRateItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $paxType = $itemDetails['pax_type'] ?? 'adult';
                    $paxCount = $itemDetails['pax'] ?? 0;
                    $rate = floatval($itemDetails['rate'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    
                    $html .= '<tr>';
                    $html .= '<td>' . ($item->service_name ?? '-') . '</td>';
                    $html .= '<td>' . ucfirst($paxType) . '</td>';
                    $html .= '<td>' . $paxCount . '</td>';
                    $html .= '<td>' . $this->formatCurrency($rate, $displayCurrency) . '</td>';
                    $html .= '<td>' . $this->formatCurrency(abs($displayAmount), $displayCurrency) . '</td>';
                    $html .= '</tr>';
                }
                $otherTotal = $otherRateItems->sum(fn($item) => abs(floatval($item->amount_original)) * ($showLocalCurrency ? $exchangeRate : 1));
                $html .= '<tr class="fw-bold"><td colspan="4">Grand Total</td><td>' . $this->formatCurrency($otherTotal, $displayCurrency) . '</td></tr>';
                $html .= '</tbody></table>';
            }
            
            // ========== TRANSPORT ==========
            $transportItems = $record->items->where('type', 'TRANSPORT');
            if ($transportItems->isNotEmpty()) {
                $html .= '<h4 class="mt-4" style="background:#DC3545;color:#fff;padding:8px;">TRANSPORT</h4>';
                $html .= '<table class="table table-bordered table-striped table-sm">';
                $html .= '<thead><tr><th>Description</th><th>Unit</th><th>Count</th><th>Rate</th><th>Total</th></tr></thead><tbody>';
                
                $transportOrder = [
                    'Travel' => 1,
                    'Bata' => 2,
                    'Paging' => 3,
                    'Highway Charges' => 4,
                    'Driver Accomodation' => 5,
                    'Driver Accommodation' => 5,
                    'Guide Fee' => 6,
                    'Water Bottles' => 7,
                    'Other Cost' => 8,
                ];
                
                $sortedTransportItems = $transportItems->sortBy(function($item) use ($transportOrder) {
                    $serviceName = $item->service_name;
                    foreach ($transportOrder as $key => $order) {
                        if (stripos($serviceName, $key) !== false) {
                            return $order;
                        }
                    }
                    return 999;
                });
                
                foreach ($sortedTransportItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $unit = $itemDetails['unit_type'] ?? 'Days';
                    $count = $itemDetails['distance_days'] ?? 1;
                    $rate = floatval($itemDetails['rate'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    $html .= '<tr>';
                    $html .= '<td>' . ($item->service_name ?? '-') . '</td>';
                    $html .= '<td>' . $unit . '</td>';
                    $html .= '<td>' . $count . '</td>';
                    $html .= '<td>' . number_format($rate, 4) . '</td>';
                    $html .= '<td>' . $this->formatCurrency(abs($displayAmount), $displayCurrency) . '</td>';
                    $html .= '</tr>';
                }
                $transportTotal = $transportItems->sum(fn($item) => abs(floatval($item->amount_original)) * ($showLocalCurrency ? $exchangeRate : 1));
                $html .= '<tr class="fw-bold"><td colspan="4">Grand Total</td><td>' . $this->formatCurrency($transportTotal, $displayCurrency) . '</td></tr>';
                $html .= '</tbody></table>';
            }
            
            // ========== MEALS ==========
            $mealItems = $record->items->where('type', 'MEALS');
            if ($mealItems->isNotEmpty()) {
                $html .= '<h4 class="mt-4" style="background:#FFC107;color:#000;padding:8px;">MEALS</h4>';
                $html .= '<table class="table table-bordered table-striped table-sm">';
                $html .= '<thead><tr><th>Meals</th><th>Type</th><th>Adult Count</th><th>Adult Rate</th><th>Child Count</th><th>Child Rate</th><th>Total</th></tr></thead><tbody>';
                $pax = $record->total_pax ?? 0;
                foreach ($mealItems as $item) {
                    $itemDetails = json_decode($item->item_details, true);
                    $adultRate = floatval($itemDetails['adult_rate'] ?? 0);
                    $childRate = floatval($itemDetails['child_rate'] ?? 0);
                    $amount = floatval($item->amount_original);
                    $displayAmount = $showLocalCurrency ? $amount * $exchangeRate : $amount;
                    $html .= '<tr>';
                    $html .= '<td>' . ($item->service_name ?? '-') . '</td>';
                    $html .= '<td>Dinner</td>';
                    $html .= '<td>' . $pax . '</td>';
                    $html .= '<td>' . $this->formatCurrency($adultRate, $displayCurrency) . '</td>';
                    $html .= '<td>0</td>';
                    $html .= '<td>' . $this->formatCurrency($childRate, $displayCurrency) . '</td>';
                    $html .= '<td>' . $this->formatCurrency(abs($displayAmount), $displayCurrency) . '</td>';
                    $html .= '</tr>';
                }
                $mealTotal = $mealItems->sum(fn($item) => abs(floatval($item->amount_original)) * ($showLocalCurrency ? $exchangeRate : 1));
                $html .= '<tr class="fw-bold"><td colspan="6">Grand Total</td><td>' . $this->formatCurrency($mealTotal, $displayCurrency) . '</td></tr>';
                $html .= '</tbody></table>';
            }
            
            // ========== SUMMARY ==========
            $invoiceTotal = floatval($record->amount ?? 0);
            $totalCost = $record->items->where('type', '!=', 'INVOICE')->sum(fn($item) => abs(floatval($item->amount_original)));
            $profitLoss = $invoiceTotal - $totalCost;
            
            $displayInvoiceTotal = $showLocalCurrency ? $invoiceTotal * $exchangeRate : $invoiceTotal;
            $displayTotalCost = $showLocalCurrency ? $totalCost * $exchangeRate : $totalCost;
            $displayProfitLoss = $showLocalCurrency ? $profitLoss * $exchangeRate : $profitLoss;
            
            $html .= '<h4 class="mt-4" style="background:#6C757D;color:#fff;padding:8px;">SUMMARY</h4>';
            $html .= '<table class="table table-bordered table-sm">';
            $html .= '<tr><th style="width:200px;">Total Revenue</th><td>' . $this->formatCurrency($displayInvoiceTotal, $displayCurrency) . '</td></tr>';
            $html .= '<tr><th>Total Cost</th><td>' . $this->formatCurrency($displayTotalCost, $displayCurrency) . '</td></tr>';
            $html .= '<tr class="' . ($profitLoss >= 0 ? 'text-success' : 'text-danger') . ' fw-bold">';
            $html .= '<th>Profit / Loss</th><td>' . $this->formatCurrency($displayProfitLoss, $displayCurrency) . '</td></tr>';
            $html .= '<tr><th>Exchange Rate</th><td>1 USD = ' . $exchangeRate . ' ' . $displayCurrency . '</td></tr>';
            $html .= '</table>';
            
            $html .= '</div>';
            return $html;
            
        } catch (\Exception $e) {
            Log::error('Detailed preview error: ' . $e->getMessage());
            return '<div class="alert alert-danger">Error loading preview: ' . $e->getMessage() . '</div>';
        }
    }

    /**
     * Format currency with symbol
     */
    private function formatCurrency($amount, $currency)
    {
        $symbol = $currency === 'USD' ? '$' : $currency . ' ';
        return $symbol . number_format($amount, 2);
    }
}