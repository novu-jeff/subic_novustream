<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Reading;
use App\Models\UserAccounts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountEnrollmentController extends Controller
{
    /**
     * Show the enroll account form (Meralco-style: enter account number).
     */
    public function index()
    {
        $user = Auth::user();
        $accounts = $user->accounts ?? collect();

        return view('account-enrollment.index', compact('accounts'));
    }

    /**
     * Look up account by number: validate it exists and has enough billing history.
     * Returns the last 3 billing months (labels only) for verification.
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'account_no' => ['required', 'string', 'max:20'],
        ]);

        $accountNo = trim($request->account_no);
        $userId = Auth::id();

        // Check if already enrolled to current user
        $existing = UserAccounts::where('account_no', $accountNo)->first();
        if ($existing && (int) $existing->user_id === (int) $userId) {
            return response()->json([
                'status' => 'already_enrolled',
                'message' => 'This account is already linked to your profile.',
            ], 200);
        }

        // Get last 2 bills (by bill_period_to) for this account - via readings
        $lastBills = Bill::query()
            ->join('readings', 'bill.reading_id', '=', 'readings.id')
            ->where('readings.account_no', $accountNo)
            ->where('readings.isReRead', false)
            ->select(
                'readings.id as reading_id',
                'readings.consumption',
                'bill.bill_period_from',
                'bill.bill_period_to'
            )
            ->orderByDesc('bill.bill_period_to')
            ->limit(2)
            ->get();

        if ($lastBills->count() < 2) {
            return response()->json([
                'status' => 'insufficient_history',
                'message' => 'This account does not have enough billing history (at least 2 months required) for verification.',
            ], 422);
        }

        $months = $lastBills->map(function ($row) {
            $periodTo = $row->bill_period_to ?? $row->bill_period_from;
            return [
                'label' => Carbon::parse($periodTo)->format('F Y'),
                'key'   => Carbon::parse($periodTo)->format('Y-m'),
            ];
        })->values();

        return response()->json([
            'status' => 'ok',
            'account_no' => $accountNo,
            'months' => $months,
            'message' => 'Enter the consumption (cubic meters) for the last 2 billing periods to verify.',
        ]);
    }

    /**
     * Verify consumption and enroll the account.
     */
    public function verifyAndEnroll(Request $request)
    {
        $request->validate([
            'account_no' => ['required', 'string', 'max:20'],
            'consumption_1' => ['required', 'numeric', 'min:0'],
            'consumption_2' => ['required', 'numeric', 'min:0'],
        ]);

        $accountNo = trim($request->account_no);
        $userId = Auth::id();

        $userInputs = [
            (int) $request->consumption_1,
            (int) $request->consumption_2,
        ];

        $lastBills = Bill::query()
            ->join('readings', 'bill.reading_id', '=', 'readings.id')
            ->where('readings.account_no', $accountNo)
            ->where('readings.isReRead', false)
            ->select('readings.consumption', 'bill.bill_period_to')
            ->orderByDesc('bill.bill_period_to')
            ->limit(2)
            ->get();

        if ($lastBills->count() < 2) {
            return back()->with('alert', [
                'status' => 'error',
                'message' => 'Insufficient billing history for this account.',
            ]);
        }

        $actualConsumptions = $lastBills->pluck('consumption')->map(fn ($c) => (int) $c)->values()->toArray();

        foreach ($userInputs as $i => $input) {
            if ($input !== ($actualConsumptions[$i] ?? -1)) {
                return back()->with('alert', [
                    'status' => 'error',
                    'message' => 'Verification failed. The consumption values do not match our records. Please check your bill and try again.',
                ]);
            }
        }

        $existing = UserAccounts::where('account_no', $accountNo)->first();

        if ($existing) {
            if ((int) $existing->user_id === (int) $userId) {
                return redirect()->route('account-overview.index')->with('alert', [
                    'status' => 'info',
                    'message' => 'This account is already linked to your profile.',
                ]);
            }
            // Transfer: anyone can claim account if they pass verification (even if linked to another customer)
            $existing->update(['user_id' => $userId]);
            return redirect()->route('account-overview.index')->with('alert', [
                'status' => 'success',
                'message' => 'Account ' . $accountNo . ' has been successfully enrolled to your profile.',
            ]);
        }

        // Create new concessioner_account from readings data
        $latestReading = Reading::where('account_no', $accountNo)
            ->where('isReRead', false)
            ->latest()
            ->first();

        $zone = $latestReading->zone ?? substr($accountNo, 0, 3);

        UserAccounts::create([
            'user_id'        => $userId,
            'zone'           => $zone,
            'account_no'     => $accountNo,
            'address'        => 'To be updated',
            'property_type'  => null,
            'rate_code'      => 0,
            'status'         => 'Active',
            'sc_no'          => '-',
            'meter_serial_no'=> null,
            'date_connected' => now()->format('Y-m-d'),
            'sequence_no'    => '0',
        ]);

        return redirect()->route('account-overview.index')->with('alert', [
            'status' => 'success',
            'message' => 'Account ' . $accountNo . ' has been successfully enrolled to your profile.',
        ]);
    }
}
