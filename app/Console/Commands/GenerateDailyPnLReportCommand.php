<?php

namespace App\Console\Commands;

use App\Models\PnlRecord;
use App\Models\PnlItem;
use App\Services\MicrosoftGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Mail;

class GenerateDailyPnLReportCommand extends Command
{
    protected $signature = 'pnl:report:daily {--date=} {--upload}';
    protected $description = 'Generate daily P&L report and upload to OneDrive';

    public function handle()
    {
        $date = $this->option('date') ?? date('Y-m-d');
        $upload = $this->option('upload') ?? true;
        
        $this->info("📊 Generating daily P&L report for: {$date}");
        
        try {
            $filePath = $this->generatePnLReport($date);
            
            if (!$filePath) {
                $this->error('❌ Failed to generate P&L report');
                return 1;
            }
            
            $this->info("✅ P&L Report generated: {$filePath}");
            
            if ($upload) {
                $uploadSuccess = $this->uploadToOneDrive($filePath, $date);
                
                if ($uploadSuccess) {
                    $this->sendNotificationEmail($date);
                }
            }
            
            Log::info("✅ Daily P&L report generated for {$date}: {$filePath}");
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            Log::error('Daily P&L report failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function generatePnLReport($date)
    {
        // Get all P&L records for the date
        $records = PnlRecord::with('items')
            ->whereDate('created_at', $date)
            ->orderBy('created_at', 'asc')
            ->get();
        
        if ($records->isEmpty()) {
            $this->info("📭 No P&L records found for {$date}");
            return $this->generateEmptyReport($date);
        }
        
        // Get only latest revisions
        $latestRecords = $this->getLatestPnLRecords($records);
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // ✅ Headers
        $headers = [
            'S.No',
            'Tour Number',
            'Invoice Number',
            'Agent Type',
            'Agent Name',
            'Client Name',
            'Category',
            'Description',
            'Amount (USD)',
            'Exchange Rate',
            'Amount (Local)',
            'Remarks'
        ];
        
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }
        
        $row = 2;
        $sno = 1;
        $totalAmount = 0;
        $totalProfitLoss = 0;
        
        foreach ($latestRecords as $record) {
            $exchangeRate = $record->exchange_rate_used ?? 1;
            $tourRef = $record->tour_ref;
            $invoiceNumber = $record->invoice_number;
            $agentName = $record->agent_name;
            $currencySymbol = $this->getCurrencySymbol($record->country_code ?? 'VN');
            
            $items = $record->items;
            
            if ($items->isEmpty()) {
                // Single row for records without items
                $sheet->setCellValue("A{$row}", $sno++);
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", $record->credit_type ?? 'Credit');
                $sheet->setCellValue("E{$row}", $agentName ?? '-');
                $sheet->setCellValue("F{$row}", $record->vendor_name ?? '');
                $sheet->setCellValue("G{$row}", 'INVOICE');
                $sheet->setCellValue("H{$row}", 'Total Tour Package');
                $sheet->setCellValue("I{$row}", number_format($record->amount, 2));
                $sheet->setCellValue("J{$row}", $exchangeRate);
                $sheet->setCellValue("K{$row}", number_format($record->amount * $exchangeRate, 2));
                $sheet->setCellValue("L{$row}", "Pax: {$record->total_pax}, Nights: {$record->total_nights}");
                
                $totalAmount += $record->amount;
                $row++;
            } else {
                // Multiple items
                foreach ($items as $item) {
                    $amount = $item->amount_original;
                    if ($item->type != 'INVOICE') {
                        $amount = -abs($amount);
                    }
                    
                    $description = $item->service_name ?? $item->type;
                    if ($item->type == 'HOTEL') {
                        $description = $item->hotel_name ?? $item->service_name ?? $item->type;
                    }
                    
                    $itemDetails = json_decode($item->item_details, true);
                    $remarks = '';
                    if ($item->type == 'INVOICE') {
                        $remarks = "Pax: {$record->total_pax}, Nights: {$record->total_nights}";
                    } elseif ($item->type == 'HOTEL') {
                        $remarks = ($itemDetails['nights'] ?? 1) . ' nights';
                    } else {
                        $remarks = $itemDetails['remarks'] ?? '';
                    }
                    
                    $sheet->setCellValue("A{$row}", $sno++);
                    $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                    $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                    $sheet->setCellValue("D{$row}", $item->credit_type ?? 'Credit');
                    $sheet->setCellValue("E{$row}", $agentName ?? '-');
                    $sheet->setCellValue("F{$row}", $item->client_name ?? $record->vendor_name ?? '');
                    $sheet->setCellValue("G{$row}", $item->type);
                    $sheet->setCellValue("H{$row}", $description);
                    $sheet->setCellValue("I{$row}", number_format($amount, 2));
                    $sheet->setCellValue("J{$row}", $exchangeRate);
                    $sheet->setCellValue("K{$row}", number_format($amount * $exchangeRate, 2));
                    $sheet->setCellValue("L{$row}", $remarks);
                    
                    $totalAmount += $amount;
                    $row++;
                }
            }
            
            // Profit/Loss row for this record
            if ($record->profit_loss !== null) {
                $pl = $record->profit_loss;
                $totalProfitLoss += $pl;
                
                $sheet->setCellValue("A{$row}", '');
                $sheet->setCellValue("B{$row}", $tourRef ?? '-');
                $sheet->setCellValue("C{$row}", $invoiceNumber ?? '-');
                $sheet->setCellValue("D{$row}", '');
                $sheet->setCellValue("E{$row}", $agentName ?? '-');
                $sheet->setCellValue("F{$row}", '');
                $sheet->setCellValue("G{$row}", 'PROFIT / (LOSS)');
                $sheet->setCellValue("H{$row}", '');
                $sheet->setCellValue("I{$row}", number_format($pl, 2));
                $sheet->setCellValue("J{$row}", $exchangeRate);
                $sheet->setCellValue("K{$row}", number_format($pl * $exchangeRate, 2));
                $sheet->setCellValue("L{$row}", $pl >= 0 ? 'Profit' : 'Loss');
                
                $sheet->getStyle("A{$row}:L{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:L{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFF3CD');
                $row++;
            }
            
            // Blank row between records
            $row++;
        }
        
        // Total row
        $row++;
        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->setCellValue("I{$row}", number_format($totalAmount, 2));
        $sheet->setCellValue("K{$row}", number_format($totalAmount * 1, 2));
        $sheet->getStyle("A{$row}:L{$row}")->getFont()->setBold(true);
        
        // Auto-size columns
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $filename = 'daily_pnl_report_' . date('Y-m-d', strtotime($date)) . '.xlsx';
        $directory = storage_path('app/public/reports/pnl');
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filePath = $directory . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        
        return $filePath;
    }

    protected function generateEmptyReport($date)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'No P&L records found on ' . date('d/m/Y', strtotime($date)));
        $sheet->getStyle('A1')->getFont()->setBold(true);
        
        $filename = 'daily_pnl_report_' . date('Y-m-d', strtotime($date)) . '.xlsx';
        $directory = storage_path('app/public/reports/pnl');
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filePath = $directory . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        
        return $filePath;
    }

    protected function uploadToOneDrive($filePath, $date)
    {
        try {
            $filename = basename($filePath);
            // ✅ Use P&L_report folder
            $folderPath = env('ONEDRIVE_PNL_FOLDER_PATH', '/P&L_report/');
            $userEmail = env('ONEDRIVE_USER', env('GRAPH_INVOICE_USER'));
            
            $this->info("📤 Uploading to OneDrive: {$folderPath}{$filename}");
            
            if (!file_exists($filePath) || !is_readable($filePath)) {
                $this->error("❌ File not found or not readable: {$filePath}");
                return false;
            }
            
            $content = file_get_contents($filePath);
            
            if (empty($content)) {
                $this->error("❌ File is empty: {$filePath}");
                return false;
            }
            
            $graphService = new MicrosoftGraphService();
            
            // Get token via reflection
            $reflection = new \ReflectionProperty($graphService, 'accessToken');
            $reflection->setAccessible(true);
            $token = $reflection->getValue($graphService);
            
            if (empty($token)) {
                $this->error("❌ No access token available.");
                return false;
            }
            
            $encodedFolder = str_replace(' ', '%20', $folderPath);
            $uploadUrl = "https://graph.microsoft.com/v1.0/users/{$userEmail}/drive/root:{$encodedFolder}{$filename}:/content";
            
            $client = new \GuzzleHttp\Client([
                'timeout' => 120,
                'verify' => false,
            ]);
            
            $response = $client->put($uploadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
                'body' => $content,
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->info("✅ Uploaded to OneDrive: {$folderPath}{$filename}");
                Log::info("Uploaded daily P&L report to OneDrive: {$folderPath}{$filename}");
                return true;
            } else {
                $this->error("❌ Upload failed: Status " . $response->getStatusCode());
                return false;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Upload error: " . $e->getMessage());
            Log::error("OneDrive upload error: " . $e->getMessage());
            return false;
        }
    }

    protected function sendNotificationEmail($date)
    {
        try {
            $formattedDate = date('d/m/Y', strtotime($date));
            $to = 'reetha@aahaas.com';
            $subject = " Daily P&L Report Uploaded - {$formattedDate}";
            
            $message = "Dear Team,\n\n";
            $message .= "The daily P&L report for {$formattedDate} has been successfully uploaded to the shared OneDrive folder.\n\n";
            $message .= "📁 Folder: P&L_report\n";
            $message .= "📄 File: daily_pnl_report_{$date}.xlsx\n\n";
            $message .= "You can access the report from the shared OneDrive folder.\n\n";
            $message .= "─────────────────────────────\n";
            $message .= "Generated by: Invoice Processing System\n";
            $message .= "Time: " . now()->format('d/m/Y H:i:s') . "\n";
            $message .= "─────────────────────────────\n";
            $message .= "This is an automated notification.\n";

            Mail::raw($message, function ($mail) use ($to, $subject) {
                $mail->to($to)->subject($subject);
            });

            $this->info("📧 Notification email sent to: {$to}");
            Log::info("Daily P&L report notification sent to: {$to}");

        } catch (\Exception $e) {
            $this->error("❌ Failed to send notification: " . $e->getMessage());
            Log::error("Failed to send notification: " . $e->getMessage());
        }
    }

    protected function getCurrencySymbol($countryCode)
    {
        $symbols = [
            'LK' => 'LKR',
            'VN' => 'VND',
            'SG' => 'SGD',
            'MY' => 'MYR',
        ];
        return $symbols[$countryCode] ?? 'USD';
    }

    protected function getLatestPnLRecords($records)
    {
        if ($records->isEmpty()) {
            return $records;
        }
        
        $grouped = [];
        
        foreach ($records as $record) {
            $baseIsNumber = $record->original_is_number ?? $record->is_number;
            
            if (preg_match('/^([A-Z]{2}\d+)_R\d+\/R\d+$/', $record->is_number, $match)) {
                $baseIsNumber = $match[1];
            }
            
            $key = $baseIsNumber;
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = $record;
            } else {
                $existing = $grouped[$key];
                
                $currentHasRevision = preg_match('/_R\d+\/R\d+$/', $record->is_number);
                $existingHasRevision = preg_match('/_R\d+\/R\d+$/', $existing->is_number);
                
                if ($currentHasRevision && !$existingHasRevision) {
                    $grouped[$key] = $record;
                } elseif ($currentHasRevision && $existingHasRevision) {
                    preg_match('/_R(\d+)\/R(\d+)$/', $record->is_number, $currentMatch);
                    preg_match('/_R(\d+)\/R(\d+)$/', $existing->is_number, $existingMatch);
                    
                    $currentVer = intval($currentMatch[2] ?? 0);
                    $existingVer = intval($existingMatch[2] ?? 0);
                    
                    if ($currentVer > $existingVer) {
                        $grouped[$key] = $record;
                    }
                } else {
                    $currentDate = $record->received_at ?? $record->created_at;
                    $existingDate = $existing->received_at ?? $existing->created_at;
                    
                    if ($currentDate > $existingDate) {
                        $grouped[$key] = $record;
                    }
                }
            }
        }
        
        return collect(array_values($grouped));
    }
}