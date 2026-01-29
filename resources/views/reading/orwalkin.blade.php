<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Print Bill {{$reference_no}}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq" crossorigin="anonymous"></script>
    @vite(['resources/js/app.js'])
</head>
<body>
<div class="container">

    {{-- ACTION BUTTONS --}}
    <div class="print-controls"
        style="display:flex; gap:12px; justify-content:center; margin:40px 0;">

        @php
            $previousUrl = url()->previous();
            $currentUrl = url()->current();
            $fallbackUrl = Auth::user()->user_type == 'client' ? route('account-overview.show') : route('reading.index');
            $backUrl = ($previousUrl !== $currentUrl) ? $previousUrl : $fallbackUrl;
        @endphp
        <a href="{{$backUrl}}"
            id="goBackButton"
            style="border: 1px solid #32667e; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; text-decoration: none; color: #32667e; background-color: transparent; border-radius: 5px;">
            <i style="font-size: 15px;" class='bx bx-left-arrow-alt'></i> Go Back
        </a>

       <button
            class="download-js"
            data-target="#bill"
            data-filename="{{$data['current_bill']['reference_no']}}"
            style="background-color: #32667e; color: white; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border: none; border-radius: 5px; cursor: pointer;">
            <i style="font-size: 15px;" class='bx bxs-download'></i> Download
        </button>

        <button
            class="print-js"
            style="text-align: center; background-color: #32667e; color: white; padding: 12px 40px; text-align:center; text-transform: uppercase; display: flex; align-items: center; gap: 8px; border: none; border-radius: 5px; cursor: pointer;">
            <i style="font-size: 15px;" class='bx bxs-printer'></i> Print Bill
        </button>
    </div>

    {{-- RECEIPT --}}
    <div id="bill">
    <div style="
        max-width:360px;
        margin:0 auto;
        padding:32px 28px;
        border:1px solid #ddd;
        border-radius:10px;
        text-align:center;
        font-family:'Segoe UI', Arial, sans-serif;
        color:#222;
        background:#fff;
    ">

        {{-- HEADER --}}
        <div style="margin-bottom:24px;">
            <h4 style="
                margin:0;
                font-weight:700;
                letter-spacing:1px;
                text-transform:uppercase;
            ">
                Official Receipt
            </h4>
            <div style="
                font-size:12px;
                color:#777;
            ">
                Walk-In Payment
            </div>
        </div>

        {{-- DATE --}}
        <div style="font-size:12px; color:#555; margin-bottom:10px;">
            {{ \Carbon\Carbon::now('Asia/Manila')->format('F d, Y') }}
        </div>

        {{-- AMOUNT --}}
        <div style="margin:28px 0;">
            <div style="
                font-size:13px;
                letter-spacing:0.5px;
                text-transform:uppercase;
                color:#666;
            ">
                Amount Paid
            </div>

            <div style="font-size:32px; font-weight:800; margin-top:2px;">
                ₱ {{ number_format($walkInFee, 2) }}
            </div>

            <div style="
                font-size:11px;
                color:#777;
                margin-top:4px;
            ">
                {{ $propertyType }}
            </div>

        </div>

        {{-- DIVIDER --}}
        <div style="
            border-top:1px dashed #ccc;
            margin:8px 0;
        "></div>

        {{-- REFERENCE --}}
        <div style="font-size:11px; color:#666;">
            Reference No.
        </div>

        <div style="
            font-size:13px;
            font-weight:600;
            letter-spacing:1px;
            margin-top:4px;
        ">
            {{ $reference_no }}
        </div>

        {{-- FOOTER --}}
        <div style="
            margin-top:10px;
            font-size:10px;
            color:#777;
            font-style:italic;
        ">
            This receipt acknowledges payment received
        </div>
    </div>
</div>

</div>

{{-- PRINT STYLE --}}
<style>
@media print {
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #fff;
    }
    .print-controls {
        display: none !important;
    }
}
</style>
</body>
