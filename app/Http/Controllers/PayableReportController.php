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
        
        // ✅ For Malaysia and Singapore - use separate layout
        if ($country === 'MY' || $country === 'SG') {
            return view('payable-report.malaysia', [
                'countries' => $countries,
                'selectedCountry' => $country,
                'countryName' => $countries[$country],
                'hotels' => $result['hotels'] ?? [],
                'tickets' => $result['tickets'] ?? [],
                'summary' => $result['summary'] ?? [],
                'exchangeRate' => $result['exchange_rate'] ?? 1,
                'date' => $result['date'] ?? now()->format('Y-m-d'),
                'targetDate' => $targetDate,
                'checkInDate' => $result['check_in_date'] ?? '',
                'deadlineDays' => $deadlineDays,
                'totalCount' => $result['total_count'] ?? 0,
                'deadline' => $result['deadline'] ?? 'D-4',
                'today' => now()->format('Y-m-d'),
                'currency' => $result['currency'] ?? ($country === 'MY' ? 'MYR' : 'SGD'),
            ]);
        }
        
        // ✅ For Vietnam - use Vietnam layout
        if ($country === 'VN') {
            return view('payable-report.vietnam', [
                'countries' => $countries,
                'selectedCountry' => $country,
                'countryName' => $countries[$country],
                'payables' => $result['payables'] ?? [],
                'summary' => $result['summary'] ?? [],
                'exchangeRate' => $result['exchange_rate'] ?? 25500,
                'date' => $result['date'] ?? now()->format('Y-m-d'),
                'targetDate' => $targetDate,
                'checkInDate' => $result['check_in_date'] ?? '',
                'deadlineDays' => $deadlineDays,
                'totalCount' => $result['total_count'] ?? 0,
                'deadline' => $result['deadline'] ?? 'D-4',
                'today' => now()->format('Y-m-d'),
            ]);
        }
        
        // ✅ For Sri Lanka - use full layout
        return view('payable-report.index', [
            'countries' => $countries,
            'selectedCountry' => $country,
            'countryName' => $countries[$country],
            'payables' => $result['payables'] ?? [],
            'summary' => $result['summary'] ?? [],
            'exchangeRate' => $result['exchange_rate'] ?? 330,
            'date' => $result['date'] ?? now()->format('Y-m-d'),
            'targetDate' => $targetDate,
            'checkInDate' => $result['check_in_date'] ?? '',
            'deadlineDays' => $deadlineDays,
            'totalCount' => $result['total_count'] ?? 0,
            'deadline' => $result['deadline'] ?? 'D-4',
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
        
        // ✅ For Malaysia and Singapore - export with different format
        if ($country === 'MY' || $country === 'SG') {
            return $this->exportMalaysiaSingapore($result, $country, $targetDate);
        }
        
        // ✅ For Vietnam - export with Vietnam format
        if ($country === 'VN') {
            return $this->exportVietnam($result, $country, $targetDate);
        }
        
        // ✅ For Sri Lanka - export with full format
        return $this->exportSriLanka($result, $country, $targetDate);
    }
    
    /**
     * Export for Malaysia and Singapore
     */
    protected function exportMalaysiaSingapore($result, $country, $targetDate)
    {
        $currency = $country === 'MY' ? 'MYR' : 'SGD';
        $hotels = $result['hotels'] ?? [];
        $tickets = $result['tickets'] ?? [];
        
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        
        $this->createMalaysiaHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate, $currency);
        $this->createMalaysiaTicketSheet($spreadsheet, $tickets, $result, $country, $targetDate, $currency);
        
        $spreadsheet->setActiveSheetIndex(0);
        
        $filename = "payable_report_{$country}_{$targetDate}.xlsx";
        $writer = new Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Export for Vietnam
     */
    protected function exportVietnam($result, $country, $targetDate)
    {
        $payables = $result['payables'] ?? [];
        
        $hotels = array_filter($payables, function($p) {
            return $p['type'] == 'HOTEL';
        });
        
        $tickets = array_filter($payables, function($p) {
            return in_array($p['type'], ['TRANSPORT', 'ATTRACTION', 'TOUR TRANSFER', 'MEALS', 'OTHER RATES']);
        });
        
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        
        // ✅ Create HOTEL sheet for Vietnam
        $this->createVietnamHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate);
        
        // ✅ Create TICKETS sheet for Vietnam
        $this->createVietnamTicketSheet($spreadsheet, $tickets, $result, $country, $targetDate);
        
        $spreadsheet->setActiveSheetIndex(0);
        
        $filename = "payable_report_{$country}_{$targetDate}.xlsx";
        $writer = new Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Export for Sri Lanka
     */
    protected function exportSriLanka($result, $country, $targetDate)
    {
        $hotels = array_filter($result['payables'], function($p) {
            return $p['type'] == 'HOTEL';
        });
        
        $transports = array_filter($result['payables'], function($p) {
            return $p['type'] == 'TRANSPORT';
        });
        
        $attractions = array_filter($result['payables'], function($p) {
            return $p['type'] == 'ATTRACTION';
        });
        
        $tourTransfers = array_filter($result['payables'], function($p) {
            return $p['type'] == 'TOUR TRANSFER';
        });
        
        $meals = array_filter($result['payables'], function($p) {
            return $p['type'] == 'MEALS';
        });
        
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        
        $this->createSriLankaHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate);
        $this->createSriLankaTransportSheet($spreadsheet, $transports, $result, $country, $targetDate);
        $this->createSriLankaAttractionSheet($spreadsheet, $attractions, $result, $country, $targetDate);
        $this->createSriLankaTourTransferSheet($spreadsheet, $tourTransfers, $result, $country, $targetDate);
        $this->createSriLankaMealsSheet($spreadsheet, $meals, $result, $country, $targetDate);
        
        $spreadsheet->setActiveSheetIndex(0);
        
        $filename = "payable_report_{$country}_{$targetDate}.xlsx";
        $writer = new Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
    
    /**
     * Create Vietnam Hotel Sheet
     */
    protected function createVietnamHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Hotels');
        
        // Header Info
        $sheet->setCellValue('A1', 'Payable Report - Hotels');
        $sheet->mergeCells('A1:U1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = VND ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'The payable request is generated based on the customer\'s travel date.');
        
        // Headers
        $headers = [
            'A8' => 'CNTL',
            'B8' => 'Tour',
            'C8' => 'Invoice',
            'D8' => 'Paid Amount',
            'E8' => 'Balance',
            'F8' => 'Start date',
            'G8' => 'End date',
            'H8' => 'Check In date',
            'I8' => 'Check out day',
            'J8' => 'Agent Type',
            'K8' => 'Agent',
            'L8' => 'Client',
            'M8' => 'Cost',
            'N8' => 'VND',
            'O8' => 'USD',
            'P8' => 'Process or not',
            'Q8' => 'A/C Name',
            'R8' => 'A/C Number',
            'S8' => 'Bank',
            'T8' => 'Branch',
            'U8' => 'Swift'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style Header
        $sheet->getStyle('A8:U8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(12);
        $sheet->getColumnDimension('K')->setWidth(20);
        $sheet->getColumnDimension('L')->setWidth(20);
        $sheet->getColumnDimension('M')->setWidth(12);
        $sheet->getColumnDimension('N')->setWidth(15);
        $sheet->getColumnDimension('O')->setWidth(12);
        $sheet->getColumnDimension('P')->setWidth(15);
        $sheet->getColumnDimension('Q')->setWidth(20);
        $sheet->getColumnDimension('R')->setWidth(15);
        $sheet->getColumnDimension('S')->setWidth(20);
        $sheet->getColumnDimension('T')->setWidth(15);
        $sheet->getColumnDimension('U')->setWidth(15);
        
        // Data
        $row = 9;
        foreach ($hotels as $payable) {
            $sheet->setCellValue('A' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, 'VND ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('G' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('H' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('I' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('J' . $row, $payable['agent_type'] ?? 'Credit');
            $sheet->setCellValue('K' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('L' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('M' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('N' . $row, number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('O' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('P' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('Q' . $row, $payable['ac_name'] ?? 'N/A');
            $sheet->setCellValue('R' . $row, $payable['account_number'] ?? 'N/A');
            $sheet->setCellValue('S' . $row, $payable['bank'] ?? 'N/A');
            $sheet->setCellValue('T' . $row, $payable['branch'] ?? 'N/A');
            $sheet->setCellValue('U' . $row, $payable['swift'] ?? 'N/A');
            $row++;
        }
        
        // Summary
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL HOTELS: ' . count($hotels));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D9:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E9:E' . ($row - 1) . ')');
        $sheet->setCellValue('M' . $summaryRow, '=SUM(M9:M' . ($row - 1) . ')');
        $sheet->setCellValue('N' . $summaryRow, '=SUM(N9:N' . ($row - 1) . ')');
        $sheet->setCellValue('O' . $summaryRow, '=SUM(O9:O' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':U' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    /**
     * Create Vietnam Ticket Sheet
     */
    protected function createVietnamTicketSheet($spreadsheet, $tickets, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Tickets');
        
        // Header Info
        $sheet->setCellValue('A1', 'Payable Report - Tickets & Attractions');
        $sheet->mergeCells('A1:U1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = VND ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'The payable request is generated based on the customer\'s travel date.');
        
        // Headers
        $headers = [
            'A8' => 'CNTL',
            'B8' => 'Tour',
            'C8' => 'Start',
            'D8' => 'End',
            'E8' => 'Agent Type',
            'F8' => 'Invoice',
            'G8' => 'Paid Amount',
            'H8' => 'Balance',
            'I8' => 'Agent',
            'J8' => 'Client',
            'K8' => 'Cost',
            'L8' => 'Amount (VND)',
            'M8' => 'Conversion',
            'N8' => 'USD',
            'O8' => 'Remark',
            'P8' => 'A/C Name',
            'Q8' => 'A/C Number',
            'R8' => 'Bank',
            'S8' => 'Branch',
            'T8' => 'Swift'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style Header
        $sheet->getStyle('A8:T8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(20);
        $sheet->getColumnDimension('J')->setWidth(20);
        $sheet->getColumnDimension('K')->setWidth(12);
        $sheet->getColumnDimension('L')->setWidth(15);
        $sheet->getColumnDimension('M')->setWidth(15);
        $sheet->getColumnDimension('N')->setWidth(12);
        $sheet->getColumnDimension('O')->setWidth(30);
        $sheet->getColumnDimension('P')->setWidth(20);
        $sheet->getColumnDimension('Q')->setWidth(15);
        $sheet->getColumnDimension('R')->setWidth(20);
        $sheet->getColumnDimension('S')->setWidth(15);
        $sheet->getColumnDimension('T')->setWidth(15);
        
        // Data
        $row = 9;
        foreach ($tickets as $payable) {
            $sheet->setCellValue('A' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('D' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('E' . $row, $payable['agent_type'] ?? 'Credit');
            $sheet->setCellValue('F' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('H' . $row, 'VND ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('I' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('J' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('K' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('L' . $row, number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('M' . $row, number_format($payable['exchange_rate'] ?? 25500, 2));
            $sheet->setCellValue('N' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('O' . $row, $payable['description'] ?? $payable['vendor_name'] ?? '');
            $sheet->setCellValue('P' . $row, $payable['ac_name'] ?? 'N/A');
            $sheet->setCellValue('Q' . $row, $payable['account_number'] ?? 'N/A');
            $sheet->setCellValue('R' . $row, $payable['bank'] ?? 'N/A');
            $sheet->setCellValue('S' . $row, $payable['branch'] ?? 'N/A');
            $sheet->setCellValue('T' . $row, $payable['swift'] ?? 'N/A');
            $row++;
        }
        
        // Summary
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL TICKETS: ' . count($tickets));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('G' . $summaryRow, '=SUM(G9:G' . ($row - 1) . ')');
        $sheet->setCellValue('H' . $summaryRow, '=SUM(H9:H' . ($row - 1) . ')');
        $sheet->setCellValue('K' . $summaryRow, '=SUM(K9:K' . ($row - 1) . ')');
        $sheet->setCellValue('L' . $summaryRow, '=SUM(L9:L' . ($row - 1) . ')');
        $sheet->setCellValue('N' . $summaryRow, '=SUM(N9:N' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':T' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1E7DD']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    // =============================================
    // MALAYSIA/SINGAPORE SHEETS
    // =============================================
    
    protected function createMalaysiaHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate, $currency)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Hotels');
        
        $sheet->setCellValue('A1', 'Payable Report - Hotels');
        $sheet->mergeCells('A1:S1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = ' . $currency . ' ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'This payable request is generated based on the travel date');
        
        $headers = [
            'A8' => 'CNTL', 'B8' => 'Tour', 'C8' => 'Invoice',
            'D8' => 'Paid Amount', 'E8' => 'Balance', 'F8' => 'Start date',
            'G8' => 'End date', 'H8' => 'Agent Type', 'I8' => 'Agent',
            'J8' => 'Client', 'K8' => 'Hotel', 'L8' => 'Payable',
            'M8' => 'Process or not', 'N8' => 'A/C Name', 'O8' => 'A/C Number',
            'P8' => 'Bank', 'Q8' => 'Branch', 'R8' => 'Swift Code', 'S8' => 'UEN'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A8:S8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 9;
        foreach ($hotels as $payable) {
            $sheet->setCellValue('A' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, $currency . ' ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('G' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('H' . $row, $payable['agent_type'] ?? 'Credit');
            $sheet->setCellValue('I' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('J' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('K' . $row, $payable['vendor_name'] ?? 'N/A');
            $sheet->setCellValue('L' . $row, $currency . ' ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('M' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('N' . $row, $payable['ac_name'] ?? 'N/A');
            $sheet->setCellValue('O' . $row, $payable['account_number'] ?? 'N/A');
            $sheet->setCellValue('P' . $row, $payable['bank'] ?? 'N/A');
            $sheet->setCellValue('Q' . $row, $payable['branch'] ?? 'N/A');
            $sheet->setCellValue('R' . $row, $payable['swift'] ?? 'N/A');
            $sheet->setCellValue('S' . $row, $payable['uen'] ?? 'N/A');
            $row++;
        }
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL HOTELS: ' . count($hotels));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D9:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E9:E' . ($row - 1) . ')');
        $sheet->setCellValue('L' . $summaryRow, '=SUM(L9:L' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':S' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    protected function createMalaysiaTicketSheet($spreadsheet, $tickets, $result, $country, $targetDate, $currency)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Tickets');
        
        $sheet->setCellValue('A1', 'Payable Report - Tickets & Attractions');
        $sheet->mergeCells('A1:U1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = ' . $currency . ' ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'Includes: Attractions, Tour Transfers, Meals, Other Rates, Transport');
        
        $headers = [
            'A8' => 'Travel Date', 'B8' => 'Tour', 'C8' => 'Invoice',
            'D8' => 'Paid Amount', 'E8' => 'Balance', 'F8' => 'Agent',
            'G8' => 'Description', 'H8' => 'Dest', 'I8' => 'Typ',
            'J8' => 'Budget', 'K8' => 'Global Tix', 'L8' => 'Travel Vago',
            'M8' => 'Suresh', 'N8' => 'Total', 'O8' => 'Difference',
            'P8' => 'Process or Hold', 'Q8' => 'A/C Name', 'R8' => 'A/C Number',
            'S8' => 'Bank', 'T8' => 'Branch', 'U8' => 'Swift Code'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A8:U8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 9;
        foreach ($tickets as $payable) {
            $sheet->setCellValue('A' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, $currency . ' ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, $payable['vendor_name'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, '-');
            $sheet->setCellValue('I' . $row, $payable['type'] ?? 'Ticket');
            $sheet->setCellValue('J' . $row, $currency . ' ' . number_format($payable['budgeted_total'] ?? 0, 2));
            $sheet->setCellValue('K' . $row, '-');
            $sheet->setCellValue('L' . $row, '-');
            $sheet->setCellValue('M' . $row, '-');
            $sheet->setCellValue('N' . $row, $currency . ' ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('O' . $row, '-');
            $sheet->setCellValue('P' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('Q' . $row, $payable['ac_name'] ?? 'N/A');
            $sheet->setCellValue('R' . $row, $payable['account_number'] ?? 'N/A');
            $sheet->setCellValue('S' . $row, $payable['bank'] ?? 'N/A');
            $sheet->setCellValue('T' . $row, $payable['branch'] ?? 'N/A');
            $sheet->setCellValue('U' . $row, $payable['swift'] ?? 'N/A');
            $row++;
        }
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL TICKETS: ' . count($tickets));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D9:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E9:E' . ($row - 1) . ')');
        $sheet->setCellValue('J' . $summaryRow, '=SUM(J9:J' . ($row - 1) . ')');
        $sheet->setCellValue('N' . $summaryRow, '=SUM(N9:N' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':U' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1E7DD']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    // =============================================
    // SRI LANKA SHEETS
    // =============================================
    
    protected function createSriLankaHotelSheet($spreadsheet, $hotels, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Hotels');
        
        $sheet->setCellValue('A1', 'Payable Report - Hotels');
        $sheet->mergeCells('A1:W1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        
        $headers = [
            'A7' => 'CNTL', 'B7' => 'Tour', 'C7' => 'Invoice',
            'D7' => 'Paid Amount', 'E7' => 'Balance', 'F7' => 'Start Date',
            'G7' => 'End Date', 'H7' => 'Check out date', 'I7' => 'Agent Type',
            'J7' => 'Agent', 'K7' => 'Client', 'L7' => 'Hotel',
            'M7' => 'USD', 'N7' => 'Budgeted Total', 'O7' => 'Exchange Rate',
            'P7' => 'Payable', 'Q7' => 'Hold / Process',
            'R7' => 'A/C Name', 'S7' => 'Bank', 'T7' => 'A/C Number',
            'U7' => 'Branch', 'V7' => 'Bank and Branch', 'W7' => 'SWIFT'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A7:W7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 8;
        foreach ($hotels as $payable) {
            $sheet->setCellValue('A' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, 'LKR ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('G' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('H' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('I' . $row, $payable['agent_type'] ?? 'Credit');
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
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL HOTELS: ' . count($hotels));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D8:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E8:E' . ($row - 1) . ')');
        $sheet->setCellValue('M' . $summaryRow, '=SUM(M8:M' . ($row - 1) . ')');
        $sheet->setCellValue('N' . $summaryRow, '=SUM(N8:N' . ($row - 1) . ')');
        $sheet->setCellValue('P' . $summaryRow, '=SUM(P8:P' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':W' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    protected function createSriLankaTransportSheet($spreadsheet, $transports, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Transport');
        
        $sheet->setCellValue('A1', 'Payable Report - Transport');
        $sheet->mergeCells('A1:P1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'Collect 30% of the transport payment in advance to cover fuel expenses.');
        
        $headers = [
            'A8' => 'Date', 'B8' => 'Tour Number', 'C8' => 'Invoice',
            'D8' => 'Paid Amount', 'E8' => 'balance', 'F8' => 'Agent',
            'G8' => 'Advance %', 'H8' => 'Fuel Advance', 'I8' => 'Tour Advance',
            'J8' => 'Payable', 'K8' => 'Process or Hold',
            'L8' => 'Driver Name', 'M8' => 'A/C Name',
            'N8' => 'A/C Number', 'O8' => 'Bank and Branch', 'P8' => 'Transport Details'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A8:P8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 9;
        foreach ($transports as $payable) {
            $sheet->setCellValue('A' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
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
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL TRANSPORT: ' . count($transports));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D9:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E9:E' . ($row - 1) . ')');
        $sheet->setCellValue('H' . $summaryRow, '=SUM(H9:H' . ($row - 1) . ')');
        $sheet->setCellValue('I' . $summaryRow, '=SUM(I9:I' . ($row - 1) . ')');
        $sheet->setCellValue('J' . $summaryRow, '=SUM(J9:J' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':P' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1E7DD']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    protected function createSriLankaAttractionSheet($spreadsheet, $attractions, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Attractions');
        
        $sheet->setCellValue('A1', 'Payable Report - Attractions');
        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFC107']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'Deduct LKR 5,000 from the total attraction cost and collect the remaining amount as advance.');
        
        $headers = [
            'A8' => '#', 'B8' => 'Date', 'C8' => 'Tour', 'D8' => 'Invoice',
            'E8' => 'Attraction', 'F8' => 'Client', 'G8' => 'Agent',
            'H8' => 'USD', 'I8' => 'Total LKR', 'J8' => 'Deduction',
            'K8' => 'Advance LKR', 'L8' => 'Hold/Process', 'M8' => 'Details'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A8:M8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '000000'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFC107']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 9;
        foreach ($attractions as $index => $payable) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('C' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('E' . $row, $payable['vendor_name'] ?? 'N/A');
            $sheet->setCellValue('F' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('I' . $row, number_format($payable['budgeted_total'] ?? 0, 2));
            $sheet->setCellValue('J' . $row, '- LKR 5,000');
            $sheet->setCellValue('K' . $row, number_format($payable['advance_amount'] ?? 0, 2));
            $sheet->setCellValue('L' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('M' . $row, $payable['attraction_details'] ?? '');
            $row++;
        }
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL ATTRACTIONS: ' . count($attractions));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('H' . $summaryRow, '=SUM(H9:H' . ($row - 1) . ')');
        $sheet->setCellValue('I' . $summaryRow, '=SUM(I9:I' . ($row - 1) . ')');
        $sheet->setCellValue('K' . $summaryRow, '=SUM(K9:K' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':M' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    protected function createSriLankaTourTransferSheet($spreadsheet, $tourTransfers, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Tour Transfers');
        
        $sheet->setCellValue('A1', 'Payable Report - Tour Transfers');
        $sheet->mergeCells('A1:O1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'DC3545']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        $sheet->setCellValue('A6', 'Guide: Collect only the guide\'s accommodation cost as the advance payment.');
        
        $headers = [
            'A8' => '#', 'B8' => 'Date', 'C8' => 'Tour', 'D8' => 'Invoice',
            'E8' => 'Transfer', 'F8' => 'Client', 'G8' => 'Agent',
            'H8' => 'USD', 'I8' => 'Total LKR', 'J8' => 'Advance %',
            'K8' => 'Advance LKR', 'L8' => 'Balance LKR', 'M8' => 'Payable LKR',
            'N8' => 'Hold/Process', 'O8' => 'Details'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A8:O8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC3545']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 9;
        foreach ($tourTransfers as $index => $payable) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('C' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('E' . $row, $payable['vendor_name'] ?? 'N/A');
            $sheet->setCellValue('F' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('I' . $row, number_format($payable['budgeted_total'] ?? 0, 2));
            $sheet->setCellValue('J' . $row, ($payable['advance_percent'] ?? 20) . '%');
            $sheet->setCellValue('K' . $row, number_format($payable['advance_amount'] ?? 0, 2));
            $sheet->setCellValue('L' . $row, number_format($payable['balance_amount'] ?? 0, 2));
            $sheet->setCellValue('M' . $row, number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('N' . $row, $payable['hold_process'] ?? 'Process');
            $sheet->setCellValue('O' . $row, $payable['tour_transfer_details'] ?? '');
            $row++;
        }
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL TOUR TRANSFERS: ' . count($tourTransfers));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('H' . $summaryRow, '=SUM(H9:H' . ($row - 1) . ')');
        $sheet->setCellValue('I' . $summaryRow, '=SUM(I9:I' . ($row - 1) . ')');
        $sheet->setCellValue('K' . $summaryRow, '=SUM(K9:K' . ($row - 1) . ')');
        $sheet->setCellValue('L' . $summaryRow, '=SUM(L9:L' . ($row - 1) . ')');
        $sheet->setCellValue('M' . $summaryRow, '=SUM(M9:M' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':O' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
    
    protected function createSriLankaMealsSheet($spreadsheet, $meals, $result, $country, $targetDate)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Meals');
        
        $sheet->setCellValue('A1', 'Payable Report - Meals');
        $sheet->mergeCells('A1:O1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '6C757D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        $sheet->setCellValue('A2', 'Country: ' . $country);
        $sheet->setCellValue('A3', 'Target Date: ' . $targetDate);
        $sheet->setCellValue('A4', 'Exchange Rate: USD 1 = LKR ' . number_format($result['exchange_rate'], 2));
        $sheet->setCellValue('A5', 'Report Date: ' . now()->format('Y-m-d'));
        
        $headers = [
            'A7' => 'CNTL', 'B7' => 'Tour', 'C7' => 'Invoice',
            'D7' => 'Paid Amount', 'E7' => 'Balance', 'F7' => 'Start',
            'G7' => 'End', 'H7' => 'Type', 'I7' => 'Agent',
            'J7' => 'Client', 'K7' => 'Cost', 'L7' => 'Payable',
            'M7' => 'A/C Name', 'N7' => 'Bank', 'O7' => 'A/C Number'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        $sheet->getStyle('A7:O7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6C757D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        
        $row = 8;
        foreach ($meals as $payable) {
            $sheet->setCellValue('A' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('B' . $row, $payable['tour_number'] ?? 'N/A');
            $sheet->setCellValue('C' . $row, $payable['invoice_number'] ?? 'N/A');
            $sheet->setCellValue('D' . $row, '$' . number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('E' . $row, 'LKR ' . number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('F' . $row, isset($payable['start_date']) ? date('Y-m-d', strtotime($payable['start_date'])) : '');
            $sheet->setCellValue('G' . $row, isset($payable['end_date']) ? date('Y-m-d', strtotime($payable['end_date'])) : '');
            $sheet->setCellValue('H' . $row, $payable['type'] ?? 'MEALS');
            $sheet->setCellValue('I' . $row, $payable['agent_name'] ?? 'N/A');
            $sheet->setCellValue('J' . $row, $payable['client_name'] ?? 'N/A');
            $sheet->setCellValue('K' . $row, number_format($payable['usd_amount'] ?? 0, 2));
            $sheet->setCellValue('L' . $row, number_format($payable['payable_lkr'] ?? 0, 2));
            $sheet->setCellValue('M' . $row, $payable['ac_name'] ?? 'N/A');
            $sheet->setCellValue('N' . $row, $payable['bank'] ?? 'N/A');
            $sheet->setCellValue('O' . $row, $payable['account_number'] ?? 'N/A');
            $row++;
        }
        
        $summaryRow = $row + 1;
        $sheet->setCellValue('A' . $summaryRow, 'TOTAL MEALS: ' . count($meals));
        $sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
        $sheet->setCellValue('D' . $summaryRow, '=SUM(D8:D' . ($row - 1) . ')');
        $sheet->setCellValue('E' . $summaryRow, '=SUM(E8:E' . ($row - 1) . ')');
        $sheet->setCellValue('K' . $summaryRow, '=SUM(K8:K' . ($row - 1) . ')');
        $sheet->setCellValue('L' . $summaryRow, '=SUM(L8:L' . ($row - 1) . ')');
        
        $sheet->getStyle('A' . $summaryRow . ':O' . $summaryRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    }
}