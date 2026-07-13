<?php
// app/Console/Commands/TestDailyReport.php

namespace App\Console\Commands;

use App\Models\GeneratedInvoice;
use App\Models\IncomingEmail;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TestDailyReport extends Command
{
    protected $signature = 'report:test {--date=}';
    protected $description = 'Test daily report generation without upload or email';

    protected $exchangeRateService;

    public function __construct(ExchangeRateService $exchangeRateService)
    {
        parent::__construct();
        $this->exchangeRateService = $exchangeRateService;
    }

    public function handle()
    {
        $date = $this->option('date') ?? date('Y-m-d');
        $this->info("📊 Testing daily report for: {$date}");
        
        $invoices = GeneratedInvoice::with('email')
            ->whereDate('created_at', $date)
            ->orderBy('created_at', 'asc')
            ->get();
        
        if ($invoices->isEmpty()) {
            $this->info("📭 No invoices found for {$date}");
            
            // Generate empty report for testing
            $this->generateEmptyReport($date);
            return 0;
        }
        
        $this->info("📄 Found " . $invoices->count() . " invoices");
        
        // Show a preview of the data with USD conversion
        $this->showPreview($invoices);
        
        // Generate the actual report
        $this->generateTestReport($date, $invoices);
        
        $this->info("✅ Report saved to: storage/app/public/reports/daily/");
        return 0;
    }

    /**
     * ✅ Show preview of data with USD conversion
     */
    protected function showPreview($invoices)
    {
        $this->newLine();
        $this->info("📊 Preview of USD Conversion:");
        $this->newLine();
        
        $rows = [];
        foreach ($invoices->take(10) as $invoice) {
            $amount = $invoice->grand_total ?? 0;
            $currency = $invoice->currency ?? 'LKR';
            $usdAmount = $this->convertToUSD($amount, $currency);
            
            $rows[] = [
                $invoice->invoice_number,
                $amount,
                $currency,
                number_format($usdAmount, 2),
                'USD'
            ];
        }
        
        $this->table(
            ['Invoice #', 'Amount', 'Currency', 'USD Amount', 'Converted To'],
            $rows
        );
        
        $this->newLine();
    }

    /**
     * ✅ Convert any currency to USD
     */
    protected function convertToUSD($amount, $currency)
    {
        // If amount is 0 or null, return 0
        if (empty($amount) || $amount == 0) {
            return 0;
        }

        // If already USD, return the amount as-is
        if (strtoupper($currency) === 'USD') {
            return $amount;
        }

        try {
            // Get rate from currency to INR using ExchangeRateService
            $inrRate = $this->exchangeRateService->getRate($currency, 'INR');
            
            // Get USD to INR rate
            $usdToInrRate = $this->exchangeRateService->getUsdToInrRate();
            
            // Convert to INR first, then to USD
            $amountInInr = $amount * $inrRate;
            $usdAmount = $amountInInr / $usdToInrRate;
            
            $this->info("💰 Conversion: {$amount} {$currency} → {$amountInInr} INR → {$usdAmount} USD");
            
            return $usdAmount;
            
        } catch (\Exception $e) {
            $this->warn("⚠️ Could not get exchange rate for currency: {$currency}, using fallback");
            
            // ✅ Fallback: Direct rates to USD
            $fallbackRates = [
                'LKR' => 0.0030,   // 1 LKR = 0.0030 USD
                'INR' => 0.0120,   // 1 INR = 0.0120 USD
                'EUR' => 1.09,
                'GBP' => 1.27,
                'SGD' => 0.74,
                'MYR' => 0.21,
                'VND' => 0.000039,
                'AED' => 0.27,
                'SAR' => 0.27,
                'QAR' => 0.27,
                'KWD' => 3.26,
                'BHD' => 2.65,
            ];

            $rate = $fallbackRates[strtoupper($currency)] ?? 1;
            return $amount * $rate;
        }
    }

    protected function generateTestReport($date, $invoices)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // ✅ Headers: Only USD Amount column (no INR)
        $headers = [
            'S.No', 
            'Invoice #', 
            'Tour Ref', 
            'Agent Name', 
            'Agent ID',
            'Guest Name',
            'Amount', 
            'Currency',
            'USD Amount',     // ✅ Only USD Amount - no INR
            'File Handler', 
            'Tour Start Date',
            'Travel Date', 
            'Sales Person', 
            'GST No', 
            'Revision', 
            'Created At'
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
        $totalUSD = 0;
        
        foreach ($invoices as $invoice) {
            $col = 'A';
            $amount = $invoice->grand_total ?? 0;
            $currency = $invoice->currency ?? 'LKR';
            
            // ✅ Only convert to USD (no INR)
            $usdAmount = $this->convertToUSD($amount, $currency);
            
            $sheet->setCellValue($col++ . $row, $sno++);
            $sheet->setCellValue($col++ . $row, $invoice->invoice_number);
            $sheet->setCellValue($col++ . $row, $invoice->tour_ref);
            $sheet->setCellValue($col++ . $row, $invoice->customer_name);
            $sheet->setCellValue($col++ . $row, $invoice->email->reference_no ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->guest_name);
            $sheet->setCellValue($col++ . $row, $amount);
            $sheet->setCellValue($col++ . $row, $currency);
            $sheet->setCellValue($col++ . $row, number_format($usdAmount, 2));    // ✅ USD Amount
            $sheet->setCellValue($col++ . $row, $invoice->email->file_handler ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->email->travel_start_date ? date('d/m/Y', strtotime($invoice->email->travel_start_date)) : 'NA');
            $sheet->setCellValue($col++ . $row, $this->getTravelDates($invoice->email));
            $sheet->setCellValue($col++ . $row, $invoice->sales_person ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->gst_number ?? 'NA');
            $sheet->setCellValue($col++ . $row, $invoice->is_revision ? 'R' . $invoice->revision_number : 'Original');
            $sheet->setCellValue($col++ . $row, $invoice->created_at->format('d/m/Y H:i'));
            
            $totalAmount += $amount;
            $totalUSD += $usdAmount;
            $row++;
        }
        
        // ✅ Summary Row
        $row++;
        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('G' . $row, $totalAmount);        // Amount column (G)
        $sheet->setCellValue('I' . $row, number_format($totalUSD, 2));   // USD Amount column (I)
        $sheet->getStyle('A' . $row . ':P' . $row)->getFont()->setBold(true);
        
        // ✅ Auto-size all columns (A to P = 16 columns)
        foreach (range('A', 'P') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $filename = 'daily_invoice_report_' . date('Y-m-d', strtotime($date)) . '.xlsx';
        $directory = storage_path('app/public/reports/daily');
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filePath = $directory . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        
        $this->info("📄 Report saved: {$filePath}");
        $this->info("📊 Total Amount: {$totalAmount}");
        $this->info("💵 Total USD: $" . number_format($totalUSD, 2));
    }

    protected function generateEmptyReport($date)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'No invoices generated on ' . date('d/m/Y', strtotime($date)));
        $sheet->getStyle('A1')->getFont()->setBold(true);
        
        $filename = 'daily_invoice_report_' . date('Y-m-d', strtotime($date)) . '.xlsx';
        $directory = storage_path('app/public/reports/daily');
        
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $filePath = $directory . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        
        $this->info("📄 Empty report saved: {$filePath}");
    }

    protected function getTravelDates($email)
    {
        if (!$email) return 'NA';
        
        $start = $email->travel_start_date ? date('d/m/Y', strtotime($email->travel_start_date)) : '';
        $end = $email->travel_end_date ? date('d/m/Y', strtotime($email->travel_end_date)) : '';
        
        if ($start && $end && $start !== $end) {
            return $start . ' - ' . $end;
        }
        return $start ?: 'NA';
    }
}