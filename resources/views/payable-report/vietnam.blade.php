{{-- resources/views/payable-report/vietnam.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <h4><i class="fas fa-file-invoice-dollar"></i> Payable Report - {{ $countryName }}</h4>
                        <div>
                            <span class="badge bg-light text-dark me-2">
                                <i class="fas fa-calendar-day me-1"></i> Today: {{ $today }}
                            </span>
                            <span class="badge bg-warning">
                                <i class="fas fa-calendar-check me-1"></i> Check-in: {{ $checkInDate ?? $targetDate }}
                            </span>
                            <span class="badge bg-info text-dark ms-2">{{ $deadline }}</span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    
                    <!-- Country Selection & Filters -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <form method="GET" class="row g-3 align-items-end">
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-globe"></i> Country
                                            </label>
                                            <select name="country" class="form-select" onchange="this.form.submit()">
                                                @foreach($countries as $code => $name)
                                                    <option value="{{ $code }}" {{ $selectedCountry == $code ? 'selected' : '' }}>
                                                        {{ $name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-calendar"></i> Target Date
                                            </label>
                                            <input type="date" name="date" class="form-control" value="{{ $targetDate }}">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-clock"></i> Deadline
                                            </label>
                                            <select name="deadline" class="form-select">
                                                <option value="1" {{ $deadlineDays == 1 ? 'selected' : '' }}>D-1</option>
                                                <option value="2" {{ $deadlineDays == 2 ? 'selected' : '' }}>D-2</option>
                                                <option value="3" {{ $deadlineDays == 3 ? 'selected' : '' }}>D-3</option>
                                                <option value="4" {{ $deadlineDays == 4 ? 'selected' : '' }}>D-4</option>
                                                <option value="5" {{ $deadlineDays == 5 ? 'selected' : '' }}>D-5</option>
                                                <option value="7" {{ $deadlineDays == 7 ? 'selected' : '' }}>D-7</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="fas fa-search me-1"></i> Generate
                                            </button>
                                        </div>
                                        <div class="col-md-2">
                                            <a href="{{ route('payable.export', ['country' => $selectedCountry, 'date' => $targetDate, 'deadline' => $deadlineDays]) }}" 
                                               class="btn btn-success w-100">
                                                <i class="fas fa-file-excel me-1"></i> Export
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 col-6">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <h6>🏨 Hotels</h6>
                                    <h2>{{ $summary['hotels'] ?? 0 }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h6>🎫 Tickets</h6>
                                    <h2>{{ ($summary['transport'] ?? 0) + ($summary['attraction'] ?? 0) + ($summary['tour_transfers'] ?? 0) + ($summary['meals'] ?? 0) + ($summary['other_rates'] ?? 0) }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card bg-dark text-white">
                                <div class="card-body text-center">
                                    <h6>📊 Total</h6>
                                    <h2>{{ $totalCount }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h6>💱 Rate</h6>
                                    <h5>USD 1 = VND {{ number_format($exchangeRate, 2) }}</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Exchange Rate Alert -->
                    <div class="alert alert-info">
                        <i class="fas fa-exchange-alt me-2"></i>
                        <strong>Exchange Rate:</strong> USD 1 = VND {{ number_format($exchangeRate, 2) }}
                        <span class="ms-3"><i class="fas fa-calendar me-1"></i>Report Date: {{ $date }}</span>
                        <span class="ms-3"><i class="fas fa-flag me-1"></i>Country: {{ $countryName }}</span>
                        <span class="ms-3"><i class="fas fa-info-circle me-1"></i>The payable request is generated based on the customer's travel date</span>
                    </div>
                    
                    <!-- ============================================ -->
                    <!-- ✅ HOTEL TABLE - VIETNAM -->
                    <!-- ============================================ -->
                    @php
                        $hotels = array_filter($payables, function($p) {
                            return $p['type'] == 'HOTEL';
                        });
                    @endphp
                    
                    @if(!empty($hotels))
                    <div class="table-section mt-4">
                        <div class="table-header bg-info text-white p-2 rounded-top">
                            <h5 class="mb-0"><i class="fas fa-hotel me-2"></i> Hotel Payables</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-striped" id="hotelTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>CNTL</th>
                                        <th>Tour</th>
                                        <th>Invoice</th>
                                        <th>Paid Amount</th>
                                        <th>Balance</th>
                                        <th>Start date</th>
                                        <th>End date</th>
                                        <th>Check In date</th>
                                        <th>Check out day</th>
                                        <th>Agent Type</th>
                                        <th>Agent</th>
                                        <th>Client</th>
                                        <th>Cost</th>
                                        <th>VND</th>
                                        <th>USD</th>
                                        <th>Process or not</th>
                                        <th>A/C Name</th>
                                        <th>A/C Number</th>
                                        <th>Bank</th>
                                        <th>Branch</th>
                                        <th>Swift</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($hotels as $index => $payable)
                                        <tr>
                                            <td>{{ $payable['tour_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['tour_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['invoice_number'] ?? 'N/A' }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>VND {{ number_format($payable['payable_lkr'] ?? 0, 2) }}</td>
                                            <td>{{ isset($payable['start_date']) && $payable['start_date'] ? date('Y-m-d', strtotime($payable['start_date'])) : '-' }}</td>
                                            <td>{{ isset($payable['end_date']) && $payable['end_date'] ? date('Y-m-d', strtotime($payable['end_date'])) : '-' }}</td>
                                            <td>{{ isset($payable['start_date']) && $payable['start_date'] ? date('Y-m-d', strtotime($payable['start_date'])) : '-' }}</td>
                                            <td>{{ isset($payable['end_date']) && $payable['end_date'] ? date('Y-m-d', strtotime($payable['end_date'])) : '-' }}</td>
                                            <td>{{ $payable['agent_type'] ?? 'Credit' }}</td>
                                            <td>{{ $payable['agent_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['client_name'] ?? 'N/A' }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>{{ number_format($payable['payable_lkr'] ?? 0, 2) }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>
                                                <span class="badge bg-success">{{ $payable['hold_process'] ?? 'Process' }}</span>
                                            </td>
                                            <td>{{ $payable['ac_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['account_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['bank'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['branch'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['swift'] ?? 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @else
                    <div class="alert alert-warning mt-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No Hotel records found for the selected date.
                    </div>
                    @endif

                    <!-- ============================================ -->
                    <!-- ✅ TICKETS TABLE - VIETNAM -->
                    <!-- ============================================ -->
                    @php
                        $tickets = array_filter($payables, function($p) {
                            return in_array($p['type'], ['TRANSPORT', 'ATTRACTION', 'TOUR TRANSFER', 'MEALS', 'OTHER RATES']);
                        });
                    @endphp
                    
                    @if(!empty($tickets))
                    <div class="table-section mt-5">
                        <div class="table-header bg-success text-white p-2 rounded-top">
                            <h5 class="mb-0"><i class="fas fa-ticket-alt me-2"></i> Tickets & Attractions Payables</h5>
                            <small class="ms-3">Includes: Transport, Attractions, Tour Transfers, Meals, Other Rates</small>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-striped" id="ticketTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>CNTL</th>
                                        <th>Tour</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Agent Type</th>
                                        <th>Invoice</th>
                                        <th>Paid Amount</th>
                                        <th>Balance</th>
                                        <th>Agent</th>
                                        <th>Client</th>
                                        <th>Cost</th>
                                        <th>Amount (VND)</th>
                                        <th>Conversion</th>
                                        <th>USD</th>
                                        <th>Remark</th>
                                        <th>A/C Name</th>
                                        <th>A/C Number</th>
                                        <th>Bank</th>
                                        <th>Branch</th>
                                        <th>Swift</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($tickets as $index => $payable)
                                        <tr>
                                            <td>{{ $payable['tour_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['tour_number'] ?? 'N/A' }}</td>
                                            <td>{{ isset($payable['start_date']) && $payable['start_date'] ? date('Y-m-d', strtotime($payable['start_date'])) : '-' }}</td>
                                            <td>{{ isset($payable['end_date']) && $payable['end_date'] ? date('Y-m-d', strtotime($payable['end_date'])) : '-' }}</td>
                                            <td>{{ $payable['agent_type'] ?? 'Credit' }}</td>
                                            <td>{{ $payable['invoice_number'] ?? 'N/A' }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>VND {{ number_format($payable['payable_lkr'] ?? 0, 2) }}</td>
                                            <td>{{ $payable['agent_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['client_name'] ?? 'N/A' }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>{{ number_format($payable['payable_lkr'] ?? 0, 2) }}</td>
                                            <td>{{ number_format($payable['exchange_rate'] ?? 25500, 2) }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>{{ $payable['description'] ?? $payable['vendor_name'] ?? '-' }}</td>
                                            <td>{{ $payable['ac_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['account_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['bank'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['branch'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['swift'] ?? 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @else
                    <div class="alert alert-warning mt-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No Ticket records found for the selected date.
                    </div>
                    @endif
                    
                </div>
                <div class="card-footer">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button onclick="window.location.href='{{ route('payable.export', ['country' => $selectedCountry, 'date' => $targetDate, 'deadline' => $deadlineDays]) }}'" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    $(document).ready(function() {
        $('#hotelTable').DataTable({
            pageLength: 25,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'asc']],
        });
        
        $('#ticketTable').DataTable({
            pageLength: 25,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'asc']],
        });
    });
</script>
@endpush

<style>
    .table td, .table th {
        white-space: nowrap;
        font-size: 0.82rem;
    }
    .card-header {
        border-bottom: 0;
    }
    .badge {
        font-size: 0.72rem;
        padding: 4px 8px;
    }
    .bg-light .card-body {
        padding: 1rem;
    }
    .form-label {
        font-size: 0.85rem;
        margin-bottom: 0.25rem;
    }
    .table-section {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
    }
    .table-header {
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    .table-section .table {
        margin-bottom: 0;
    }
    .table-section .table thead th {
        background-color: #212529;
        color: white;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
</style>
@endsection