{{-- resources/views/payable-report/index.blade.php --}}
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
                        <div class="col-md-2 col-6">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <h6>🏨 Hotels</h6>
                                    <h2>{{ $summary['hotels'] ?? 0 }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h6>🚗 Transport</h6>
                                    <h2>{{ $summary['transport'] ?? 0 }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h6>🎯 Attraction</h6>
                                    <h2>{{ $summary['attraction'] ?? 0 }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h6>🔄 Tour Transfers</h6>
                                    <h2>{{ $summary['tour_transfers'] ?? 0 }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="card bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h6>🍽️ Meals</h6>
                                    <h2>{{ $summary['meals'] ?? 0 }}</h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6">
                            <div class="card bg-dark text-white">
                                <div class="card-body text-center">
                                    <h6>📊 Total</h6>
                                    <h2>{{ $totalCount }}</h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Exchange Rate Alert -->
                    <div class="alert alert-info">
                        <i class="fas fa-exchange-alt me-2"></i>
                        <strong>Exchange Rate:</strong> USD 1 = LKR {{ number_format($exchangeRate, 2) }}
                        <span class="ms-3"><i class="fas fa-calendar me-1"></i>Report Date: {{ $date }}</span>
                        <span class="ms-3"><i class="fas fa-flag me-1"></i>Country: {{ $countryName }}</span>
                    </div>
                    
                    <!-- ============================================ -->
                    <!-- ✅ HOTEL TABLE -->
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
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Tour</th>
                                        <th>Invoice</th>
                                        <th>Hotel Name</th>
                                        <th>Paid Amount</th>
                                        <th>Balance</th>
                                        <th>Agent</th>
                                        <th>Payable LKR</th>
                                        <th>Hold/Process</th>
                                        <th>A/C Name</th>
                                        <th>Bank</th>
                                        <th>A/C No.</th>
                                        <th>Branch</th>
                                        <th>SWIFT</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($hotels as $index => $payable)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td>{{ $payable['check_in_date'] ?? '-' }}</td>
                                            <td>{{ $payable['tour_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['invoice_number'] ?? 'N/A' }}</td>
                                            <td><strong>{{ $payable['vendor_name'] ?? 'N/A' }}</strong></td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>LKR {{ number_format($payable['payable_lkr'] ?? 0, 2) }}</td>
                                            <td>{{ $payable['agent_name'] ?? 'N/A' }}</td>
                                            <td><strong>LKR {{ number_format($payable['payable_lkr'] ?? 0, 2) }}</strong></td>
                                            <td>
                                                <span class="badge bg-success">Process</span>
                                            </td>
                                            <td>{{ $payable['ac_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['bank'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['account_number'] ?? 'N/A' }}</td>
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
                    <!-- ✅ TRANSPORT TABLE -->
                    <!-- ============================================ -->
                    @php
                        $transports = array_filter($payables, function($p) {
                            return $p['type'] == 'TRANSPORT';
                        });
                    @endphp
                    
                    @if(!empty($transports))
                    <div class="table-section mt-5">
                        <div class="table-header bg-success text-white p-2 rounded-top">
                            <h5 class="mb-0"><i class="fas fa-truck me-2"></i> Transport Payables</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-striped" id="transportTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th>Tour</th>
                                        <th>Invoice</th>
                                        <th>Paid Amount</th>
                                        <th>Balance</th>
                                        <th>Agent</th>
                                        <th>Advance %</th>
                                        <th>Fuel Advance</th>
                                        <th>Tour Advance</th>
                                        <th>Payable LKR</th>
                                        <th>Hold/Process</th>
                                        <th>Driver Name</th>
                                        <th>A/C Name</th>
                                        <th>A/C No.</th>
                                        <th>Bank & Branch</th>
                                        <th>Transport Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($transports as $index => $payable)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td>{{ $payable['check_in_date'] ?? '-' }}</td>
                                            <td>{{ $payable['tour_number'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['invoice_number'] ?? 'N/A' }}</td>
                                            <td>${{ number_format($payable['usd_amount'] ?? 0, 2) }}</td>
                                            <td>LKR {{ number_format($payable['payable_lkr'] ?? 0, 2) }}</td>
                                            <td>{{ $payable['agent_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['advance_percent'] ?? 0 }}%</td>
                                            <td>LKR {{ number_format($payable['fuel_advance'] ?? 0, 2) }}</td>
                                            <td>LKR {{ number_format($payable['tour_advance'] ?? 0, 2) }}</td>
                                            <td><strong>LKR {{ number_format($payable['payable_lkr'] ?? 0, 2) }}</strong></td>
                                            <td>
                                                <span class="badge bg-success">Process</span>
                                            </td>
                                            <td>{{ $payable['driver_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['driver_name'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['driver_account'] ?? 'N/A' }}</td>
                                            <td>{{ $payable['driver_bank'] ?? 'N/A' }}</td>
                                            <td>
                                                <small class="text-muted">{{ $payable['transport_details'] ?? 'N/A' }}</small>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @else
                    <div class="alert alert-warning mt-4">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No Transport records found for the selected date.
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
        
        $('#transportTable').DataTable({
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