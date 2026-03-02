<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Reading;
use App\Services\DashboardService;
use App\Services\MeterService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    protected $dashboardService;
    protected $meterService;

    public function __construct(DashboardService $dashboardService, MeterService $meterService)
    {
        $this->middleware(function ($request, $next) {
            if (Gate::allows('technician') || Gate::allows('inspector')) {
                return response()->view('others.restricted');
            }

        if (!Gate::any(['admin', 'cashier', 'superadmin'])) {
            abort(403, 'Unauthorized');
        }

            return $next($request);
        });

        $this->dashboardService = $dashboardService;
        $this->meterService = $meterService;
    }

    public function index()
    {
        $users = $this->dashboardService->getAllUsers() ?? [];
        $readings = $this->meterService->getReport() ?? collect([]);

        $total_unpaid = $readings
            ->where('bill.isPaid', false)
            ->sum(fn ($r) =>
                (float) ($r['bill']['previous_unpaid'] ?? 0) +
                (float) ($r['bill']['amount'] ?? 0) +
                (float) ($r['bill']['penalty'] ?? 0)
            );

        $total_paid = $readings
            ->where('bill.isPaid', true)
            ->sum(fn ($r) => (float) ($r['bill']['amount_paid'] ?? 0));

        $total_transactions = $total_paid + $total_unpaid;
        $total_payments = $readings->sum(fn ($r) => (float) ($r['bill']['amount'] ?? 0));
        $total_transactions_count = $readings->where('bill.isPaid', true)->count();

        $payment_method_count = $readings
            ->where('bill.isPaid', true)
            ->groupBy('bill.payment_method')
            ->map(fn ($group) => $group->count());

        // Chart: Monthly revenue (last 12 months)
        $startDate = Carbon::now()->subMonths(11)->startOfMonth();
        $monthlyRevenue = Bill::query()
            ->join('readings', 'bill.reading_id', '=', 'readings.id')
            ->where('bill.isPaid', true)
            ->whereNotNull('bill.date_paid')
            ->where('readings.isReRead', false)
            ->where('bill.date_paid', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(bill.date_paid, '%Y-%m') as month"),
                DB::raw('COALESCE(SUM(CAST(bill.amount_paid AS DECIMAL(15,2))), 0) as total')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month')
            ->toArray();

        // Fill gaps for last 12 months
        $allMonths = collect();
        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i);
            $key = $m->format('Y-m');
            $allMonths->put($key, (float) ($monthlyRevenue[$key] ?? 0));
        }
        $chartMonthlyLabels = $allMonths->keys()->map(fn ($m) => Carbon::parse($m . '-01')->format('M Y'))->values()->toArray();
        $chartMonthlyData = $allMonths->values()->toArray();

        // Chart: Readings by zone
        $readingsByZone = Reading::query()
            ->where('isReRead', false)
            ->whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->select('zone', DB::raw('COUNT(*) as cnt'))
            ->groupBy('zone')
            ->orderByDesc('cnt')
            ->limit(8)
            ->pluck('cnt', 'zone')
            ->toArray();
        $chartZoneLabels = array_keys($readingsByZone);
        $chartZoneData = array_values(array_map('intval', $readingsByZone));

        // Today's collection
        $todayPaid = Bill::query()
            ->where('isPaid', true)
            ->whereDate('date_paid', Carbon::today())
            ->sum(DB::raw('CAST(amount_paid AS DECIMAL(15,2))'));
        $todayCount = Bill::query()
            ->where('isPaid', true)
            ->whereDate('date_paid', Carbon::today())
            ->count();

        // Superadmin-only metrics
        $uniqueOnlinePayments = 0;
        $concessionaireAccounts = 0;
        if (Gate::allows('superadmin')) {
            $uniqueOnlinePayments = Bill::query()
                ->join('readings', 'bill.reading_id', '=', 'readings.id')
                ->where('bill.isPaid', true)
                ->where('bill.payment_method', 'online')
                ->where('readings.isReRead', false)
                ->count();
            $concessionaireAccounts = \App\Models\User::whereNotNull('email')
                ->where('email', '!=', '')
                ->whereNotNull('contact_no')
                ->where('contact_no', '!=', '')
                ->count();
        }

        $data = [
            'admins' => $users['admins'] ?? 0,
            'concessionaires' => $users['concessionaires'] ?? 0,
            'technicians' => $users['technicians'] ?? 0,
            'total_readings' => $readings->count(),
            'total_transactions' => $total_transactions,
            'total_unpaid' => $total_unpaid,
            'total_paid' => $total_paid,
            'total_payments' => $total_payments,
            'total_transactions_count' => $total_transactions_count,
            'payment_method_count' => $payment_method_count,
            'today_paid' => (float) $todayPaid,
            'today_count' => (int) $todayCount,
            'chart_monthly_labels' => $chartMonthlyLabels,
            'chart_monthly_data' => $chartMonthlyData,
            'chart_zone_labels' => $chartZoneLabels,
            'chart_zone_data' => $chartZoneData,
            'unique_online_payments' => $uniqueOnlinePayments,
            'concessionaire_accounts' => $concessionaireAccounts,
        ];

        return view('dashboard', compact('data'));
    }
}
