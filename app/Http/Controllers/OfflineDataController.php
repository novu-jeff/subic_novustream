<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserAccounts;
use App\Models\Zones;
use App\Models\Rates;
use App\Models\PropertyTypes;
use App\Models\PaymentDiscount;
use App\Models\PaymentBreakdownPenalty;
use App\Models\Reading;
use App\Models\Bill;
use App\Models\ReadingDate;
use App\Services\MeterService;

class OfflineDataController extends Controller
{
    public function download(Request $request)
    {

        // STATIC TOKEN AUTHENTICATION
        // $token = $request->header('X-API-KEY');

        // if ($token !== config('app.offline_api_key')) {
        //     return response()->json(['error' => 'Unauthorized'], 403);
        // }

        $user = \App\Models\User::first();


        // if (!$user) {
        //     return response()->json(['error' => 'Unauthenticated'], 401);
        // }

        // if (!in_array($user->user_type, ['technician', 'admin'])) {
        //     return response()->json(['error' => 'Unauthorized'], 403);
        // }

        // ✅ Determine technician’s assigned zones
        // Optional: ?include=accounts,readings — only return requested sections (default: all)
        $includeParam = $request->query('include', '');
        $include = array_map('trim', array_filter(explode(',', strtolower($includeParam))));
        $wantAccounts = empty($include) || in_array('accounts', $include, true);
        $wantReadings = empty($include) || in_array('readings', $include, true);

        set_time_limit(300);
        ini_set('memory_limit', '512M');
        $limit = (int) $request->query('limit', 0);
        $offset = (int) $request->query('offset', 0);
        if ($limit <= 0) {
            $limit = 0;
        }

        $zoneIds = $user->zone_assigned ? explode(',', $user->zone_assigned) : [];
        $zones = Zones::whereIn('id', $zoneIds)->pluck('zone');

        // ✅ Fetch accounts with latest reading + related user
        $accountsQuery = UserAccounts::with([
                'user',
                'readings' => function ($q) {
                    $q->latest()->limit(1);
                }
            ])
            ->when($zones->isNotEmpty(), function ($query) use ($zones) {
                $query->where(function ($q) use ($zones) {
                    foreach ($zones as $zone) {
                        $q->orWhere('account_no', 'like', "{$zone}%");
                    }
                });
            });

        $totalAccounts = $accountsQuery->count();
        if ($limit > 0) {
            $accountsQuery->skip($offset)->take($limit);
        }
        $accounts = $accountsQuery->get();

        

        // ✅ Compute unpaid + previous_reading BEFORE mapping to array
        $previousReadings = [];
        $readingsList = [];
        $readingsToExport = [];

        foreach ($accounts as $acc) {
            $latest = Reading::with('bill')
                ->where('account_no', $acc->account_no)
                ->latest('created_at')
                ->first();

            $previousReadings[$acc->account_no] = [
                'present_reading' => $latest?->present_reading ?? 0,
                'created_at'      => $latest?->created_at ?? null,
            ];

            if ($wantReadings && $latest && $latest->bill) {
                $bill = $latest->bill;
                $refNo = $bill->reference_no ?? null;
                if ($refNo) {
                    $readingsToExport[] = ['refNo' => $refNo, 'latest' => $latest, 'bill' => $bill];
                }
            }
        }

        if ($wantReadings && !empty($readingsToExport)) {
            $refNos = array_column($readingsToExport, 'refNo');
            $billsWithBreakdown = Bill::with('breakdown')->whereIn('reference_no', array_unique($refNos))->get()->keyBy('reference_no');
            foreach ($readingsToExport as $item) {
                $refNo = $item['refNo'];
                $latest = $item['latest'];
                $bill = $billsWithBreakdown->get($refNo) ?? $item['bill'];
                $soaData = self::minimalSoaFromModels($refNo, $latest, $bill);
                $readingsList[] = [
                    'reference_no'         => $refNo,
                    'account_no'           => $latest->account_no,
                    'previous_reading'     => (float) ($latest->previous_reading ?? 0),
                    'present_reading'      => (float) ($latest->present_reading ?? 0),
                    'consumption'          => (float) ($latest->consumption ?? 0),
                    'is_high_consumption'  => isset($bill->isHighConsumption) ? (int) $bill->isHighConsumption : 0,
                    'high_consumption_note'=> (string) ($bill->high_consumption_note ?? ''),
                    'amount'               => (float) ($bill->amount ?? 0),
                    'amount_after_due'     => (float) ($bill->amount_after_due ?? $bill->amount ?? 0),
                    'timestamp'            => $latest->created_at ? $latest->created_at->format('c') : date('c'),
                    'soa_json'             => json_encode($soaData),
                ];
            }
        }


        // ✅ Now transform to clean arrays for frontend (only when requested)
        $accountsPayload = $accounts;
        if ($wantAccounts) {
            $accountsPayload = $accounts->map(function ($acc) use ($previousReadings) {
            // \Log::info('[DEBUG OFFLINE] Account structure:', $acc->toArray());
            $unpaid = Bill::whereHas('reading', function ($q) use ($acc) {
                    $q->where('account_no', $acc->account_no);
                })
                ->where('isPaid', false)
                ->sum('amount');

            return [
                'account_no'       => $acc->account_no,
                'name'             => $acc->user->name ?? 'N/A',
                'address'          => $acc->address,
                'meter_serial_no'  => $acc->meter_serial_no,
                'zone'             => $acc->zone,
                'status'           => $acc->status ?? null,
                'property_type_id' => $acc->property_types_by_name->id ?? null,
                'discount_type'    => $acc->discount->discount_type_id ?? 0,
                'previous_reading' => $previousReadings[$acc->account_no]['present_reading'] ?? 0,
                'unpaid_amount'    => $unpaid,
                'created_at'       => $previousReadings[$acc->account_no]['created_at'] ?? null,
                'sequence_no'      => $acc->sequence_no ?? null,
            ];

        })->sortBy(function ($account) {
            return $account['sequence_no'] ?? PHP_INT_MAX;
        })->values();
        }

        // ✅ Rates, Property Types, Discounts, Penalties (only when accounts requested)
        $rates = $wantAccounts ? Rates::select('property_types_id', 'cu_m', 'amount')->get() : [];
        $propertyTypes = $wantAccounts ? PropertyTypes::select('id', 'name')->get() : [];
        $discounts = $wantAccounts ? PaymentDiscount::select('eligible', 'type', 'amount', 'percentage_of')->get() : [];
        $penalties = $wantAccounts ? PaymentBreakdownPenalty::select('due_from', 'due_to', 'amount_type', 'amount')->get() : [];

        // ✅ Build response from requested sections
        $data = [];
        if ($wantAccounts) {
            $data['accounts'] = $accountsPayload;
            $data['rates'] = $rates;
            $data['property_types'] = $propertyTypes;
            $data['discounts'] = $discounts;
            $data['penalties'] = $penalties;
        }
        if ($wantReadings) {
            $data['readings'] = $readingsList;
        }
        if ($limit > 0) {
            $data['_meta'] = [
                'total_accounts' => $totalAccounts,
                'limit'          => $limit,
                'offset'         => $offset,
            ];
        }

        return response()->json($data);
    }

    /**
     * Build minimal SOA data for offline download (enough to generate/view/print SOA).
     * Omits client dump, previous_payment, active_payment, unpaid_bills, previousConsumption.
     */
    private static function minimalSoaForDownload($fullBill, string $refNo, $reading, $bill): array
    {
        if (!is_array($fullBill) || ($fullBill['status'] ?? null) === 'error') {
            return self::minimalSoaFromModels($refNo, $reading, $bill);
        }
        $cb = $fullBill['current_bill'] ?? [];
        $client = $fullBill['client'] ?? [];
        $readingArr = $cb['reading'] ?? [];
        $breakdown = $cb['breakdown'] ?? [];
        return [
            'reference_no'         => $cb['reference_no'] ?? $refNo,
            'account_no'           => $cb['bill_account_no'] ?? $client['account_no'] ?? $reading->account_no ?? '',
            'bill_period_from'     => $cb['bill_period_from'] ?? null,
            'bill_period_to'      => $cb['bill_period_to'] ?? null,
            'due_date'             => $cb['due_date'] ?? null,
            'previous_unpaid'      => $cb['previous_unpaid'] ?? 0,
            'total'                => $cb['total'] ?? 0,
            'discount'             => $cb['discount'] ?? 0,
            'penalty'              => $cb['penalty'] ?? 0,
            'amount'               => $cb['amount'] ?? 0,
            'amount_after_due'     => $cb['amount_after_due'] ?? 0,
            'isPaid'               => $cb['isPaid'] ?? false,
            'date_paid'            => $cb['date_paid'] ?? null,
            'payor_name'           => $cb['payor_name'] ?? $client['name'] ?? '',
            'bill_owner_name'      => $cb['bill_owner_name'] ?? $client['name'] ?? '',
            'bill_account_no'      => $cb['bill_account_no'] ?? $client['account_no'] ?? '',
            'bill_address'         => $cb['bill_address'] ?? $client['address'] ?? '',
            'bill_meter_serial_no' => $cb['bill_meter_serial_no'] ?? $client['meter_serial_no'] ?? '',
            'reading'              => [
                'previous_reading' => $readingArr['previous_reading'] ?? $reading->previous_reading ?? 0,
                'present_reading'  => $readingArr['present_reading'] ?? $reading->present_reading ?? 0,
                'consumption'      => $readingArr['consumption'] ?? $reading->consumption ?? 0,
            ],
            'breakdown'            => array_values(array_map(function ($row) {
                return [
                    'name'        => $row['name'] ?? '',
                    'description' => $row['description'] ?? '',
                    'amount'      => $row['amount'] ?? 0,
                ];
            }, is_array($breakdown) ? $breakdown : [])),
        ];
    }

    private static function minimalSoaFromModels(string $refNo, $reading, $bill): array
    {
        $breakdown = [];
        if ($bill->relationLoaded('breakdown') && $bill->breakdown) {
            $breakdown = $bill->breakdown->map(function ($row) {
                return ['name' => $row->name ?? '', 'description' => $row->description ?? '', 'amount' => $row->amount ?? 0];
            })->values()->toArray();
        }
        return [
            'reference_no'         => $refNo,
            'account_no'           => $reading->account_no ?? '',
            'bill_period_from'     => $bill->bill_period_from ?? null,
            'bill_period_to'      => $bill->bill_period_to ?? null,
            'due_date'             => $bill->due_date ?? null,
            'previous_unpaid'      => $bill->previous_unpaid ?? 0,
            'total'                => $bill->total ?? 0,
            'discount'             => $bill->discount ?? 0,
            'penalty'              => $bill->penalty ?? 0,
            'amount'               => $bill->amount ?? 0,
            'amount_after_due'     => $bill->amount_after_due ?? $bill->amount ?? 0,
            'isPaid'               => (bool) ($bill->isPaid ?? false),
            'date_paid'            => $bill->date_paid ?? null,
            'payor_name'           => $bill->payor_name ?? '',
            'bill_owner_name'      => $bill->bill_owner_name ?? $bill->payor_name ?? '',
            'bill_account_no'      => $bill->bill_account_no ?? $reading->account_no ?? '',
            'bill_address'         => $bill->bill_address ?? '',
            'bill_meter_serial_no' => $bill->bill_meter_serial_no ?? '',
            'reading'              => [
                'previous_reading' => (float) ($reading->previous_reading ?? 0),
                'present_reading'   => (float) ($reading->present_reading ?? 0),
                'consumption'      => (float) ($reading->consumption ?? 0),
            ],
            'breakdown'            => $breakdown,
        ];
    }

    /**
     * GET /offline/reading-dates — same usage as offline/download for mobile offline app.
     * Returns reading_dates table data as JSON.
     */
    public function readingDates(Request $request)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('reading_dates')) {
            return response()->json(['reading_dates' => []]);
        }

        $rows = ReadingDate::with('zone')->orderBy('zone_id')->get();
        $readingDates = $rows->map(function ($rd) {
            return [
                'id'               => $rd->id,
                'zone_id'          => $rd->zone_id,
                'zone'             => $rd->zone ? $rd->zone->zone : null,
                'bill_period_from' => $rd->bill_period_from,
                'bill_period_to'   => $rd->bill_period_to,
                'due_date'         => $rd->due_date,
                'is_active'        => (bool) $rd->is_active,
            ];
        })->values();

        return response()->json(['reading_dates' => $readingDates]);
    }
}