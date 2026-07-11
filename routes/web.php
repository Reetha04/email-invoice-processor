<?php

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PnlController;
use App\Http\Controllers\CreditController;
use App\Services\ClientManager;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\PayableReportController;

// ========== INVOICE ROUTES ==========
Route::get('/', [InvoiceController::class, 'index'])->name('index');
Route::get('/non-credit', [InvoiceController::class, 'nonCredit'])->name('non-credit');
Route::get('/credit', [InvoiceController::class, 'credit'])->name('credit');
Route::post('/process', [InvoiceController::class, 'processNow'])->name('process');
Route::post('/generate-invoice', [InvoiceController::class, 'generateAndViewInvoice'])->name('generate.and.view.invoice');
Route::post('/regenerate-invoice', [InvoiceController::class, 'regenerateInvoice'])->name('regenerate.invoice');
Route::get('/invoice/view/{id}', [InvoiceController::class, 'viewInvoice'])->name('invoice.view');
Route::get('/invoice/download/{id}', [InvoiceController::class, 'downloadInvoice'])->name('invoice.download');
Route::get('/email/view', [InvoiceController::class, 'viewEmail'])->name('email.view');

Route::get('/check-invoice-exists', [InvoiceController::class, 'checkInvoiceExists'])->name('check.invoice.exists');
Route::get('/get-email-invoice-number', [InvoiceController::class, 'getEmailInvoiceNumber'])->name('get.email.invoice.number');
Route::get('/check-invoice-by-number', [InvoiceController::class, 'checkInvoiceByNumber'])->name('check.invoice.by.number');
Route::get('/pnl/export-detailed/{id}', [PnlController::class, 'exportDetailedPnL'])->name('pnl.export.detailed');
// Detailed P&L View
Route::get('/pnl/view-detailed/{id}', [PnlController::class, 'viewDetailedPnL'])->name('pnl.view-detailed');
Route::get('/pnl/download-detailed/{id}', [PnlController::class, 'downloadDetailedPnL'])->name('pnl.download-detailed');
// ========== PNL ROUTES ==========
Route::prefix('pnl')->name('pnl.')->group(function () {
    Route::get('/', [PnlController::class, 'index'])->name('index');
    Route::post('/fetch', [PnlController::class, 'fetchEmails'])->name('fetch');
    Route::post('/update-status/{id}', [PnlController::class, 'updateStatus'])->name('update-status');
    Route::post('/mark-read/{id}', [PnlController::class, 'markAsRead'])->name('mark-read');
    Route::get('/view-email/{id}', [PnlController::class, 'viewEmail'])->name('view-email');
    Route::get('/items/{id}', [PnlController::class, 'viewItems'])->name('items');
    Route::get('/export', [PnlController::class, 'exportToExcel'])->name('export');  // Overall Export
    Route::get('/export-country/{country}', [PnlController::class, 'exportByCountry'])->name('export-country');  // Country-based Export
    Route::post('/update-excel', [PnlController::class, 'updateExcel'])->name('update-excel');
    // Route::get('/view-excel/{country}', [PnlController::class, 'viewExcel'])->name('view-excel');
    Route::get('/view-excel/{country}/{id?}', [PnlController::class, 'viewExcel'])->name('view-excel');
    Route::get('/export-country-approved/{country}', [PnlController::class, 'exportByCountryApproved'])->name('export-country-approved');
   Route::match(['get', 'post'], '/export-selected', [PnlController::class, 'exportSelected'])->name('export.selected');
    Route::get('/view-selected', [PnlController::class, 'viewSelected'])->name('view-selected');
      Route::post('/match-services', [PnlController::class, 'matchServices'])->name('match-services');
    Route::get('/match-services', [PnlController::class, 'matchServices'])->name('match-services');
});
// In routes/api.php or web.php

Route::prefix('hotel-match')->group(function () {
    Route::post('/record/{pnlRecordId}', [HotelMatchController::class, 'matchHotel']);
    Route::get('/preview/{pnlRecordId}', [HotelMatchController::class, 'preview']);
    Route::post('/match-all', [HotelMatchController::class, 'matchAll']);
});
Route::get('/test-mail', function () {
    $cm = new ClientManager();
    $client = $cm->make([
        'host'          => env('IMAP_HOST'),
        'port'          => env('IMAP_PORT'),
        'encryption'    => env('IMAP_ENCRYPTION'),
        'validate_cert' => false,
        'username'      => env('IMAP_USERNAME'),
        'password'      => env('IMAP_PASSWORD'),
        'protocol'      => 'imap'
    ]);
    $client->connect();
    return 'Connected Successfully';
});

Route::post('/generate-revised-invoice', [InvoiceController::class, 'generateRevisedInvoice'])->name('generate.revised.invoice');
Route::get('/payable-report', [PayableReportController::class, 'index'])->name('payable.report');
Route::get('/payable-report/export', [PayableReportController::class, 'export'])->name('payable.export');

// ========== REPORT ROUTES ==========
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/month-wise', [ReportController::class, 'monthWise'])->name('month-wise');
    Route::get('/date-wise', [ReportController::class, 'dateWise'])->name('date-wise');
    Route::get('/export/month-wise', [ReportController::class, 'exportMonthWise'])->name('export.month-wise');
    Route::get('/export/date-wise', [ReportController::class, 'exportDateWise'])->name('export.date-wise');
});