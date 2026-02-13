@extends('layouts.app')

@section('content')
<main class="main">
    <div class="responsive-wrapper">
        <div class="main-header">
            <h1>Dashboard</h1>
            <span class="dashboard-badge">{{ \Carbon\Carbon::now()->format('M d, Y \a\t h:i A') }}</span>
        </div>

        @can('superadmin')
        {{-- Superadmin: Modern Dashboard with Charts --}}
        <div class="dashboard-stats-grid">
            <div class="stat-card stat-card--primary">
                <div class="stat-card__icon"><i class="bx bx-wallet"></i></div>
                <div class="stat-card__content">
                    <span class="stat-card__label">Today's Collection</span>
                    <span class="stat-card__value">₱{{ number_format($data['today_paid'] ?? 0, 2) }}</span>
                    <span class="stat-card__sub">{{ $data['today_count'] ?? 0 }} transactions</span>
                </div>
            </div>
            <div class="stat-card stat-card--success">
                <div class="stat-card__icon"><i class="bx bx-check-circle"></i></div>
                <div class="stat-card__content">
                    <span class="stat-card__label">Total Paid</span>
                    <span class="stat-card__value">₱{{ number_format($data['total_paid'] ?? 0, 2) }}</span>
                </div>
            </div>
            <div class="stat-card stat-card--warning">
                <div class="stat-card__icon"><i class="bx bx-time-five"></i></div>
                <div class="stat-card__content">
                    <span class="stat-card__label">Unpaid Amount</span>
                    <span class="stat-card__value">₱{{ number_format($data['total_unpaid'] ?? 0, 2) }}</span>
                </div>
            </div>
            <div class="stat-card stat-card--info">
                <div class="stat-card__icon"><i class="bx bx-file"></i></div>
                <div class="stat-card__content">
                    <span class="stat-card__label">Readings</span>
                    <span class="stat-card__value">{{ number_format($data['total_readings'] ?? 0) }}</span>
                </div>
            </div>
        </div>

        <div class="dashboard-stats-row">
            <div class="stat-pill"><i class="bx bx-user"></i><span>{{ $data['admins'] ?? 0 }} Admins</span></div>
            <div class="stat-pill"><i class="bx bx-group"></i><span>{{ $data['concessionaires'] ?? 0 }} Concessionaires</span></div>
            <div class="stat-pill"><i class="bx bx-wrench"></i><span>{{ $data['technicians'] ?? 0 }} Technicians</span></div>
            <div class="stat-pill"><i class="bx bx-receipt"></i><span>{{ $data['total_transactions_count'] ?? 0 }} Paid Bills</span></div>
            <div class="stat-pill stat-pill--accent"><i class="bx bx-credit-card"></i><span>{{ $data['unique_online_payments'] ?? 0 }} Online Payments</span></div>
            <div class="stat-pill stat-pill--accent"><i class="bx bx-log-in-circle"></i><span>{{ $data['concessionaire_accounts'] ?? 0 }} Concessionaire Accounts</span></div>
        </div>

        <div class="dashboard-charts-grid">
            <div class="chart-card chart-card--wide">
                <div class="chart-card__header"><h3>Revenue Trend</h3><span class="chart-card__sub">Last 12 months</span></div>
                <div id="chartRevenue" class="chart-card__body"></div>
            </div>
            <div class="chart-card">
                <div class="chart-card__header"><h3>Payment Method</h3><span class="chart-card__sub">Cash vs Online</span></div>
                <div id="chartPaymentMethod" class="chart-card__body"></div>
            </div>
        </div>
        <div class="dashboard-charts-grid">
            <div class="chart-card chart-card--full">
                <div class="chart-card__header"><h3>Readings by Zone</h3><span class="chart-card__sub">This month</span></div>
                <div id="chartZone" class="chart-card__body chart-card__body--tall"></div>
            </div>
        </div>

        @else
        {{-- Admin/Cashier: Simple Dashboard --}}
        <div class="row mt-5">
            <div class="col-12 col-md-4 mb-3">
                <div class="card shadow p-3">
                    <div class="card-body">
                        <h4 class="mb-3 text-uppercase fw-lighter">Admins</h4>
                        <h1>{{ number_format($data['admins'] ?? 0) }}</h1>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 mb-3">
                <div class="card shadow p-3">
                    <div class="card-body">
                        <h4 class="mb-3 text-uppercase fw-lighter">Concessionaires</h4>
                        <h1>{{ number_format($data['concessionaires'] ?? 0) }}</h1>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 mb-3">
                <div class="card shadow p-3">
                    <div class="card-body">
                        <h4 class="mb-3 text-uppercase fw-lighter">Technicians</h4>
                        <h1>{{ number_format($data['technicians'] ?? 0) }}</h1>
                    </div>
                </div>
            </div>
        </div>
        <div><hr class="mt-3 mb-3"><p class="text-uppercase text-muted fw-bold">Updated: <span class="text-decoration-underline fst-italic">{{ \Carbon\Carbon::now()->format('F d, Y \a\t h:i A') }}</span></p></div>
        <div class="row">
            <div class="col-12 col-md-4 mb-3">
                <div class="card shadow p-3">
                    <div class="card-body">
                        <h4 class="mb-3 text-uppercase fw-lighter">Unpaid Amount</h4>
                        <h1>₱{{ number_format($data['total_unpaid'] ?? 0, 2) }}</h1>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 mb-3">
                <div class="card shadow p-3">
                    <div class="card-body">
                        <h4 class="mb-3 text-uppercase fw-lighter">Paid Amount</h4>
                        <h1>₱{{ number_format($data['total_paid'] ?? 0, 2) }}</h1>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 mb-3">
                <div class="card shadow p-3">
                    <div class="card-body">
                        <h4 class="mb-3 text-uppercase fw-lighter">Total Amount</h4>
                        <h1>₱{{ number_format($data['total_transactions'] ?? 0, 2) }}</h1>
                    </div>
                </div>
            </div>
        </div>
        @endcan
    </div>
</main>
@endsection

@can('superadmin')
@section('script')
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const revenueOptions = {
        series: [{ name: 'Collection (₱)', data: @json($data['chart_monthly_data'] ?? []) }],
        chart: { type: 'area', height: 320, fontFamily: 'Be Vietnam Pro, sans-serif', toolbar: { show: false } },
        colors: ['#434ce8'],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05 } },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { categories: @json($data['chart_monthly_labels'] ?? []), labels: { style: { fontSize: '11px' } } },
        yaxis: { labels: { formatter: function(v) { return '₱' + (v >= 1000 ? (v/1000).toFixed(1) + 'k' : v); } } },
        grid: { borderColor: '#eff1f6', strokeDashArray: 4 },
        tooltip: { y: { formatter: function(v) { return '₱' + v.toLocaleString('en-PH', {minimumFractionDigits: 2}); } } }
    };
    new ApexCharts(document.querySelector('#chartRevenue'), revenueOptions).render();

    const cashCount = {{ $data['payment_method_count']['cash'] ?? 0 }};
    const onlineCount = {{ $data['payment_method_count']['online'] ?? 0 }};
    const otherCount = Math.max(0, {{ $data['total_transactions_count'] ?? 0 }} - cashCount - onlineCount);
    const paymentSeries = [cashCount, onlineCount, otherCount].filter(v => v > 0);
    const paymentLabels = [];
    if (cashCount > 0) paymentLabels.push('Cash');
    if (onlineCount > 0) paymentLabels.push('Online');
    if (otherCount > 0) paymentLabels.push('Other');
    if (paymentSeries.length > 0) {
        new ApexCharts(document.querySelector('#chartPaymentMethod'), {
            series: paymentSeries, chart: { type: 'donut', height: 280 }, labels: paymentLabels,
            colors: ['#00d084', '#434ce8', '#686b87'], legend: { position: 'bottom' },
            plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true } } } } }
        }).render();
    } else {
        document.querySelector('#chartPaymentMethod').innerHTML = '<div class="chart-empty"><span>No paid transactions yet</span></div>';
    }

    const zoneData = @json($data['chart_zone_data'] ?? []);
    if (zoneData.length > 0) {
        new ApexCharts(document.querySelector('#chartZone'), {
            series: [{ name: 'Readings', data: zoneData }],
            chart: { type: 'bar', height: 320 },
            colors: ['#434ce8'],
            plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '60%' } },
            dataLabels: { enabled: true },
            xaxis: { categories: @json($data['chart_zone_labels'] ?? []), labels: { style: { fontSize: '11px' } } },
            grid: { borderColor: '#eff1f6', strokeDashArray: 4 }
        }).render();
    } else {
        document.querySelector('#chartZone').innerHTML = '<div class="chart-empty"><span>No readings this month</span></div>';
    }
});
</script>
@endsection
@endcan
