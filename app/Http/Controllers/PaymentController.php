<?php

namespace App\Http\Controllers;

use App\Imports\PreviousBillingImport;
use App\Models\Bill;
use App\Services\GenerateService;
use App\Services\MeterService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Yajra\DataTables\Facades\DataTables;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Maatwebsite\Excel\Excel as ExcelFormat;
use App\Models\PaymentBreakdownPenalty;
use App\Models\PartialPayment;

class PaymentController extends Controller
{

    public $meterService;
    public $generateService;

    public function __construct(MeterService $meterService,
        GenerateService $generateService) {
        $this->meterService = $meterService;
        $this->generateService = $generateService;
    }

    public function index(Request $request)
{
    $filter = $request->filter ?? '';

    if (!in_array($filter, ['unpaid', 'paid'], true)) {
        return redirect()->route('payments.index', ['filter' => 'unpaid']);
    }

    $zones = $this->meterService->getZones();
    $zone = $request->zone ?? 'all';

    $entries = $request->entries ?? 10;
    $toSearch = trim($request->search ?? '');
    $date = $request->date ?? $this->meterService->getLatestReadingMonth();

    /**
     * ------------------------------------------------------------
     *  SMART SEARCH PROCESSING (Catherine Abayari → ABAYARI...)
     * ------------------------------------------------------------
     *
     * We convert input like:
     *   "Catherine Abayari"
     *   "Abayari Catherine"
     *   "Catherine A."
     *
     * Into searchable fragments:
     *   catherine
     *   abayari
     *
     * So the service can match: "ABAYARI I, CATHERINE S."
     */
    $searchParts = [];

    if (!empty($toSearch)) {
        // Split into keywords
        $keywords = preg_split('/\s+/', strtolower($toSearch));

        foreach ($keywords as $keyword) {
            if (strlen($keyword) > 0) {
                $searchParts[] = $keyword;
            }
        }
    }

    // Pass to service as array OR as original string
    // Service can filter using LIKE per keyword
    $collection = collect(
        $this->meterService::getPayments(
            $filter,
            $zone,
            $date,
            $toSearch
        )
    )->flatten(2);

    // Pagination (unchanged)
    $currentPage = LengthAwarePaginator::resolveCurrentPage();
    $currentItems = $collection->slice(($currentPage - 1) * $entries, $entries)->values();

    $data = new LengthAwarePaginator(
        $currentItems,
        $collection->count(),
        $entries,
        $currentPage,
        ['path' => $request->url(), 'query' => $request->query()]
    );

    return view(
        'payments.index',
        compact('data', 'entries', 'filter', 'zones', 'zone', 'date', 'toSearch')
    );
}


    public function upload(Request $request)
    {
        if ($request->getMethod() !== 'POST') {
            return view('payments.upload');
        }

        if (!$request->hasFile('file')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No file uploaded.',
            ]);
        }

        $file = $request->file('file');

        if (
            !$file->isValid() ||
            $file->getClientOriginalExtension() !== 'xlsx' ||
            $file->getMimeType() !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only Excel (.xlsx) files are allowed.',
            ]);
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheetNames = $spreadsheet->getSheetNames();

        $expectedHeaders = [
            'reference_no', 'account_no', 'billing_from', 'billing_to',
            'previous_reading', 'present_reading', 'consumption', 'penalty',
            'unpaid', 'arrears', 'current_bill', 'amount_paid',
            'date_paid', 'due_date', 'payor_name', 'payment_reference_no',
        ];

        $allMessages = [];
        $importedSheets = [];

        $headingData = (new HeadingRowImport(2))->toArray($file);

        $normalizeHeader = function ($header) {
            $h = (string)$header;
            $h = trim($h);
            $h = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $h));
            $h = preg_replace('/_+/', '_', $h);
            $h = trim($h, '_');
            return $h;
        };

        foreach ($sheetNames as $index => $sheetName) {
            $rawHeadersRow = $headingData[$index][0] ?? [];

            $normalizedHeaders = [];
            foreach ($rawHeadersRow as $h) {
                $n = $normalizeHeader($h);
                if (!empty($n)) {
                    $normalizedHeaders[] = $n;
                }
            }

            if (empty($normalizedHeaders) && !empty($headingData[$index])) {
                foreach ($headingData[$index] as $possibleRow) {
                    if (!empty($possibleRow) && is_array($possibleRow)) {
                        foreach ($possibleRow as $h) {
                            $n = $normalizeHeader($h);
                            if (!empty($n)) {
                                $normalizedHeaders[] = $n;
                            }
                        }
                        if (!empty($normalizedHeaders)) break;
                    }
                }
            }

            $missing = array_values(array_diff($expectedHeaders, $normalizedHeaders));

            if (!empty($missing)) {
                $allMessages[] = [
                    'sheet' => $sheetName,
                    'status' => 'error',
                    'message' => 'Missing headers in sheet.',
                    'missing_headers' => $missing,
                ];
                continue;
            }

            try {
                $importInstance = new PreviousBillingImport($sheetName);

                Excel::import(new class($importInstance, $sheetName) implements \Maatwebsite\Excel\Concerns\WithMultipleSheets {
                    private $importInstance;
                    private $sheetName;

                    public function __construct($importInstance, $sheetName)
                    {
                        $this->importInstance = $importInstance;
                        $this->sheetName = $sheetName;
                    }

                    public function sheets(): array
                    {
                        return [$this->sheetName => $this->importInstance];
                    }
                }, $file);

                $importedSheets[] = $sheetName;

                $failures = $importInstance->failures();
                $failureErrors = [];

                if ($failures->isNotEmpty()) {
                    foreach ($failures as $failure) {
                        $row = $failure->row();
                        foreach ($failure->errors() as $error) {
                            $failureErrors[] = "Row [$row]: $error";
                        }
                    }
                }

                $skippedRows = $importInstance->getSkippedRows();
                $rowCount = $importInstance->getRowCounter();
                $totalImported = max($rowCount - 2 - count($failureErrors) - count($skippedRows), 0);

                if (!empty($failureErrors) || !empty($skippedRows)) {
                    $message = [];
                    if (!empty($failureErrors)) {
                        $message[] = count($failureErrors) . ' skipped due to validation';
                    }
                    if (!empty($skippedRows)) {
                        $message[] = count($skippedRows) . ' skipped due to logic checks';
                    }

                    $allMessages[] = [
                        'sheet' => $sheetName,
                        'status' => 'warning',
                        'message' => "Total of <b>(".number_format($totalImported, 0).")</b> records partially imported. <br>" . implode(', ', $message),
                        'errors' => array_merge($failureErrors, $skippedRows),
                    ];
                } else {
                    $allMessages[] = [
                        'sheet' => $sheetName,
                        'status' => 'success',
                        'message' => "Total of <b>(".number_format($totalImported, 0).")</b> records imported successfully.",
                    ];
                }

            } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                $failures = $e->failures();
                $messages = [];

                foreach ($failures as $failure) {
                    $row = $failure->row();
                    foreach ($failure->errors() as $error) {
                        $messages[] = "Row [$row]: $error";
                    }
                }

                $allMessages[] = [
                    'sheet' => $sheetName,
                    'status' => 'error',
                    'message' => 'Validation errors found during import.',
                    'errors' => $messages,
                ];
            } catch (\Exception $e) {
                $allMessages[] = [
                    'sheet' => $sheetName,
                    'status' => 'error',
                    'message' => 'An error occurred: ' . $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'completed',
            'imported' => $importedSheets,
            'messages' => $allMessages,
        ]);
    }


    public function pay(Request $request, string $reference_no)
{
    if ($request->getMethod() == 'POST') {
        $payload = $request->all();

        switch ($payload['payment_type']) {
            case 'cash':
                return $this->processCashPayment($reference_no, $payload);
            case 'online':
                return $this->processOnlinePayment($reference_no, $payload);
        }
    }

    $data = $this->meterService::getBill($reference_no);

    if (isset($data['status']) && $data['status'] === 'error') {
        return redirect()->back()->with('alert', [
            'status' => 'error',
            'message' => $data['message']
        ]);
    }

    // ⚙️ Validate reading
    $currentBill = $data['current_bill'] ?? null;
    if (!$currentBill || !isset($currentBill['reading_id'])) {
        return redirect()->back()->with('alert', [
            'status' => 'error',
            'message' => 'No reading found for this bill.'
        ]);
    }

    $reading = \App\Models\Reading::find($currentBill['reading_id']);
    if (!$reading) {
        return redirect()->back()->with('alert', [
            'status' => 'error',
            'message' => 'Reading not found.'
        ]);
    }

    // 🔹 Deduct previous partial payment (important new part)
    if (!empty($currentBill['isPartial']) && $currentBill['isPartial'] == 1) {
        $partialAmount = floatval($currentBill['partial_payment'] ?? 0);
        $remaining = floatval($currentBill['total'] ?? 0);

        if ($remaining < 0) $remaining = 0;

        // Update total due shown to user
        $data['current_bill']['total'] = $remaining;
    }

    // 🧾 Compute arrears stack
    $arrearsStack = collect();
    $previousUnpaid = (float)($currentBill['previous_unpaid'] ?? 0);
    if ($previousUnpaid > 0) {
        $arrearsMonth = \Carbon\Carbon::parse($currentBill['bill_period_to'])
            ->subMonth()
            ->format('F');
        $arrearsStack[$arrearsMonth] = $previousUnpaid;
    }

    // 🧮 Use dynamic penalty computation
    $amount = (float)($data['current_bill']['total'] ?? 0);
    $amount_afterDue = (float)($currentBill['amount_after_due'] ?? 0);
    $discount = (float)($currentBill['discount'] ?? 0);
    $tax = (float)($currentBill['tax'] ?? 0);
    $currentDay = now()->day;

    $penaltyEntry = \App\Models\PaymentBreakdownPenalty::where('due_from', '<=', $currentDay)
        ->where('due_to', '>=', $currentDay)
        ->first();

    $assumedPenalty = 0;
    $assumedAmountAfterDue = $amount_afterDue;

    if ($penaltyEntry) {
        $penaltyBase = $amount - $discount;
        if ($penaltyEntry->amount_type === 'percentage') {
            $assumedPenalty = $penaltyBase * floatval($penaltyEntry->amount);
        } elseif ($penaltyEntry->amount_type === 'fixed') {
            $assumedPenalty = floatval($penaltyEntry->amount);
        }
    } else {
        $assumedPenalty = $amount * 0.10; // fallback
    }

    $assumedAmountAfterDue = $amount + $previousUnpaid + $tax - $discount;

    $data['current_bill']['assumed_penalty'] = $assumedPenalty;
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
    \Log::error('HitPay Data: ' . json_encode($hitpayData));

    $url = $hitpayData['url'] ?? env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;
    $qr_code = $this->generateService::qr_code($url, 80);

    $url = $hitpayData['url']
        ?? env('NOVUPAY_URL') . '/payment-expired/' . $reference_no;

    $qr_code = $this->generateService::qr_code($url, 80);


    return view('payments.pay', compact('data', 'reference_no', 'qr_code', 'arrearsStack'));
}


    private function calculateTotalDue(array $currentBillData, ?array $payload = null, float $fullArrears = 0): array
    {
        // 1. amount -> total
        $currentBill = (float) ($currentBillData['total'] ?? 0);
        $arrears = $fullArrears ?: (float) ($currentBillData['previous_unpaid'] ?? 0);
        $penalty = (float) ($currentBillData['penalty'] ?? 0);

        $discount = 0;
        if (isset($payload['discount'])) {
            $discount = (float) $payload['discount'];
        } elseif (isset($currentBillData['discount'])) {
            if (is_array($currentBillData['discount'])) {
                $discount = collect($currentBillData['discount'])->sum('amount');
            } else {
                $discount = (float) $currentBillData['discount'];
            }
        }

        $advancePayment = (float) ($currentBillData['advances'] ?? 0);
        $partialPayment = (float) ($currentBillData['partial_payment'] ?? 0);
        $dueDatePenalty = 0;
        $dueDate = $currentBillData['due_date'] ?? null;
        $tax = (float) ($currentBillData['tax'] ?? 0);

        $dueDate = isset($currentBillData['due_date'])
            ? \Carbon\Carbon::parse(trim($currentBillData['due_date']))
                ->timezone('Asia/Manila')
                ->startOfDay()
            : null;

        $today = \Carbon\Carbon::today('Asia/Manila');

        if ($dueDate && $today->gt($dueDate)) {
            $dueDatePenalty = (float) $penalty;
        }

        // $userDiscount = $currentBillData['reading']['account_no'];
        //     if (in_array($userDiscount, ['061-12-064897'])) {
        //         $temporaryDiscount = 0.25;
        //     } else {
        //         $temporaryDiscount = null;
        //     }

        // 2. removed arrears
        $totalDue = $currentBill - $discount + $dueDatePenalty - $advancePayment - $partialPayment;
        // $temporaryDiscounts = $totalDue * $temporaryDiscount;
        // $totalDue = $totalDue - $temporaryDiscounts;
        $totalDue = max(0, round($totalDue, 2));

        return [
            'total_due' => $totalDue,
            'breakdown' => [
                'current_bill' => $currentBill,
                'arrears' => $arrears,
                //'previous_penalty' => $prevPenalty, // optional
                'discount' => $discount,
                'advance_payment' => $advancePayment,
                'due_date_penalty' => $dueDatePenalty,
                'tax' => $tax,
            ],
        ];
    }



        private function getBill(string $reference_no, $payload = null, bool $strictAmount = false)
    {
        $data = $this->meterService::getBill($reference_no);

        if (!$data || !isset($data['current_bill'])) {
            return ['error' => 'Bill not found'];
        }

        $readingId = $data['current_bill']['reading_id'] ?? null;

        if (!$readingId) {
            return ['error' => 'No reading found for this bill.'];
        }

        $reading = \App\Models\Reading::find($readingId);
        if (!$reading) {
            return ['error' => 'Reading not found.'];
        }

        $accountNo = $reading->account_no;

        $unpaidBills = Bill::whereHas('reading', function($query) use ($accountNo) {
            $query->where('account_no', $accountNo);
        })
        ->where('isPaid', 0)
        ->orderBy('bill_period_from')
        ->get();

        $fullArrears = $unpaidBills->sum(fn($b) => $b->previous_unpaid);

        $totalDueResult = $this->calculateTotalDue($data['current_bill'], $payload);
        $totalDue = $totalDueResult['total_due'];
        $breakdown = $totalDueResult['breakdown'];

        if ($strictAmount && $payload) {
            $validator = Validator::make($payload, [
                'payment_amount' => 'required|numeric|gte:' . $totalDue,
            ], [
                'payment_amount.gte' => 'Cash payment is insufficient. Total due is PHP ' . number_format($totalDue, 2)
            ]);

            if ($validator->fails()) {
                return ['error' => $validator->errors()->first()];
            }
        }

        $data['current_bill']['assumed_amount_after_due'] = $totalDue;
        $data['current_bill']['breakdown'] = $breakdown;
        $data['current_bill']['previous_unpaid'] = $fullArrears;

        return [
            'data' => $data,
            'total_due' => $totalDue,
            'breakdown' => $breakdown,
        ];
    }


    public function processCashPayment(string $reference_no, array $payload)
    {
        $result = $this->getBill($reference_no, $payload, false);

        if (isset($result['error'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $result['error']
            ]);
        }

        $data = $result['data'];
        $now = Carbon::now()->format('Y-m-d H:i:s');

        $amountPay = (float) $result['total_due'];
        $paymentAmount = (float) $payload['payment_amount'];
        $change = $paymentAmount - $amountPay;

        $forAdvancePayment = isset($payload['for_advances']) && $payload['for_advances'];
        $isPartialPayment = isset($payload['partial_payment']) && $payload['partial_payment'];
        $saveChange = ($change > 0 && $forAdvancePayment);

        if (!$isPartialPayment && $paymentAmount < $amountPay) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => "Cash payment is insufficient. Total due is PHP " . number_format($amountPay, 2)
            ]);
        }

        $currentBill = Bill::find($data['current_bill']['id']);

        if ($currentBill) {
            $previousPartial = floatval($currentBill->partial_payment ?? 0);
            $totalAmountPaid = floatval($currentBill->amount_paid ?? 0);
            $totalBillDue = (float) $result['total_due'];

            if ($isPartialPayment) {
                $currentBill->update([
                    'partial_payment' => $previousPartial + $paymentAmount,
                    'amount_paid' => $totalAmountPaid + $paymentAmount,
                    'isPaid' => 0,
                    'isPartial' => 1,
                    'change' => 0,
                    'payor_name' => $payload['payor'],
                    'date_paid' => $now,
                    'isChangeForAdvancePayment' => 0,
                    'payment_method' => 'cash',
                ]);

                $remainingBalance = $totalBillDue - ($previousPartial + $paymentAmount);

                PartialPayment::create([
                    'reading_id' => $currentBill->reading_id,
                    'partial_payment' => $paymentAmount,
                    'remaining_balance' => max($remainingBalance, 0),
                ]);
            } else {
                $currentBill->update([
                    'amount_paid' => $totalAmountPaid + $paymentAmount,
                    'partial_payment' => null,
                    'isPaid' => 1,
                    'isPartial' => 0,
                    'change' => $change > 0 ? $change : 0,
                    'payor_name' => $payload['payor'],
                    'date_paid' => $now,
                    'isChangeForAdvancePayment' => $saveChange,
                    'payment_method' => 'cash',
                ]);

                $account_no = optional($currentBill->reading)->account_no;

                if ($account_no) {
                    $previousBills = Bill::whereHas('reading', function ($q) use ($account_no) {
                            $q->where('account_no', $account_no);
                        })
                        ->where('isPaid', 0)
                        ->where('id', '<>', $currentBill->id)
                        ->orderBy('id', 'asc')
                        ->get();

                    $remainingPayment = $paymentAmount;

                    foreach ($previousBills as $bill) {
                        $remainingBalance = (float) $bill->amount - (float) $bill->amount_paid;

                        if ($remainingBalance <= 0) {
                            continue;
                        }

                        if ($remainingPayment >= $remainingBalance) {
                            $bill->update([
                                'isPaid' => 1,
                                'isPartial' => 0,
                                'amount_paid' => $bill->amount,
                                'partial_payment' => null,
                                'change' => 0,
                                'payor_name' => $payload['payor'],
                                'date_paid' => $now,
                                'paid_by_reference_no' => $reference_no,
                            ]);

                            $remainingPayment -= $remainingBalance;
                        } else {
                            $bill->update([
                                'isPartial' => 1,
                                'amount_paid' => $bill->amount_paid + $remainingPayment,
                                'payor_name' => $payload['payor'],
                                'partial_payment' => null,
                                'date_paid' => $now,
                                'paid_by_reference_no' => $reference_no,
                            ]);
                            break;
                        }
                    }
                }
            }
        }

        return redirect()->back()->with('alert', [
            'status' => 'success',
            'message' => $isPartialPayment
                ? 'Partial payment has been recorded.'
                : 'Bill has been fully paid and any arrears have been cleared.'
        ]);
    }


    public function processOnlinePaymentOld(string $reference_no, array $payload)
    {
        $result = $this->getBill($reference_no, $payload, false);

        if (isset($result['error'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => $result['error']
            ]);
        }

        $url = env('NOVUPAY_URL') . '/payment/merchants/' . $reference_no;

        return redirect()->route('payments.pay', ['reference_no' => $reference_no])->with('alert', [
            'status' => 'success',
            'payment_request' => true,
            'redirect' => $url,
        ]);
    }

    public function processOnlinePayment(string $reference_no, array $payload)
    {
        // 🔹 Call your existing helper method
        $hitpayData = $this->createHitpayPaymentRequest($reference_no, $payload);

        // 🔹 Handle error from HitPay
        if (!$hitpayData || empty($hitpayData['url'])) {
            return redirect()->back()->with('alert', [
                'status' => 'error',
                'message' => 'Failed to generate HitPay payment request.',
            ]);
        }

        // 🔹 Update Bill record (optional, if you want to track the HitPay ID)
        $bill = \App\Models\Bill::where('reference_no', $reference_no)->first();
        if ($bill) {
            $bill->update([
                'payment_method' => 'online',
                'initiated_at' => now(),
                'hitpay_payment_id' => $hitpayData['id'] ?? null,
            ]);
        }

        // 🔹 Redirect with success message + payment link
        return redirect()->back()->with('alert', [
            'status' => 'success',
            'payment_request' => true,
            'redirect' => $hitpayData['url'],
        ]);
    }



    public function createHitpayPaymentRequest(string $reference_no, array $payload): ?array
    {
        try {
            $result = $this->getBill($reference_no, $payload, false);

            if (isset($result['error'])) {
                \Log::error('HitPay error: ' . $result['error']);
                return null;
            }

            $billData = $result['data']['current_bill'] ?? null;
            // dd($billData);
            if (!$billData) {
                \Log::error('Missing bill data for HitPay', ['reference_no' => $reference_no]);
                return null;
            }
            $days_before_due = 15;
            if (!empty($billData['due_date'])) {
                $due = Carbon::parse($billData['due_date'])->endOfDay();
            } else {
                $due = Carbon::now()->addDays($days_before_due)->endOfDay();
            }

            // HitPay requires expiry_date to be in the future – clamp if bill is already overdue
            $now = Carbon::now();
            if ($due->lessThanOrEqualTo($now)) {
                $due = $now->copy()->addHours(1);
            }

            $due_date = $due->format('Y-m-d H:i:s');


            // dd($billData);
            $rateCode = $result['data']['client']['rate_code'] ?? null;

            // ✅ Use payload amount if partial payment
            if (!empty($payload['metadata']['partial_payment'])) {
                $amount = (float) $payload['metadata']['partial_amount'];
            } else {
                $amount = (float) $billData['total'];

                $discount = !empty($billData['discount'][0]['amount'])
                    ? (float) $billData['discount'][0]['amount']
                    : 0;

                $amount = $amount - $discount;
            }

            // dd($amount, $discount);

            $payor = $result['data']['client']['name'] ?? ($payload['payor'] ?? 'Sta. Rita Customer');
            $email = $result['data']['client']['email'] ?? ($payload['email'] ?? 'srwdsystem2023@gmail.com');
            $account_no = $result['data']['client']['account_no'] ?? ($payload['account_no'] ?? '000000');

            // ⚙️ Default payment methods (include QRPH if allowed)
            // $paymentMethods = ["gcash","gcash_qr","qrph_netbank","upay_bayd","upay_ecpy","upay_instapay","upay_online","upay_pchc","upay_plwn","xpay_card"];
            $qrph_fee  = $amount <= 2000 ? 20 : ($amount * 0.01);
            $gcash_fee = $amount * 0.023;

            $novupay_fee = 25;
            if(Str::contains($rateCode, '12')){
                $novupay_fee = 10;
            }

            // Select fee based on amount
            if ($amount < 800) {
                $selected_fee = $gcash_fee;
                $paymentMethods = ['gcash'];
            } else {
                $selected_fee = $qrph_fee;
                $paymentMethods = ['qrph_netbank', 'upay_online'];
            }

            $hitpay_fee = round($selected_fee, 1);
            $additional_service_fee = $hitpay_fee + $novupay_fee;

            if (!empty($payload['metadata']['partial_payment'])) {
                $final_amount = (float) $payload['amount'];
            } else {
                $final_amount = round($amount + $additional_service_fee, 2);
            }

            // 🧾 Purpose formatting
            if (!empty($payload['metadata']['partial_payment'])) {
                $purpose = "Partial Payment: PHP {$amount}\nConvenience Fee Included\nAccount #: {$account_no}";
            } else {
                $purpose = "Amount Due: PHP {$amount}\nConvenience Fee: PHP {$additional_service_fee}\nAccount #: {$account_no}";
            }

            $hitpayPayload = [
                'amount' => $final_amount,
                'currency' => 'PHP',
                'email' => $email,
                'purpose' => $purpose,
                'reference_number' => $reference_no,
                'redirect_url' => env('HITPAY_REDIRECT_URL'),
                'webhook' => env('HITPAY_WEBHOOK_URL'),
                'send_email' => true,
                'send_sms' => true,
                'name' => $payor,
                'add_admin_fee' => true,
                'admin_fee' => '15.00',
                'payment_methods' => array_values($paymentMethods),
                'expiry_date' => $due_date,
            ];

            // dd($hitpayPayload);

            $response = \Http::withHeaders([
                'X-BUSINESS-API-KEY' => env('HITPAY_API_KEY'),
            ])->post(env('HITPAY_API_URL') . '/payment-requests', $hitpayPayload);

            // dd($response->body());
            if ($response->failed()) {
                \Log::error('HitPay API request failed', ['body' => $response->body()]);
                return null;
            }

            $data = $response->json();
            return [
                'id' => $data['id'] ?? null,
                'url' => $data['url'] ?? null,
            ];
        } catch (\Exception $e) {
            \Log::error('createHitpayPaymentRequest exception: ' . $e->getMessage());
            return null;
        }
    }




    public function handleRedirect(Request $request)
    {
        \Log::info('HitPay redirect received in PaymentController', $request->all());

        $status = strtolower($request->query('status'));
        $hitpay_reference = $request->query('reference');

        if (!$hitpay_reference || !$status) {
            abort(404, 'Invalid payment reference.');
        }

        // ✅ Step 1: Verify payment details with HitPay
        $response = \Http::withHeaders([
            'X-BUSINESS-API-KEY' => env('HITPAY_API_KEY'),
        ])->get(env('HITPAY_API_URL') . "/payment-requests/{$hitpay_reference}");

        if ($response->failed()) {
            \Log::error('HitPay verify API failed', ['reference' => $hitpay_reference]);
            return view('payments.status', [
                'payload' => [
                    'title' => 'Payment Verification Failed',
                    'message' => 'Unable to verify payment from HitPay.',
                    'reference_no' => $hitpay_reference,
                    'status' => 'failed',
                    'amount' => '0.00',
                    'date_paid' => now()->format('M d, Y h:i A'),
                ]
            ]);
        }

        $payment = $response->json();
        \Log::info('HitPay verified payment', $payment);

        $status = strtolower($payment['status'] ?? $status);
        $reference_number = $payment['reference_number'] ?? null;
        $amount = (float) ($payment['amount'] ?? 0);
        $payor = $payment['name'] ?? 'Unknown';

        // ✅ Step 2: Find bill
        $bill = \App\Models\Bill::where('hitpay_reference', $hitpay_reference)
            ->orWhere('reference_no', $reference_number)
            ->first();

        $days_before_due = 15;
        $due_date = !empty($bill['due_date'])
            ? Carbon::parse($bill['due_date'])->format('M d, Y H:i:s')
            : Carbon::now()->addDays($days_before_due)->format('M d, Y H:i:s');


        if (!$bill) {
            \Log::warning("HitPay verify: Bill not found for {$hitpay_reference}");
            return view('payments.status', [
                'payload' => [
                    'title' => 'Bill Not Found',
                    'message' => 'Payment verified, but no matching bill found.',
                    'reference_no' => $hitpay_reference,
                    'status' => 'error',
                    'amount' => $amount,
                    'date_paid' => now()->format('M d, Y h:i A'),
                ]
            ]);
        }

        if (in_array($status, ['completed', 'succeeded', 'success'])) {

        // 🔎 Detect partial payment from metadata
        $metadata = $payment['metadata'] ?? [];
        $isPartial = !empty($metadata['partial_payment']);

        if ($isPartial) {

            $partialAmount = (float) ($metadata['partial_amount'] ?? 0);

            $bill->update([
                'isPartial' => 1,
                'partial_payment' => $partialAmount,
                'isPaid' => 0,
                'amount_paid' => null,
                'payor_name' => $payor,
                'date_paid' => now(),
                'payment_method' => 'online',
            ]);

            // Optional: Save record in partial_payments table
            \App\Models\PartialPayment::create([
                'reading_id' => $bill->reading_id,
                'partial_payment' => $partialAmount,
                'remaining_balance' => max(($bill->amount ?? 0) - $partialAmount, 0),
            ]);

        } else {

            // FULL PAYMENT
            $bill->update([
                'isPaid' => 1,
                'isPartial' => 0,
                'amount_paid' => $amount,
                'partial_payment' => null,
                'payor_name' => $payor,
                'date_paid' => now(),
                'payment_method' => 'online',
            ]);
        }

            return view('payments.status', [
                'payload' => [
                    'title' => 'Payment Successful',
                    'message' => 'Your payment was verified and marked as paid.',
                    'reference_no' => $bill->reference_no,
                    'status' => $status,
                    'amount' => number_format($amount, 2),
                    'date_paid' => now()->format('M d, Y H:i:s'),
                    'payment_id' => $payment['id'] ?? uniqid('PAY-'),
                ]
            ]);
        }

        // ❌ Step 4: Failed or canceled
        return view('payments.status', [
            'payload' => [
                'title' => 'Payment Failed',
                'message' => 'Your payment was not completed or was canceled.',
                'reference_no' => $bill->reference_no,
                'status' => $status,
                'amount' => number_format($amount, 2),
                'date_paid' => now()->format('M d, Y H:i:s'),
                'payment_id' => $payment['id'] ?? uniqid('PAY-'),
                'expires_at' => $due_date ?? null,
            ]
        ]);
    }

    public function datatable($query) {
        return DataTables::of($query)
            ->addIndexColumn()
            ->editColumn('account_no', function($row) {
                return $row->reading->account_no ?? 'N/A';
            })
            ->editColumn('billing_period', function ($row) {
                return ($row->bill_period_from && $row->bill_period_to)
                    ? Carbon::parse($row->bill_period_from)->format('M d, Y') . ' TO ' . Carbon::parse($row->bill_period_to)->format('M d, Y')
                    : 'N/A';
            })
            ->editColumn('bill_date', function ($row) {
                return !empty($row->bill_period_to)
                    ? Carbon::parse($row->bill_period_to)->format('M d, Y')
                    : 'N/A';
            })
            ->editColumn('amount', function ($row) {
                return '₱' . number_format((float)($row->amount ?? 0), 2);
            })
            ->editColumn('due_date', function ($row) {
                return !empty($row->due_date)
                    ? Carbon::parse($row->due_date)->format('M d, Y')
                    : 'N/A';
            })
            ->editColumn('status', function ($row) {
                return $row->isPaid
                    ? '<div class="alert alert-primary mb-0 py-1 px-2 text-center">Paid</div>'
                    : '<div class="alert alert-danger mb-0 py-1 px-2 text-center">Unpaid</div>';
            })
            ->addColumn('actions', function ($row) {
                if(!$row->isPaid) {
                    return '
                    <div class="d-flex align-items-center gap-2">
                        <a href="' . route('payments.pay', ['reference_no' => $row->reference_no]) . '"
                            class="btn btn-primary text-white text-uppercase fw-bold">
                            <i class="bx bx-credit-card-alt" ></i>
                        </a>
                    </div>';
                } else {
                    return
                    '<div class="d-flex align-items-center gap-2">
                        <a target="_blank" href="' . route('reading.show', $row->reference_no) . '"
                            class="btn btn-primary text-white text-uppercase fw-bold"
                            id="show-btn" data-id="' . e($row->id) . '">
                            <i class="bx bx-receipt"></i>
                        </a>
                    </div>';
                }
            })
            ->rawColumns(['status', 'actions'])
            ->make(true);
    }

}
