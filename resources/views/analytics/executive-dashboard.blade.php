@extends('layouts.app')

@section('content')

    <div class="container-fluid" id="dashboardContent">
        @include('analytics.partials.executive-dashboard-content')
    </div>

@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="{{ asset('js/executive-dashboard-charts.js') }}"></script>
@endpush