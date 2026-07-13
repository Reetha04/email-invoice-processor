<?php
// app/Http/Controllers/PayableReportController.php

namespace App\Http\Controllers;

use App\Services\PayableReportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class PayableReportController extends Controller
{
    protected $payableService;
    
    public function __construct(PayableReportService $payableService)
    {
        $this->payableService = $payableService;
    }
    
    public function index(Request $request)
    {
        $countries = [
            'LK' => '🇱🇰 Sri Lanka',
            'MY' => '🇲🇾 Malaysia',
            'SG' => '🇸🇬 Singapore',
            'VN' => '🇻🇳 Vietnam',
        ];
        
        $country = $request->get('country', 'LK');
        $deadlineDays = (int) $request->get('deadline', 4);
        $targetDate = $request->get('date', now()->format('Y-m-d'));
        
        $result = $this->payableService->generateReport($country, $targetDate, $deadlineDays);
        
        if (!$result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to generate report');
        }
        
        return view('payable-report.index', [
            'countries' => $countries,
            'selectedCountry' => $country,
            'payables' => $result['payables'] ?? [],
            'summary' => $result['summary'] ?? [],
            'exchangeRate' => $result['exchange_rate'] ?? 330,
            'date' => $result['date'] ?? now()->format('Y-m-d'),
            'targetDate' => $targetDate,
            'checkInDate' => $result['check_in_date'] ?? '',
            'deadlineDays' => $deadlineDays,
            'totalCount' => $result['total_count'] ?? 0,
            'deadline' => $result['deadline'] ?? 'D-4',
            'countryName' => $countries[$country] ?? $country,
            'today' => now()->format('Y-m-d'),
        ]);
    }
    
    public function export(Request $request)
    {
        $country = $request->get('country', 'LK');
        $deadlineDays = (int) $request->get('deadline', 4);
        $targetDate = $request->get('date', now()->format('Y-m-d'));
        
        $result = $this->payableService->generateReport($country, $targetDate, $deadlineDays);
        
        if (!$result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to generate report');
        }
        
        // ✅ Split into Hotel and Transport
        $hotels = array_filter($result['payables'], function($p) {
            return $p['type'] == 'HOTEL';
        });
        
        $transports = array_filter($result['payables'], function($p) {
            return $p['type'] == 'TRANSPORT';
        });
        
        // ✅ Create new spreadsheet
        $spreadsheet = new Spreadsheet();
        
        // ✅ Remove default sheet first
        $spreadsheet->removeSheetByIndex(0);
        
        // ✅ Create HOTEL sheet
        $this->createHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate);
        
        // ✅ Create TRANSPORT sheet
        $this->createTransportSheet($spreadsheet, $transports, $result, $country, $targetDate);
        
        // Set active sheet to first sheet (Hotels)
        $spreadsheet->setActiveSheetIndex(0);
        
        // ✅ Save file
        $filename = "payable_report_{$country}_{$targetDate}.xlsx";
        $writer = new Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Create Hotel Sheet - EXACT FORMAT
     */
    protected function createHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Hotels');
        
        // ✅ Header Row with Report Info
        $sheet->setCellValue('A1', 'Payable Report - Hotels');
        $sheet->mergeCells('A1:O1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        
        // ✅ Headers (Row 7) - EXACT FORMAT
        $headers = [
            'A7' => 'CNTL',
            'B7' => 'Tour',
            'C7' => 'Invoice',
            'D7' => 'Paid Amount',
            'E7' => 'Balance',
            'F7' => 'Start Date',
            'G7' => 'End Date',
            'H7' => 'Check out date',
            'I7' => 'Agent Type',
            'J7' => 'Agent',
            'K7' => 'Client',
            'L7' => 'Hotel',
            'M7' => 'USD',
            'N7' => 'Budgeted Total',
            'O7' => 'Exchange Rate',
            'P7' => 'Payable',
            'Q7' => 'Hold / Process',
            'R7' => 'A/C Name',
            'S7' => 'Bank',
            'T7' => 'A/C Number',
            'U7' => 'Branch',
            'V7' => 'Bank and Branch',
            'W7' => 'SWIFT'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // ✅ Style Header
        $headerRange = 'A7:W7';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ]);
        
        // ✅ Set column widths
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(15);
        $sheet->getColumnDimension('K')->setWidth(15);
        $sheet->getColumnDimension('L')->setWidth(20);
        $sheet->getColumnDimension('M')->setWidth(10);
        $sheet->getColumnDimension('N')->setWidth(12);
        $sheet->getColumnDimension('O')->setWidth(12);
        $sheet->getColumnDimension('P')->setWidth(12);
        $sheet->getColumnDimension('Q')->setWidth(12);
        $sheet->getColumnDimension('R')->setWidth(20);
        $sheet->getColumnDimension('S')->setWidth(15);
        $sheet->getColumnDimension('T')->setWidth(15);
        $sheet->getColumnDimension('U')->setWidth(15);
        $sheet->getColumnDimension('V')->setWidth(20);
        $sheet->getColumnDimension('W')->setWidth(15);
        
        // ✅ Data (Start from Row 8)
        $row = 8;
        foreach ($hotels as $index => $payable) {
            $sheet->setCellValue('A' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, 'LKR ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, $payable['check_in_date'] ?? '');
            $sheet->setCellValue('G' . $row, $payable['check_out_date'] ?? '');
            $sheet->setCellValue('H' . $row, $payable['check_out_date'] ?? '');
            $sheet->setCellValue('I' . $row, 'Credit');
            $sheet->setCellValue('J' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('K' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('L' . $row, $payable['vendor_name'] ?? 'N/A');
            $sheet->setCellValue('M' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('N' . $row, $payable['budgeted_total'] ?? 0);
            $sheet->setCellValue('O' . $row, number_format($payable['exchange_rate'] ?? 1, 2));
            $sheet->setCellValue('P' . $row, $payable['payable_lkr'] ?? 0);
            $sheet->setCellValue('Q' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('R' . $row, $payable['ac_name'] ?? 'N/A');
            $sheet->setCellValue('S' . $row, $payable['bank'] ?? 'N/A');
            $sheet->setCellValue('T' . $row, $payable['account_number'] ?? 'N/A');
            $sheet->setCellValue('U' . $row, $payable['branch'] ?? 'N/A');
            $sheet->setCellValue('V' . $row, $payable['bank_and_branch'] ?? 'N/A');
            $sheet->setCellValue('W' . $row, $payable['swift'] ?? 'N/A');
            $row++;
        }
        
        // ✅ Add Summary Row
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL HOTELS: ' . count($hotels));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D8:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E8:E' . ($row - 1) . ')');
        $sheet->setCellValue('M' . $summaryRow, '=SUM(M8:M' . ($row - 1) . ')');
        $sheet->setCellValue('N' . $summaryRow, '=SUM(N8:N' . ($row - 1) . ')');
        $sheet->setCellValue('P' . $summaryRow, '=SUM(P8:P' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':W' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ]);
    }
    
    /**
     * Create Transport Sheet - EXACT FORMAT
     */
    protected function createTransportSheet($spreadsheet, $transports, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Transport');
        
        // ✅ Header Row with Report Info
        $sheet->setCellValue('A1', 'Payable Report - Transport');
        $sheet->mergeCells('A1:Q1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        
        // ✅ Headers (Row 7) - EXACT FORMAT
        $headers = [
            'A7' => 'Date',
            'B7' => 'Tour Number',
            'C7' => 'Invoice',
            'D7' => 'Paid Amount',
            'E7' => 'balance',
            'F7' => 'Agent',
            'G7' => 'Advacne %',
            'H7' => 'Fuel Advance',
            'I7' => 'Tour Advance',
            'J7' => 'Payable',
            'K7' => 'Process or Hold',
            'L7' => 'Driver Name',
            'M7' => 'A/C Name',
            'N7' => 'A/C Number',
            'O7' => 'Bank and Branch',
            'P7' => 'Transport Details'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // ✅ Style Header
        $headerRange = 'A7:P7';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ]);
        
        // ✅ Set column widths
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(15);
        $sheet->getColumnDimension('J')->setWidth(15);
        $sheet->getColumnDimension('K')->setWidth(15);
        $sheet->getColumnDimension('L')->setWidth(20);
        $sheet->getColumnDimension('M')->setWidth(20);
        $sheet->getColumnDimension('N')->setWidth(15);
        $sheet->getColumnDimension('O')->setWidth(25);
        $sheet->getColumnDimension('P')->setWidth(50);
        
        // ✅ Data (Start from Row 8)
        $row = 8;
        foreach ($transports as $index => $payable) {
            $sheet->setCellValue('A' . $row, $payable['check_in_date'] ?? '');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, 'LKR ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, ($payable['advance_percent'] ?? 0) . '%');
            $sheet->setCellValue('H' . $row, 'LKR ' . number_format($payable['fuel_advance'] ?? 0, 2));
            $sheet->setCellValue('I' . $row, 'LKR ' . number_format($payable['tour_advance'] ?? 0, 2));
            $sheet->setCellValue('J' . $row, 'LKR ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('K' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('L' . $row, $payable['driver_name'] ?? 'N/A');
            $sheet->setCellValue('M' . $row, $payable['driver_name'] ?? 'N/A');
            $sheet->setCellValue('N' . $row, $payable['driver_account'] ?? 'N/A');
            $sheet->setCellValue('O' . $row, $payable['driver_bank'] ?? 'N/A');
            $sheet->setCellValue('P' . $row, $payable['transport_details'] ?? 'N/A');
            $row++;
        }
        
        // ✅ Add Summary Row
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL TRANSPORT: ' . count($transports));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D8:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E8:E' . ($row - 1) . ')');
        $sheet->setCellValue('H' . $summaryRow, '=SUM(H8:H' . ($row - 1) . ')');
        $sheet->setCellValue('I' . $summaryRow, '=SUM(I8:I' . ($row - 1) . ')');
        $sheet->setCellValue('J' . $summaryRow, '=SUM(J8:J' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':P' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1E7DD']],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]
            ]
        ]);
    }
}