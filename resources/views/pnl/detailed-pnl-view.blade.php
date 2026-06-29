@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">
                    <i class="fas fa-file-invoice me-2"></i>
                    Detailed Profit & Loss Statement
                    <small class="text-muted ms-2">{{ $record->tour_ref ?? 'N/A' }}</small>
                </h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('pnl.index') }}" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="{{ route('pnl.download-detailed', $record->id) }}" class="btn btn-success btn-sm">
                        <i class="fas fa-download"></i> Download Excel
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body">
            {!! $html !!}
        </div>
    </div>
</div>

<style>
    .table th {
        background-color: #f8f9fa;
    }
    .table-bordered {
        border: 1px solid #dee2e6;
    }
    .table-sm th, .table-sm td {
        padding: 6px 10px;
        font-size: 14px;
    }
    .fw-bold {
        font-weight: 700;
    }
    .text-success {
        color: #28a745 !important;
    }
    .text-danger {
        color: #dc3545 !important;
    }
</style>
@endsection