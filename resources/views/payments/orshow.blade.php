<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Print Bill {{ $reference_no }}</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet">

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js"></script>

  @vite(['resources/js/app.js'])

  <style>
    /* Page fixed size for 4" x 8.5" (10.16cm x 21.59cm) */
    .receipt-sheet {
      width: 10.16cm;
      height: 21.59cm;
      box-sizing: border-box;
      background: white;
      font-family: "Times New Roman", serif;
      color: #000;
      position: relative;
      margin: 0 auto;
    }

    /* Outer thin page border when viewing (optional) */
    .page-wrap { padding: 6px; }

    /* print rules: show only the visible printable block */
    @media print {
      html, body { height: 100%; margin: 0; padding: 0; }
      body * { visibility: hidden !important; }
      .printable, .printable * { visibility: visible !important; }
      .printable { position: absolute; top: 0; left: 0; margin: 0; }
    }

    /* On-screen */
    .controls { margin: 20px auto; text-align: center; }
    .small-note { font-size: 8px; color: #333; }

    /* Header container */
    .or-header {
      border: 1px solid #000;      /* single solid border as requested */
      padding: 6px;
      box-sizing: border-box;
      display: flex;
      gap: 8px;
      align-items: flex-start;
      height: 3.5cm;               /* fixed header height */
    }

    .or-logo {
      width: 18%;
      min-width: 1.7cm;
      text-align: center;
    }
    .or-logo .crest-box {
      width: 100px;
      height: 120px;
      border: 1px solid #000;
      background: #f8f8f8;
      margin: 0 auto;
      padding-top: 6px;
      box-sizing: border-box;
    }
    .or-center {
      width: 44%;
      text-align: center;
      font-weight: 700;
      font-size: 12px;
      line-height: 1.1;
      display:flex;
      align-items:center;
      justify-content:center;
      flex-direction:column;
      gap:2px;
    }
    .or-right {
      width: 38%;
      text-align: right;
      box-sizing:border-box;
    }
    .or-right .or-no {
      display:inline-block;
      border:1px solid #000;
      padding:6px 8px;
      font-weight:700;
    }

    /* small helper text */
    .muted-small { font-size: 8px; color: #666; }

    /* Section rows */
    .row-label {
      width: 22%;
      font-weight:700;
      font-size:10px;
    }
    .underline-field {
      border-bottom: 1px solid #000;
      padding: 6px 8px;
      font-size:11px;
    }

    /* table style */
    .or-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10px;
      margin-top: 6px;
    }
    .or-table thead th {
      border:1px solid #000;
      padding:6px;
      vertical-align: middle;
    }
    .or-table tbody td {
      border-left:1px solid #000;
      border-right:1px solid #000;
      padding:6px;
      vertical-align: middle;
    }
    .or-table tbody tr:last-child td { border-bottom:1px solid #000; }
    /* make right-most column show right-aligned numbers */
    .amount-col { text-align: right; }

    /* Amount in words box */
    .amount-words {
      border:1px solid #000;
      padding:8px;
      font-style: italic;
      text-align:center;
      margin-top:8px;
      font-size:10px;
      min-height: 2.0cm;
      box-sizing: border-box;
    }

    /* Payment & drawee row */
    .payment-row { margin-top:8px; font-size:10px; }
    .drawee-box { border-bottom: 1px solid #000; height:18px; }

    /* signature area */
    .signature-area { margin-top:18px; display:flex; justify-content:flex-end; }
    .sig-box { width:60%; text-align:center; }

    /* overlay preview background (on-screen only) */
    .overlay-preview {
      background-repeat: no-repeat;
      background-position: center top;
      background-size: contain;
      opacity: 0.92;
    }
    @media print {
      .overlay-preview { background: transparent !important; opacity: 1 !important; }
    }
  </style>
</head>

<body>
@extends('layouts.app')
@section('content')

@php
    // --- Normalize and compute values ---
    $cb = $data['current_bill'] ?? [];
    $amount = (float) ($cb['amount'] ?? 0);

    $arrears = $cb['unpaid_amount'] ?? 0;
    if (is_array($arrears)) {
        $arrears = collect($arrears)->sum();
    } elseif (is_string($arrears)) {
        $arrears = (float) $arrears;
    }

    $discount = $cb['discount'] ?? 0;
    if (is_array($discount)) {
        if (isset($discount[0]) && is_array($discount[0]) && isset($discount[0]['amount'])) {
            $discount = collect($discount)->sum('amount');
        } else {
            $discount = collect($discount)->sum();
        }
    } elseif (is_string($discount)) {
        $discount = (float) $discount;
    }

    $assumed_penalty = (float) ($cb['assumed_penalty'] ?? 0);
    $total = round($amount + $arrears + $assumed_penalty - $discount, 2);

    // Use helper that falls back when the PHP intl extension is not available
    $amount_in_words = \App\Helper\NumberHelper::convertToWords($total);

    $receipt_no = $receipt_no ?? ('436' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT));
    $cashier = auth()->user()->name ?? ($cb['collecting_officer'] ?? 'NA');
    $bill_month = !empty($cb['bill_period_from']) ? \Carbon\Carbon::parse($cb['bill_period_from'])->format('M Y') : '';
    $account_no = $cb['account_no'] ?? $cb['account_number'] ?? '011-12-011110';
    $datePaid = $cb['date_paid'] ?? \Carbon\Carbon::now()->format('Y-m-d');

    // prepare preview background (if user placed scanned image in public/images/receipt-scan.png)
    $previewBg = null;
    $previewPath = public_path('images/receipt-scan.png');
    if (file_exists($previewPath)) {
        $dataUri = base64_encode(file_get_contents($previewPath));
        $mime = mime_content_type($previewPath) ?: 'image/png';
        $previewBg = "data:{$mime};base64,{$dataUri}";
    }
@endphp

<div class="print-controls" style="display: grid; grid-template-columns: repeat(2, auto); gap: 12px; margin: 50px auto; width: fit-content;">
        @php
            $previousUrl = url()->previous();
            $currentUrl = url()->current();
            $fallbackUrl = Auth::user()->user_type == 'client' ? route('account-overview.show') : route('reading.index');
            $backUrl = ($previousUrl !== $currentUrl) ? $previousUrl : $fallbackUrl;
            $logoPath = public_path(config('app.client_logo'));
            $base64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        @endphp

        <a href="{{$backUrl}}"
            id="goBackButton"
            style="border: 1px solid #32667e; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; text-decoration: none; color: #32667e; background-color: transparent; border-radius: 5px;">
            <i style="font-size: 15px;" class='bx bx-left-arrow-alt'></i> Go Back
        </a>

        @if(!$isReRead['status'])
            <button
                class="download-js"
                data-target="#bill"
                data-filename="{{$data['current_bill']['reference_no']}}"
                style="background-color: #32667e; color: white; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border: none; border-radius: 5px; cursor: pointer;">
                <i style="font-size: 15px;" class='bx bxs-download'></i> Download
            </button>
            @if(!$data['current_bill']['isPaid'] == true)
                <button
                    class="reRead"
                    style="text-align: center; background-color: #32667e; color: white; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border: none; border-radius: 5px; cursor: pointer;">
                    <i style="font-size: 15px;" class='bx bxs-printer'></i> Re Read
                </button>
            @endif
        @endif
    </div>
<div class="container controls">
  <div class="d-flex justify-content-center gap-3">
    <button class="btn btn-primary print-btn" data-target="#receipt-full">🧾 Print Full Receipt</button>
    <button class="btn btn-secondary print-btn" data-target="#receipt-overlay">🖋 Print Overlay Only</button>
  </div>
  <div class="mt-2 text-muted small-note">Printer: set to Actual Size / 100% and No margins</div>
</div>

<!-- =========================
     Option 1 - FULL RECEIPT (exact layout)
     ========================= -->
<div id="receipt-full" class="printable page-wrap" style="display:none;">
  <div class="receipt-sheet receipt-border">

    <!-- HEADER BOX (single solid border) -->
    <div class="or-header">
      <!-- LOGO on left -->
      <div class="or-logo">
        <div class="crest-box">
          <img src="{{ asset('images/rnp.png') }}" alt="logo" style="width:72px; display:block; margin:0 auto;">
        </div>
        <div class="muted-small mt-1">{{ \Carbon\Carbon::now()->format('H:i:s') }}</div>
      </div>

      <!-- CENTER Text -->
      <div class="or-center">
        <div style="font-size:11px; font-weight:700; text-align:center; letter-spacing:0.5px;">
          OFFICIAL RECEIPT
        </div>
        <div style="font-size:10px; line-height:1; font-weight:700; text-align:center;">
          OF THE REPUBLIC OF THE PHILIPPINES
        </div>
      </div>

      <!-- RIGHT: OR No. and Date -->
      <div class="or-right">
        <div class="or-no">No. <span style="font-size:13px; letter-spacing:1px;">{{ $receipt_no }}</span> <span style="font-weight:normal;">T</span></div>
        <div class="muted-small mt-1">{{ $receipt_no }}</div>
        <div style="border-top:1px solid #000; padding-top:6px; margin-top:6px; font-size:9px;">
          Date: <span>{{ \Carbon\Carbon::parse($datePaid)->format('F d, Y') }}</span>
        </div>
      </div>
    </div>

    <!-- GAP 0.4cm between header and Agency -->
    <div style="height:0.4cm;"></div>

    <!-- AGENCY -->
    <div style="display:flex; gap:8px; align-items:center;">
      <div class="row-label">Agency</div>
      <div class="underline-field">SANTA RITA WATER DISTRICT</div>
    </div>

    <!-- PAYOR -->
    <div style="display:flex; gap:8px; align-items:center; margin-top:6px;">
      <div class="row-label">Payor</div>
      <div class="underline-field text-uppercase">
        {{ $data['client']['name'] ?? 'N/A' }} {{ !empty($data['client']['account_no']) ? ' | '.$data['client']['account_no'] : '' }}
      </div>
    </div>

    <!-- Account No. -->
    <div style="display:flex; gap:8px; align-items:center; margin-top:6px;">
      <div class="row-label">Account No.</div>
      <div class="underline-field">{{ $account_no }}</div>
    </div>

    <!-- 3-column table -->
    <div style="margin-top:10px;">
      <table class="or-table">
        <thead>
          <tr>
            <th style="width:55%;">Nature of Collection</th>
            <th style="width:20%; text-align:center;">Account Code</th>
            <th style="width:25%; text-align:right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>WB {{ $bill_month }}</td>
            <td></td>
            <td class="amount-col">₱ {{ number_format($amount,2) }}</td>
          </tr>

          @if($arrears > 0)
            <tr>
              <td>Arrears</td>
              <td></td>
              <td class="amount-col">₱ {{ number_format($arrears,2) }}</td>
            </tr>
          @endif

          @if($assumed_penalty > 0)
            <tr>
              <td>Penalty</td>
              <td></td>
              <td class="amount-col">₱ {{ number_format($assumed_penalty,2) }}</td>
            </tr>
          @endif

          @php
            $filled = 1 + ($arrears > 0 ? 1 : 0) + ($assumed_penalty > 0 ? 1 : 0);
            $rowsNeeded = 6 - $filled;
          @endphp
          @for($i=0;$i<$rowsNeeded;$i++)
            <tr>
              <td>&nbsp;</td>
              <td></td>
              <td></td>
            </tr>
          @endfor

          <tr>
            <td>Less: Senior Discount</td>
            <td></td>
            <td class="amount-col">₱ {{ number_format($discount,2) }}</td>
          </tr>

          <tr style="font-weight:700;">
            <td>TOTAL</td>
            <td></td>
            <td class="amount-col">₱ {{ number_format($total,2) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Amount in words -->
    <div class="amount-words">
      {{ $amount_in_words }}
    </div>

    <!-- Payment and Drawee -->
    <div class="payment-row">
      <div style="display:flex; gap:12px; align-items:center;">
        <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" disabled> Cash</label>
        <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" disabled> Check</label>
        <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" disabled> Money Order</label>
      </div>

      <div style="display:flex; gap:10px; margin-top:8px;">
        <div style="flex:1;">
          <div style="font-size:9px;">Drawee Bank</div>
          <div class="drawee-box"></div>
        </div>
        <div style="flex:1;">
          <div style="font-size:9px;">Number</div>
          <div class="drawee-box"></div>
        </div>
        <div style="flex:1;">
          <div style="font-size:9px;">Date</div>
          <div class="drawee-box"></div>
        </div>
      </div>
    </div>

    <div style="margin-top:10px; font-size:10px;">Received the amount stated above.</div>

    <!-- signature -->
    <div class="signature-area">
      <div class="sig-box">
        <div style="border-top:1px solid #000; font-weight:700;">{{ strtoupper($cashier) }}</div>
        <div style="font-size:9px;">Collecting Officer</div>
      </div>
    </div>

    <div style="border-top:1px solid #000; margin-top:10px; padding-top:6px; font-size:9px;">
      NOTE: Write the number and date of this receipt on the back of check or money order received.
    </div>

  </div> <!-- .receipt-sheet -->
</div> <!-- #receipt-full -->

<!-- =========================
     Option 2 - OVERLAY ONLY
     (absolute positioned fields to print on pre-printed form)
     ========================= -->
<div id="receipt-overlay" class="printable receipt-sheet overlay-preview" style="display:none;
     @if($previewBg) background-image: url('{{ $previewBg }}'); @endif">

  {{-- NOTE: positions are in cm and intended to match the full receipt above.
             If you need to calibrate, tweak the top/left/right values by +/- 0.05cm. --}}

  {{-- OR Number (top-right) --}}
  <div style="position:absolute; top:0.9cm; right:0.7cm; font-weight:700; font-size:13px;">
    {{ $receipt_no }}
  </div>

  {{-- Date (below OR no.) --}}
  <div style="position:absolute; top:1.6cm; right:0.7cm; font-size:9px;">
    {{ \Carbon\Carbon::parse($datePaid)->format('F d, Y') }}
  </div>

  {{-- Agency --}}
  <div style="position:absolute; top:3.9cm; left:1.2cm; font-size:11px;">
    SANTA RITA WATER DISTRICT
  </div>

  {{-- Payor --}}
  <div style="position:absolute; top:4.4cm; left:1.2cm; right:1.2cm; font-size:11px; text-transform:uppercase;">
    {{ $data['client']['name'] ?? 'N/A' }} {{ !empty($data['client']['account_no']) ? ' | '.$data['client']['account_no'] : '' }}
  </div>

  {{-- Account No --}}
  <div style="position:absolute; top:4.9cm; left:1.2cm; font-size:11px;">
    {{ $account_no }}
  </div>

  {{-- Table: WB (Nature) main --}}
  <div style="position:absolute; top:7.0cm; left:0.7cm; font-size:10px;">
    WB {{ $bill_month }}
  </div>
  <div style="position:absolute; top:7.0cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">
    ₱ {{ number_format($amount,2) }}
  </div>

  {{-- Arrears --}}
  @if($arrears > 0)
    <div style="position:absolute; top:7.6cm; left:0.7cm; font-size:10px;">Arrears</div>
    <div style="position:absolute; top:7.6cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">₱ {{ number_format($arrears,2) }}</div>
  @endif

  {{-- Penalty --}}
  @if($assumed_penalty > 0)
    <div style="position:absolute; top:8.2cm; left:0.7cm; font-size:10px;">Penalty</div>
    <div style="position:absolute; top:8.2cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">₱ {{ number_format($assumed_penalty,2) }}</div>
  @endif

  {{-- Discount --}}
  <div style="position:absolute; top:10.1cm; left:0.7cm; font-size:10px;">Less: Senior Discount</div>
  <div style="position:absolute; top:10.1cm; right:0.8cm; width:3.0cm; font-size:10px; text-align:right;">₱ {{ number_format($discount,2) }}</div>

  {{-- Total --}}
  <div style="position:absolute; top:10.6cm; left:0.7cm; font-size:11px; font-weight:700;">TOTAL</div>
  <div style="position:absolute; top:10.6cm; right:0.8cm; width:3.0cm; font-size:11px; font-weight:700; text-align:right;">₱ {{ number_format($total,2) }}</div>

  {{-- Amount in words --}}
  <div style="position:absolute; top:12.5cm; left:0.9cm; right:0.9cm; font-size:10px; text-align:center; font-style:italic;">
    {{ $amount_in_words }}
  </div>

  {{-- Collecting Officer signature --}}
  <div style="position:absolute; bottom:2.0cm; right:1.6cm; text-align:center; width:5.0cm; font-size:10px;">
    <div style="border-top:1px solid #000; font-weight:700;">{{ strtoupper($cashier) }}</div>
    <div style="font-size:9px;">Collecting Officer</div>
  </div>

</div> <!-- #receipt-overlay -->

<script>
  $(function () {
    // show full receipt in the browser by default for preview
    $('#receipt-full').show();

    // Print button logic toggles which printable to show and triggers print
    $(document).on('click', '.print-btn', function (e) {
      e.preventDefault();
      const target = $(this).data('target'); // "#receipt-full" or "#receipt-overlay"
      $('#receipt-full, #receipt-overlay').hide();
      $(target).show();
      // slight delay for rendering changes to take effect before print dialog
      setTimeout(() => window.print(), 180);
    });
  });
        $(function () {
            @if (session('alert'))
                setTimeout(() => {
                    let alertData = @json(session('alert'));
                    alert(alertData.status, alertData.message);
                }, 100);
            @endif

            const reference_no = '{{$reference_no}}';

            if (window.opener && window.opener !== window) {
                $('#goBackButton').on('click', function (e) {
                    e.preventDefault();
                    window.close();
                });
            }

            let selectedBillId = null;

            $(document).on('click', '.reRead', function() {
                selectedBillId = $(this).data('bill-id');
                $('#reReadModal').modal('show');
            });

            $('#confirmReRead').on('click', function() {
                $('#reReadModal').modal('hide');
                const redirect = `{{ route('reading.index') }}?re-read=true&reference_no=${encodeURIComponent(reference_no)}`;
                console.log(redirect);
                setTimeout(() => {
                    window.location.href = redirect;
                }, 1000);
            });
        });
</script>

@endsection
</body>
</html>
