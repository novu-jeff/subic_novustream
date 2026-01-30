<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Print Bill {{ $reference_no }}</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    @vite(['resources/js/app.js'])

    <style>
        .print-controls {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin: 40px 0;
        }

        .thermal-receipt {
            width: 80mm;
            margin: 0 auto;
            padding: 4mm 5mm;
            font-family: Arial, sans-serif;
            font-size: 9px;
            color: #000;
            background: #fff;
            text-align: center;
        }

        .thermal-receipt h4 {
            font-size: 12px;
            font-weight: 700;
            margin: 0 0 2mm 0;
            text-transform: uppercase;
        }

        .sub-title {
            font-size: 9px;
            margin-bottom: 2mm;
        }

        .date {
            font-size: 9px;
            margin-bottom: 2mm;
        }

        .amount-label {
            font-size: 9px;
            text-transform: uppercase;
            margin-top: 2mm;
        }

        .amount {
            font-size: 18px;
            font-weight: 800;
            margin: 1mm 0;
        }

        .property {
            font-size: 9px;
            margin-bottom: 2mm;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 2mm 0;
        }

        .ref-label {
            font-size: 9px;
        }

        .ref-no {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-top: 1mm;
        }

        .footer {
            font-size: 8px;
            margin-top: 2mm;
            font-style: italic;
        }

        /* ===============================
           PRINT SETTINGS (CRITICAL)
           =============================== */
        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }

            body {
                margin: 0;
                padding: 0;
                background: #fff;
            }

            .print-controls {
                display: none !important;
            }

            .thermal-receipt {
                width: 80mm;
                padding: 4mm;
                font-size: 8.5px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    {{-- ACTION BUTTONS --}}
    <div class="print-controls">

        @php
            $previousUrl = url()->previous();
            $currentUrl = url()->current();
            $fallbackUrl = Auth::user()->user_type == 'client'
                ? route('account-overview.show')
                : route('reading.index');
            $backUrl = ($previousUrl !== $currentUrl) ? $previousUrl : $fallbackUrl;
        @endphp

        <a href="{{ $backUrl }}"
           style="border:1px solid #32667e; padding:10px 24px; text-transform:uppercase; text-decoration:none; color:#32667e; border-radius:4px;">
            Go Back
        </a>

        <button class="download-js"
                data-target="#bill"
                data-filename="{{ $data['current_bill']['reference_no'] }}"
                style="background:#32667e; color:#fff; padding:10px 24px; border:none; border-radius:4px;">
            Download
        </button>

        <button onclick="window.print()"
                style="background:#32667e; color:#fff; padding:10px 24px; border:none; border-radius:4px;">
            Print
        </button>
    </div>

    {{-- RECEIPT --}}
    <div id="bill">
        <div class="thermal-receipt">

            <h4>Official Receipt</h4>
            <div class="sub-title">Walk-In Payment | {{ \Carbon\Carbon::now('Asia/Manila')->format('F d, Y') }}</div>

            <div class="amount-label">Amount Paid</div>
            <div class="amount">
                ₱ {{ number_format($walkInFee, 2) }}
            </div>

            <div class="property">
                {{ $propertyTypeName }}
            </div>

            <div class="divider"></div>

            <div class="ref-label">Reference No. {{ $reference_no }}</div>

            <div class="footer">
                This receipt acknowledges payment received
            </div>

        </div>
    </div>

</div>

</body>
</html>
