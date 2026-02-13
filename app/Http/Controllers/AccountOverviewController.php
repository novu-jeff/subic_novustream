<?php

namespace App\Http\Controllers;

use App\Services\ClientService;
use App\Services\GenerateService;
use App\Services\MeterService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentBreakdownPenalty;
use App\Models\Bill;

class AccountOverviewController extends Controller
{

    public $clientService;
    public $meterService;
    public $generateService;

    public function __construct(ClientService $clientService, MeterService $meterService, GenerateService $generateService) {
        $this->clientService = $clientService;
        $this->meterService = $meterService;
        $this->generateService = $generateService;
    }

public function index()
{
    $my = Auth::user()->load('property_types', 'accounts.sc_discount');
    $id = $my->id;

    $data = $this->clientService::getData($id);
    $accounts = $data->accounts ?? [];

    $statement = [];
    $statement['transactions'] = [];

    foreach ($accounts as $account) {
        $bill = $this->meterService::getBills($account->account_no);

        // Only include unpaid bills
        if (!empty($bill) && ($bill['isPaid'] ?? 0) == 0) {
            $bill['account_no'] = $account->account_no;
            $statement['transactions'][] = $bill;
        }
    }

    // Determine the current bill
    $statement['current_bill'] = collect($statement['transactions'])
        ->filter(function ($bill) {
            return ($bill['isPaid'] ?? 0) == 0
                && (
                    empty($bill['amount_paid']) ||
                    floatval($bill['amount_paid']) < floatval($bill['amount'])
                );
        })
        ->sortByDesc('due_date')
        ->first();

        if (!empty($statement['current_bill'])) {
            $statement['current_bill'] =
                $this->computeBillPenalty($statement['current_bill']);
        }


    // Compute total for all transactions
    $statement['total'] = !empty($statement['transactions'])
        ? array_sum(array_map(function($bill) {
            $amount = $bill['total'] ?? 0;
            $discount = $bill['discount'] ?? 0;
            $advance = $bill['advances'] ?? 0;

        $penalty = $statement['current_bill']['penalty'] ?? 0;
        $dueDate = isset($data['current_bill']['due_date'])
                        ? \Carbon\Carbon::parse($data['current_bill']['due_date'])
                        : null;

        $today = \Carbon\Carbon::today();

        $applicablePenalty = ($dueDate && $today->gt($dueDate)) ? $penalty : 0;

            return ($amount + $applicablePenalty) - ($discount + $advance);
        }, $statement['transactions']))
        : 0;

    $statement['due_date'] = !empty($statement['transactions'])
        ? collect($statement['transactions'])
            ->pluck('due_date')
            ->filter()
            ->sortDesc()
            ->first()
        : '';

    $statement['measurement'] = env('APP_PRODUCT') == 'novusurge' ? 'kwh' : 'm³';

    $sc_discounts = collect($data['accounts'])->pluck('sc_discount');

    // -----------------------------
    // Generate online payment URL
    // -----------------------------
    $statement['current_bill_qr'] = null;

    if (!empty($statement['current_bill'])) {

        $currentBill = $statement['current_bill'];

        $payload = [
            'reference_no' => $currentBill['reference_no'] ?? '',
            'amount' => $currentBill['amount'] ?? 0,
            'customer' => [
                'name' => $data->name ?? '',
                'account_no' => $currentBill['account_no'] ?? '',
                'address' => $currentBill['address'] ?? '',
            ],
        ];

        // Call PaymentController to create HitPay payment link
        $hitpayData = app(\App\Http\Controllers\PaymentController::class)
            ->createHitpayPaymentRequest($currentBill['reference_no'] ?? '', $payload);

        if ($hitpayData && !empty($hitpayData['url'])) {
            $statement['current_bill_qr'] = $hitpayData['url'];
        } else {
            $statement['current_bill_qr'] = env('NOVUPAY_URL')
                . '/payment/merchants/'
                . ($currentBill['reference_no'] ?? '');
        }
    }

    return view('account-overview.index', compact('my', 'data', 'accounts', 'statement', 'sc_discounts'));
}



    public function getBillColumns()
    {
        // Fetch the first 50 rows from the bill table
        $bills = DB::table('bill')->limit(50)->get();

        return $bills;
    }

            public function bills(Request $request, ?string $reference_no = null)
{
    $userId = Auth::id();

    // View specific bill by reference number
    if ($reference_no) {
    $data = $this->meterService::getBill($reference_no);

    if (!$data || !isset($data['client'])) {
        return redirect()->route('account-overview.index')->with('alert', [
            'status' => 'error',
            'message' => 'Bill Not Found',
        ]);
    }

    // Concessionaire may only view bills for their enrolled accounts
    $billAccountNo = $data['client']['account_no'] ?? $data['current_bill']['reading']['account_no'] ?? null;
    $clientData = $this->clientService::getData($userId);
    $myAccountNos = collect($clientData->accounts ?? [])->pluck('account_no')->toArray();
    if ($billAccountNo && !in_array($billAccountNo, $myAccountNos)) {
        return redirect()->route('account-overview.index')->with('alert', [
            'status' => 'error',
            'message' => 'You do not have access to this bill.',
        ]);
    }

    // Compute penalties
    $data['current_bill'] = $this->computeBillPenalty($data['current_bill']);
    $currentBill = $data['current_bill'];

    // 🧮 Use dynamic penalty computation (from PaymentBreakdownPenalty)
        $amount = (float)($currentBill['total'] ?? 0);
        $amount_afterDue = (float)($currentBill['total'] ?? 0);
        $discount = (float)($currentBill['discount'] ?? 0);
        $currentDay = now()->day;

        $penaltyEntry = \App\Models\PaymentBreakdownPenalty::where('due_from', '<=', $currentDay)
            ->where('due_to', '>=', $currentDay)
            ->first();

        $penalty = $currentBill['penalty'] ?? 0;
        $dueDate = isset($currentBill['due_date'])
            ? \Carbon\Carbon::parse($currentBill['due_date'])
            : null;

        $today = \Carbon\Carbon::today();

        $applicablePenalty = ($dueDate && $today->gt($dueDate)) ? (float) $penalty : 0;

        // ✅ Always ensure defaults
        $assumedPenalty = 0;
        $assumedAmountAfterDue = $amount_afterDue + $applicablePenalty;

        // 🔹 Try to compute based on dynamic penalty config
        if ($penaltyEntry) {
            $penaltyBase = $amount - $discount;

            if ($penaltyEntry->amount_type === 'percentage') {
                $assumedPenalty = $penaltyBase * floatval($penaltyEntry->amount);
            } elseif ($penaltyEntry->amount_type === 'fixed') {
                $assumedPenalty = floatval($penaltyEntry->amount);
            }
        } else {
            // fallback 10%
            $assumedPenalty = $amount * 0.10;
        }

        $assumedAmountAfterDue = $amount - $discount;

        $data['current_bill']['assumed_penalty'] = $applicablePenalty;
        $data['current_bill']['assumed_amount_after_due'] = $assumedAmountAfterDue;

        // 💰 Add service fees
        $hitpay_fee = 20;
        $novupay_fee = 10;
        $additional_service_fee = $hitpay_fee + $novupay_fee;

        $final_amount = $assumedAmountAfterDue + $additional_service_fee;

        // 🧾 Build payment payload
        $paymentPayload = [
            'reference_no' => $reference_no,
            'amount' => $final_amount,
            'customer' => [
                'name' => $data['client']['name'] ?? '',
                'account_no' => $data['client']['account_no'] ?? '',
                'address' => $data['client']['address'] ?? '',
            ],
        ];

    // 🔹 Generate HitPay checkout link (your logic)
    $hitpayData = app(\App\Http\Controllers\PaymentController::class)
        ->createHitpayPaymentRequest($reference_no, $paymentPayload);

    if ($hitpayData && !empty($hitpayData['url'])) {
        $url = $hitpayData['url']; // ✅ HitPay checkout link
    } else {
        $url = env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;
    }

    // $url = route('account-overview.bills.reference_no', ['reference_no' => $reference_no]);
    $qr_code = $this->generateService::qr_code($url, 80);
    $payment_url = $url;
    $isViewBill = true;
    $account_no = null;
    $viewer = 'receipt';

    return view('account-overview.bill', compact('isViewBill', 'data', 'account_no', 'viewer', 'reference_no', 'qr_code', 'payment_url'));
}


    $account_no = $request->query('account_no');
    $view = $request->query('view');

    $clientData = $this->clientService::getData($userId);
    $accounts = $clientData->accounts ?? [];

    $validAccountNos = $accounts->pluck('account_no')->toArray();

    $isAccountNoValid = !empty($account_no) && in_array($account_no, $validAccountNos);
    $isViewValid = in_array($view, ['unpaid', 'paid']);

    if ((!$isAccountNoValid) && !$isViewValid) {
        if ($account_no !== null || $view !== null) {
            return redirect()->route('account-overview.bills');
        }
    }

    $statements = [];
    $isPaid = $view === 'paid';

    foreach ($accounts as $account) {
        $bills = $this->meterService::getBills($account->account_no, true, $isPaid);

        if (!empty($bills)) {
            // Compute penalty for each bill
            $bills = array_map(function ($bill) {
                return $this->computeBillPenalty($bill);
            }, $bills);

            $statements[$account->account_no] = $bills;
        }
    }


    if ($isAccountNoValid && $isViewValid) {
        $data = $statements[$account_no] ?? [];

        if ($request->ajax() && $request->has('account_no') && $request->has('view')) {
            return $this->datatable('bills', $data);
        }

        $viewer = 'bills';
        return view('account-overview.bill', compact('viewer', 'account_no', 'view'));
    }

    if ($request->ajax()) {
        return $this->datatable('account_nos', $accounts);
    }

    $viewer = 'accounts';
    return view('account-overview.bill', compact('viewer'));
}



    public function datatable($type, $query)
    {

        if($type == 'account_nos') {
            return  DataTables::of($query)
                ->addIndexColumn()
                ->editColumn('account_no', function ($row) {
                    return $row['account_no'];
                })
                ->editColumn('meter_no', function ($row) {
                    return $row['meter_serial_no'];
                })
                ->editColumn('address', function ($row) {
                    return $row['address'] ?? 'N/A';
                })
                ->editColumn('property_type', function ($row) {
                    return $row['property_type'] ?? 'N/A';
                })
                ->editColumn('date_connected', function ($row) {
                    return $row['date_connected'] ?? 'N/A';
                })

                ->addColumn('actions', function ($row) {
                    return '<div class="d-flex align-items-center gap-2">
                        <a href="' . e(route('account-overview.bills', [
                            'account_no' => $row['account_no'],
                            'view' => 'unpaid'
                        ])) . '"
                            class="btn btn-primary text-white text-uppercase fw-bold">
                            <i class="bx bx-receipt"></i>
                        </a>
                    </div>';
                })
                ->rawColumns(['status', 'actions'])
                ->make(true);
        }

        if($type == 'bills') {
    return DataTables::of($query)
        ->addIndexColumn()
        ->editColumn('billing_period', function ($row) {
            return ($row['bill_period_from'] && $row['bill_period_to'])
                ? Carbon::parse($row['bill_period_from'])->format('M d, Y') . ' TO ' . Carbon::parse($row['bill_period_to'])->format('M d, Y')
                : 'N/A';
        })
        ->editColumn('bill_date', function ($row) {
            return $row['bill_period_to'] ? Carbon::parse($row['bill_period_to'])->format('M d, Y') : 'N/A';
        })
        ->editColumn('due_date', function ($row) {
            return $row['due_date'] ? Carbon::parse($row['due_date'])->format('M d, Y') : 'N/A';
        })
        ->editColumn('penalty_date', function ($row) {
            return $row['due_date']
                ? Carbon::parse($row['due_date'])->addDay()->format('M d, Y')
                : '—';
        })
        ->editColumn('penalty_amount', function ($row) {
            return isset($row['penalty'])
                ? '₱' . number_format($row['penalty'], 2)
                : '₱0.00';
        })
        ->editColumn('amount_after_due', function ($row) {
            return isset($row['amount_after_due'])
                ? '₱' . number_format($row['amount_after_due'], 2)
                : '₱' . number_format($row['amount'], 2);
        })
        ->editColumn('status', function ($row) {
            return $row['isPaid']
                ? '<div class="alert alert-primary mb-0 py-1 px-2 text-center">Paid</div>'
                : '<div class="alert alert-danger mb-0 py-1 px-2 text-center">Unpaid</div>';
        })
        ->addColumn('actions', function ($row) {
            $reference_no = $row['reference_no'] ?? null;
            if ($reference_no) {
                return '<div class="d-flex align-items-center gap-2">
                    <a href="' . e(route('account-overview.bills.reference_no', $reference_no)) . '"
                        class="btn btn-primary text-white text-uppercase fw-bold"
                        id="show-btn" data-id="' . e($row['id']) . '">
                        <i class="bx bx-receipt"></i>
                    </a>
                </div>';
            }
            return '<span class="text-muted">No Reference</span>';
        })
       ->addColumn('pay', function ($row) {
    $reference_no = is_array($row) ? ($row['reference_no'] ?? null) : ($row->reference_no ?? null);

    if (empty($reference_no)) {
        return '<span class="text-muted">No Reference</span>';
    }

    return '<div class="d-flex align-items-center gap-2">
        <button type="button"
            class="btn btn-success text-white text-uppercase fw-bold pay-now-btn"
            data-reference="' . e($reference_no) . '"
            data-id="' . e($row['id'] ?? '') . '">
            <i class="bx bx-credit-card"></i> Pay Now
        </button>
    </div>';
})

        ->rawColumns(['status', 'actions', 'pay'])
        ->make(true);
}

    }

    public function payOnline(Request $request, string $reference_no)
{
    // Get the current authenticated user's accounts
    $userId = Auth::id();
    $clientData = $this->clientService::getData($userId);
    $accounts = $clientData->accounts ?? [];

    // Check if reference_no belongs to this user's accounts
    $validReference = false;
    foreach ($accounts as $account) {
        $bill = $this->meterService::getBill($reference_no);
        if ($bill && $bill['current_bill']['account_no'] == $account->account_no) {
            $validReference = true;
            break;
        }
    }

    if (!$validReference) {
        return redirect()->back()->with('alert', [
            'status' => 'error',
            'message' => 'Invalid bill reference for your account.'
        ]);
    }

    // Prepare payload for online payment
    $payload = [
        'payor' => $clientData->name ?? 'Customer',
        'email' => $clientData->email ?? 'customer@example.com',
        'account_no' => $bill['current_bill']['account_no'],
        'amount' => $bill['current_bill']['amount'] ?? 0,
    ];

    // Call PaymentController logic
    $paymentController = new \App\Http\Controllers\PaymentController();
    $hitpayData = $paymentController->createHitpayPaymentRequest($reference_no, $payload);

    if (!$hitpayData || empty($hitpayData['url'])) {
        return redirect()->back()->with('alert', [
            'status' => 'error',
            'message' => 'Failed to initiate online payment.'
        ]);
    }

    return redirect($hitpayData['url']);
}


    private function computeBillPenalty(array $bill): array
{
    $amount = (float) ($bill['amount'] ?? 0);
    $penaltyAmount = (float) ($bill['penalty'] ?? 0);

    $dueDate = isset($bill['due_date']) ? Carbon::parse($bill['due_date']) : null;
    $today = Carbon::today();

    $daysOverdue = 0;
    $penaltyDate = null;

    if ($dueDate && $today->gt($dueDate)) {
        $daysOverdue = $dueDate->diffInDays($today);
        $penaltyDate = $dueDate->copy()->addDay();
    }

    $bill['computed_penalty'] = $penaltyAmount;
    $bill['computed_penalty_date'] = $penaltyDate?->format('Y-m-d');
    $bill['computed_amount_after_due'] = $amount + $penaltyAmount;
    $bill['days_overdue'] = $daysOverdue;
    $bill['is_overdue'] = $daysOverdue > 0;

    return $bill;
}


    public function payPartial(Request $request, string $reference_no)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1'
        ]);

        $userId = Auth::id();
        $clientData = $this->clientService::getData($userId);

        $bill = $this->meterService::getBill($reference_no);

        if (!$bill) {
            return back()->with('alert', [
                'status' => 'error',
                'message' => 'Bill not found.'
            ]);
        }

        $partialAmount = (float) $request->amount;

        // Add service charges
        $hitpay_fee = 20;
        $novupay_fee = 10;
        // $additional_service_fee = $hitpay_fee + $novupay_fee;
        $additional_service_fee = 0;
        $finalAmount = $partialAmount + $additional_service_fee;

        $payload = [
            'reference_no' => $reference_no,
            'amount' => $finalAmount,
            'customer' => [
                'name' => $clientData->name ?? '',
                'account_no' => $bill['current_bill']['account_no'] ?? '',
                'address' => $bill['current_bill']['address'] ?? '',
            ],
            'metadata' => [
                'partial_payment' => true,
                'original_amount' => $bill['current_bill']['amount'] ?? 0,
                'partial_amount' => $partialAmount,
            ]
        ];

        $hitpayData = app(\App\Http\Controllers\PaymentController::class)
            ->createHitpayPaymentRequest($reference_no, $payload);

        if (!$hitpayData || empty($hitpayData['url'])) {
            return back()->with('alert', [
                'status' => 'error',
                'message' => 'Failed to initiate partial payment.'
            ]);
        }

        return redirect($hitpayData['url']);
    }


}
