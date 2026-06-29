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

class PnLExcelService
{

    private $currencySymbols = [
        'LK' => 'LKR',
        'VN' => 'VND', 
        'SG' => 'SGD',
        'MY' => 'MYR',
    ];

    private $currencyFormats = [
        'LK' => 'LKR %s',
        'VN' => 'VND %s',
        'SG' => 'SGD %s',
        'MY' => 'MYR %s',
    ];
    
    private $exchangeRates = [
        'LK' => 330,
        'VN' => 25500,
        'SG' => 1,
        'MY' => 1,
    ];

    private $excelColumns = [
        'A' => 'S.No',
        'B' => 'Tour Number',
        'C' => 'Invoice Number',
        'D' => 'Agent Type',
        'E' => 'Agent Name',
        'F' => 'Client Name',
        'G' => 'Type',
        'H' => 'Start Date',
        'I' => 'End Date',
        'J' => 'Category Type',
        'K' => 'Description',
        'L' => 'Amount (USD)',
        'M' => 'Exchange Rate',
        'N' => 'Amount ({currency})',
        'O' => 'Remarks'
    ];

    public function processAndUpdateExcel(PnlRecord $record)
    {
        try {
            Log::info("Processing PnL Email ID: " . $record->id);
            $profitLossFromEmail = $record->profit_loss;
            Log::info("Profit/Loss from database: " . ($profitLossFromEmail ?? 'null'));
            
            $items = PnlItem::where('pnl_record_id', $record->id)->get();
            
            if ($items->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No items found in database. Please fetch emails first.'
                ];
            }
            
            $countryCode = $record->country_code ?? 'VN';
            $exchangeRate = $this->exchangeRates[$countryCode] ?? 25500;
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            $startDate = $record->start_date ?? date('Y-m-d');
            $endDate = $record->end_date ?? date('Y-m-d');
            
            // Build items for Excel from database
            $allItems = [];
            $sno = 1;
            
            foreach ($items as $item) {
                $remarks = '';
                $itemDetails = json_decode($item->item_details, true);
                
                // ✅ Use service_name for description
                $description = $item->service_name ?? $item->type;
                
                // For hotels, use hotel_name if available
                if ($item->type == 'HOTEL') {
                    $description = $item->hotel_name ?? $item->service_name ?? $item->type;
                }
                
                $amount = $item->amount_original;
                if ($item->type != 'INVOICE') {
                    $amount = -abs($amount);
                }
                
                if ($item->type == 'INVOICE') {
                    $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                    $description = 'INVOICE';
                } elseif ($item->type == 'HOTEL') {
                    $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                } elseif ($item->type == 'ATTRACTION') {
                    $remarks = $itemDetails['remarks'] ?? $item->service_name ?? 'Attraction fees';
                } elseif ($item->type == 'TOUR TRANSFER') {
                    $remarks = $itemDetails['remarks'] ?? 'Tour transfer';
                } elseif ($item->type == 'TRANSPORT') {
                    $remarks = $itemDetails['remarks'] ?? 'Transport expenses';
                }
                
                $allItems[] = [
                    'sno' => $sno++,
                    'type' => $item->type,
                    'client_name' => $item->client_name ?? '',
                    'description' => $description,
                    'start_date' => $item->start_date ?? $startDate,
                    'end_date' => $item->end_date ?? $endDate,
                    'credit_type' => $item->credit_type,
                    'agent_name' => $agentName,
                    'amount_usd' => $amount,
                    'exchange_rate' => $exchangeRate,
                    'amount_local' => round($amount * $exchangeRate, 2),
                    'remarks' => $remarks,
                    'country_code' => $countryCode  
                ];
            }

            Log::info("Total items to insert: " . count($allItems));
            
            if (empty($allItems)) {
                return [
                    'success' => false,
                    'message' => 'No items to process'
                ];
            }
            
            $excelPath = $this->getExcelFilePath($countryCode);
            $spreadsheet = $this->loadOrCreateSpreadsheet($excelPath, $countryCode);
            
            $existingRows = $this->checkExistingEntries($spreadsheet, $tourRef, $invoiceNumber);
            
            if ($existingRows['found']) {
                Log::info("Updating existing entries for Tour: {$tourRef}, Invoice: {$invoiceNumber}");
                $this->updateExistingEntries($spreadsheet, $allItems, $tourRef, $invoiceNumber, $existingRows['rows'], $profitLossFromEmail, $exchangeRate, $agentName);
                $action = 'updated';
            } else {
                Log::info("Inserting new entries for Tour: {$tourRef}, Invoice: {$invoiceNumber}");
                $this->addItemsToSpreadsheet($spreadsheet, $allItems, $tourRef, $invoiceNumber, $profitLossFromEmail, $exchangeRate, $agentName);
                $action = 'inserted';
            }
            
            $this->saveSpreadsheet($spreadsheet, $excelPath);
            
            $record->update([
                'status' => 'approved',
                'processing_status' => 'completed'
            ]);
            
            return [
                'success' => true,
                'action' => $action,
                'message' => $action == 'updated' ? "✅ PnL Updated successfully!" : "✅ New PnL Inserted!",
                'items_count' => count($allItems),
                'items' => $allItems,
                'excel_path' => $excelPath
            ];
            
        } catch (\Exception $e) {
            Log::error('PnL Excel processing failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function formatAmount($amount, $countryCode = null)
    {
        if ($amount >= 0) {
            return number_format($amount, 2);
        }
        return '(' . number_format(abs($amount), 2) . ')';
    }

    private function formatAmountWithCurrency($amount, $countryCode)
    {
        $symbol = $this->getCurrencySymbol($countryCode);
        if ($amount >= 0) {
            return $symbol . ' ' . number_format($amount, 2);
        }
        return $symbol . ' (' . number_format(abs($amount), 2) . ')';
    }

    private function parseAmount($value)
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        
        if (is_string($value)) {
            if (preg_match('/\(([\d\.]+)\)/', $value, $match)) {
                return - (float)$match[1];
            }
            if (preg_match('/[\d\.]+/', $value, $match)) {
                return (float)$match[0];
            }
        }
        
        return 0;
    }

    private function checkExistingEntries($spreadsheet, $tourRef, $invoiceNumber)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $existingRows = [];
        
        if ($highestRow < 2) {
            return ['found' => false, 'rows' => []];
        }
        
        for ($row = 2; $row <= $highestRow; $row++) {
            $existingTourRef = $sheet->getCell("B{$row}")->getValue();
            $existingInvoice = $sheet->getCell("C{$row}")->getValue();
            $existingType = $sheet->getCell("G{$row}")->getValue();
            
            if ($existingType == 'PROFIT / (LOSS)') {
                continue;
            }
            
            if ($existingTourRef == $tourRef && $existingInvoice == $invoiceNumber) {
                $existingRows[] = $row;
            }
        }
        
        return [
            'found' => !empty($existingRows),
            'rows' => $existingRows
        ];
    }

    private function updateExistingEntries($spreadsheet, $newItems, $tourRef, $invoiceNumber, $existingRows, $profitLossFromEmail, $exchangeRate, $agentName)
    {
        $sheet = $spreadsheet->getActiveSheet();
        
        rsort($existingRows);
        
        foreach ($existingRows as $row) {
            $nextRow = $row + 1;
            $nextRowType = $sheet->getCell("G{$nextRow}")->getValue();
            if ($nextRowType == 'PROFIT / (LOSS)') {
                $sheet->removeRow($nextRow);
            }
            $sheet->removeRow($row);
            Log::info("Removed existing row {$row}");
        }
        
        $currentRow = max($sheet->getHighestRow() + 1, 2);
        $firstItemRow = $currentRow;
        
        foreach ($newItems as $item) {
            $this->writeRow($sheet, $currentRow, $item, $tourRef, $invoiceNumber);
            $currentRow++;
        }
        
        $lastItemRow = $currentRow - 1;
        $this->addProfitLossRow($spreadsheet, $firstItemRow, $lastItemRow, $tourRef, $invoiceNumber, $agentName, $exchangeRate, $profitLossFromEmail);
        
        $this->autoSizeColumns($sheet);
    }

    private function addItemsToSpreadsheet($spreadsheet, $items, $tourRef, $invoiceNumber, $profitLossFromEmail, $exchangeRate, $agentName)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $currentRow = max($sheet->getHighestRow() + 1, 2);
        $firstItemRow = $currentRow;
        
        foreach ($items as $item) {
            $this->writeRow($sheet, $currentRow, $item, $tourRef, $invoiceNumber);
            $currentRow++;
        }
        
        $lastItemRow = $currentRow - 1;
        $this->addProfitLossRow($spreadsheet, $firstItemRow, $lastItemRow, $tourRef, $invoiceNumber, $agentName, $exchangeRate, $profitLossFromEmail);
        
        $this->autoSizeColumns($sheet);
    }

    private function writeRow($sheet, $row, $item, $tourRef, $invoiceNumber)
    {
        $countryCode = $item['country_code'] ?? 'VN';
        $currencySymbol = $this->getCurrencySymbol($countryCode);
        
        $amount = $item['amount_usd'];
        $localAmount = $item['amount_local'];
        
        $formattedAmount = $this->formatAmount($amount);
        $formattedLocalAmount = $this->formatAmount($localAmount);
        
        // ✅ CORRECT COLUMN ORDER (A to O)
        $sheet->setCellValue("A{$row}", $item['sno']);                              // S.No
        $sheet->setCellValue("B{$row}", $tourRef ?? '-');                           // Tour Number
        $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');                     // Invoice Number
        $sheet->setCellValue("D{$row}", $item['credit_type'] ?? 'Credit');          // Agent Type
        $sheet->setCellValue("E{$row}", $item['agent_name']);                       // Agent Name
        $sheet->setCellValue("F{$row}", $item['client_name'] ?? '');                // Client Name
        $sheet->setCellValue("G{$row}", $item['type']);                             // Type
        $sheet->setCellValue("H{$row}", $item['start_date']);                       // Start Date
        $sheet->setCellValue("I{$row}", $item['end_date']);                         // End Date
        $sheet->setCellValue("J{$row}", $item['type']);                             // Category Type (same as Type)
        $sheet->setCellValue("K{$row}", $item['description']);                      // Description
        $sheet->setCellValue("L{$row}", $formattedAmount);                          // Amount (USD)
        $sheet->setCellValue("M{$row}", $item['exchange_rate']);                    // Exchange Rate
        $sheet->setCellValue("N{$row}", $this->formatAmountWithCurrency($localAmount, $countryCode)); // Amount (Local)
        $sheet->setCellValue("O{$row}", $item['remarks']);                          // Remarks
        
        // Color coding
        $colors = [
            'INVOICE' => 'D5E8D4',
            'HOTEL' => 'FFF2CC',
            'TRANSPORT' => 'DDEBF7',
            'TOUR TRANSFER' => 'E2EFDA',
            'ATTRACTION' => 'FCE4D6',
            'MEALS' => 'E1C699',
            'OTHER RATES' => 'D9D9D9'
        ];
        
        if (isset($colors[$item['type']])) {
            $sheet->getStyle("A{$row}:O{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($colors[$item['type']]);
        }
        
        $sheet->getStyle("A{$row}:O{$row}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        // Color for negative amounts
        if ($amount < 0) {
            $sheet->getStyle("L{$row}")->getFont()->getColor()->setRGB('DC3545');
            $sheet->getStyle("N{$row}")->getFont()->getColor()->setRGB('DC3545');
        } else {
            $sheet->getStyle("L{$row}")->getFont()->getColor()->setRGB('28A745');
            $sheet->getStyle("N{$row}")->getFont()->getColor()->setRGB('28A745');
        }
    }

    private function autoSizeColumns($sheet)
    {
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function addProfitLossRow($spreadsheet, $startRow, $endRow, $tourRef, $invoiceNumber, $agentName, $exchangeRate, $profitLossFromEmail = null)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $countryCode = $this->getCountryCodeFromTourRef($tourRef) ?? 'VN';
        $currencySymbol = $this->getCurrencySymbol($countryCode);
        
        if ($profitLossFromEmail !== null && $profitLossFromEmail != 0) {
            $profitLoss = $profitLossFromEmail;
        } else {
            $invoiceTotal = 0;
            $expenseTotal = 0;
            
            for ($row = $startRow; $row <= $endRow; $row++) {
                $type = $sheet->getCell("G{$row}")->getValue();  // Type column
                $amountCell = $sheet->getCell("L{$row}")->getValue();  // Amount column
                $amount = $this->parseAmount($amountCell);
                
                if ($type == 'INVOICE') {
                    $invoiceTotal += $amount;
                } else {
                    $expenseTotal += $amount;
                }
            }
            $profitLoss = $invoiceTotal - $expenseTotal;
        }
        
        $pnlRow = $endRow + 1;
        $sheet->insertNewRowBefore($pnlRow);
        
        $sheet->setCellValue("A{$pnlRow}", '');
        $sheet->setCellValue("B{$pnlRow}", $tourRef ?? '-');
        $sheet->setCellValue("C{$pnlRow}", $invoiceNumber ?? '-');
        $sheet->setCellValue("D{$pnlRow}", '');  // Agent Type - blank
        $sheet->setCellValue("E{$pnlRow}", $agentName ?? '-');  // Agent Name
        $sheet->setCellValue("F{$pnlRow}", '');  // Client Name - blank
        $sheet->setCellValue("G{$pnlRow}", 'PROFIT / (LOSS)');  // Type
        $sheet->setCellValue("H{$pnlRow}", '');  // Start Date - blank
        $sheet->setCellValue("I{$pnlRow}", '');  // End Date - blank
        $sheet->setCellValue("J{$pnlRow}", 'PROFIT / (LOSS)');  // Category Type
        $sheet->setCellValue("K{$pnlRow}", '');  // Description - blank
        
        if ($profitLoss >= 0) {
            $sheet->setCellValue("L{$pnlRow}", number_format($profitLoss, 2));
            $sheet->setCellValue("O{$pnlRow}", "Profit: " . number_format($profitLoss, 2) . " USD");
        } else {
            $sheet->setCellValue("L{$pnlRow}", '(' . number_format(abs($profitLoss), 2) . ')');
            $sheet->setCellValue("O{$pnlRow}", "Loss: " . number_format(abs($profitLoss), 2) . " USD");
        }
        
        $sheet->setCellValue("M{$pnlRow}", $exchangeRate);
        
        $localAmount = abs($profitLoss) * $exchangeRate;
        if ($profitLoss >= 0) {
            $sheet->setCellValue("N{$pnlRow}", $currencySymbol . ' ' . round($localAmount, 2));
        } else {
            $sheet->setCellValue("N{$pnlRow}", $currencySymbol . ' (' . number_format($localAmount, 2) . ')');
        }
        
        $sheet->getStyle("A{$pnlRow}:O{$pnlRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFF3CD']
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC']
                ]
            ]
        ]);
        
        if ($profitLoss < 0) {
            $sheet->getStyle("L{$pnlRow}")->getFont()->getColor()->setRGB('DC3545');
            $sheet->getStyle("N{$pnlRow}")->getFont()->getColor()->setRGB('DC3545');
        } else {
            $sheet->getStyle("L{$pnlRow}")->getFont()->getColor()->setRGB('28A745');
            $sheet->getStyle("N{$pnlRow}")->getFont()->getColor()->setRGB('28A745');
        }
        
        return $pnlRow;
    }

    private function getCountryCodeFromTourRef($tourRef)
    {
        if (empty($tourRef)) return null;
        
        $countryMap = [
            'LK' => ['LK', 'SL'],
            'VN' => ['VN', 'VT'],
            'SG' => ['SG'],
            'MY' => ['MY'],
        ];
        
        foreach ($countryMap as $code => $patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($tourRef, $pattern) !== false) {
                    return $code;
                }
            }
        }
        
        return null;
    }

    private function getExcelFilePath($countryCode)
    {
        $files = [
            'LK' => storage_path('app/pnl/srilanka_pnl.xlsx'),
            'VN' => storage_path('app/pnl/vietnam_pnl.xlsx'),
            'SG' => storage_path('app/pnl/singapore_pnl.xlsx'),
            'MY' => storage_path('app/pnl/malaysia_pnl.xlsx'),
        ];
        
        $path = $files[$countryCode] ?? storage_path('app/pnl/vietnam_pnl.xlsx');
        $dir = dirname($path);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        
        return $path;
    }

    private function loadOrCreateSpreadsheet($path, $countryCode)
    {
        if (file_exists($path)) {
            return IOFactory::load($path);
        }
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("PnL - " . $countryCode);
        
        $headers = [
            'A1' => 'S.No',
            'B1' => 'Tour Number',
            'C1' => 'Invoice Number',
            'D1' => 'Agent Type',
            'E1' => 'Agent Name',
            'F1' => 'Client Name',
            'G1' => 'Type',
            'H1' => 'Start Date',
            'I1' => 'End Date',
            'J1' => 'Category Type',
            'K1' => 'Description',
            'L1' => 'Amount (USD)',
            'M1' => 'Exchange Rate',
            'N1' => 'Amount ({currency})',
            'O1' => 'Remarks'
        ];
        
        foreach ($headers as $cell => $header) {
            $sheet->setCellValue($cell, $header);
        }
        
        $sheet->getStyle('A1:O1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        
        $this->autoSizeColumns($sheet);
        
        return $spreadsheet;
    }

    private function saveSpreadsheet($spreadsheet, $path)
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        Log::info("Excel file saved: {$path}");
    }

    public function getExcelPreview($countryCode = null)
    {
        $countryCode = $countryCode ?? 'VN';
        $excelPath = $this->getExcelFilePath($countryCode);
        
        if (!file_exists($excelPath)) {
            return '<div class="alert alert-warning">No Excel file found for ' . $countryCode . '.</div>';
        }
        
        try {
            $spreadsheet = IOFactory::load($excelPath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            
            $html = '<div class="table-responsive"><table class="table table-bordered table-striped table-sm">';
            $html .= '<thead class="table-dark"><tr>';
            
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $value = $sheet->getCell($col . '1')->getValue();
                $html .= '<th>' . htmlspecialchars($value) . '</th>';
            }
            $html .= '</thead><tbody>';
            
            for ($row = 2; $row <= min($highestRow, 100); $row++) {
                $html .= '<tr>';
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $value = $sheet->getCell($col . $row)->getCalculatedValue();
                    $html .= '<td>' . htmlspecialchars($value) . '</td>';
                }
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table></div>';
            return $html;
            
        } catch (\Exception $e) {
            Log::error('Excel preview error: ' . $e->getMessage());
            return '<div class="alert alert-danger">Error loading Excel file: ' . $e->getMessage() . '</div>';
        }
    }

    public function getRecordPreview($record)
    {
        try {
            $items = $record->items;
            $exchangeRate = $this->exchangeRates[$record->country_code ?? 'VN'] ?? 25500;
            $currencySymbol = $this->getCurrencySymbol($record->country_code ?? 'VN');
            if ($items->isEmpty()) {
                return '<div class="alert alert-warning">No items found for this record.</div>';
            }
            
            $html = '<div class="table-responsive"><table class="table table-bordered table-striped table-sm">';
            $html .= '<thead class="table-dark"><tr>';
            
            $headers = [
                'S.No',
                'Tour Number',
                'Invoice Number',
                'Agent Type',
                'Agent Name',
                'Client Name',
                'Type',
                'Start Date',
                'End Date',
                'Category Type',
                'Description',
                'Amount (USD)',
                'Exchange Rate',
                'Amount (' . $currencySymbol . ')',
                'Remarks'
            ];
            
            foreach ($headers as $header) {
                $html .= '<th>' . htmlspecialchars($header) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            
            $sno = 1;
            foreach ($items as $item) {
                $html .= '<tr>';
                $html .= '<td>' . $sno++ . '</td>';
                $html .= '<td>' . ($record->tour_ref ?? '-') . '</td>';
                $html .= '<td>' . ($record->invoice_number ?? '-') . '</td>';
                $html .= '<td>' . ($item->credit_type ?? 'Credit') . '</td>';
                $html .= '<td>' . ($record->agent_name ?? '-') . '</td>';
                $html .= '<td>' . ($item->client_name ?? $record->vendor_name ?? '') . '</td>';
                $html .= '<td>' . $item->type . '</td>';
                $html .= '<td>' . ($item->start_date ?? $record->start_date ?? '') . '</td>';
                $html .= '<td>' . ($item->end_date ?? $record->end_date ?? '') . '</td>';
                $html .= '<td>' . $item->type . '</td>';  // Category Type
                
                // Description - use service_name
                $description = $item->service_name ?? $item->type;
                if ($item->type == 'HOTEL' && empty($description)) {
                    $description = $item->hotel_name ?? $item->type;
                }
                $html .= '<td>' . htmlspecialchars($description) . '</td>';
                
                $amount = $item->amount_original;
                if ($item->type != 'INVOICE') {
                    $amount = -abs($amount);
                }
                $html .= '<td>' . ($amount >= 0 ? number_format($amount, 2) : '(' . number_format(abs($amount), 2) . ')') . '</td>';
                $html .= '<td>' . $exchangeRate . '</td>';
                $html .= '<td>' . number_format($amount * $exchangeRate, 2) . '</td>';
                
                $itemDetails = json_decode($item->item_details, true);
                $remarks = '';
                if ($item->type == 'INVOICE') {
                    $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                } elseif ($item->type == 'HOTEL') {
                    $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                } else {
                    $remarks = $itemDetails['remarks'] ?? '';
                }
                $html .= '<td>' . htmlspecialchars($remarks) . '</td>';
                $html .= '</tr>';
            }
            
            // Profit/Loss row
            if ($record->profit_loss !== null) {
                $pl = $record->profit_loss;
                $html .= '<tr style="background-color: #FFF3CD; font-weight: bold;">';
                $html .= '<td></td>';
                $html .= '<td>' . ($record->tour_ref ?? '-') . '</td>';
                $html .= '<td>' . ($record->invoice_number ?? '-') . '</td>';
                $html .= '<td></td>';
                $html .= '<td>' . ($record->agent_name ?? '-') . '</td>';
                $html .= '<td></td>';
                $html .= '<td>PROFIT / (LOSS)</td>';
                $html .= '<td></td>';
                $html .= '<td></td>';
                $html .= '<td>PROFIT / (LOSS)</td>';
                $html .= '<td></td>';
                $html .= '<td>' . ($pl >= 0 ? number_format($pl, 2) : '(' . number_format(abs($pl), 2) . ')') . '</td>';
                $html .= '<td>' . $exchangeRate . '</td>';
                $html .= '<td>' . number_format($pl * $exchangeRate, 2) . '</td>';
                $html .= '<td>' . ($pl >= 0 ? 'Profit' : 'Loss') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table></div>';
            return $html;
            
        } catch (\Exception $e) {
            Log::error('Record preview error: ' . $e->getMessage());
            return '<div class="alert alert-danger">Error loading record: ' . $e->getMessage() . '</div>';
        }
    }

    private function getCurrencySymbol($countryCode)
    {
        return $this->currencySymbols[$countryCode] ?? 'USD';
    }

    private function formatLocalCurrency($amount, $countryCode)
    {
        $symbol = $this->getCurrencySymbol($countryCode);
        $formattedAmount = number_format($amount, 2);
        
        if ($amount < 0) {
            return $symbol . ' (' . number_format(abs($amount), 2) . ')';
        }
        return $symbol . ' ' . $formattedAmount;
    }
}