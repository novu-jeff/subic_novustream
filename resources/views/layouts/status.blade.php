<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Status</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Bootstrap (optional) --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- Boxicons --}}
    <script src="https://unpkg.com/boxicons@2.1.4/dist/boxicons.js"></script>

    {{-- Custom styles --}}
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }
        .outer-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .inner-wrapper {
            width: 100%;
            max-width: 480px;
        }
        .wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 20px;
        }
        .top {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .icon {
            background-color: #32667e;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .icon.success {
            background-color: #28a745;
        }
        .icon.error {
            background-color: #32667e;
        }
        .header {
            text-align: center;
            margin-top: 10px;
        }
        .header h3 {
            margin-top: 10px;
            font-weight: 700;
        }
        .details .items {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .bottom {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
        .bottom button {
            flex: 1;
            border: none;
            border-radius: 8px;
            padding: 10px;
            cursor: pointer;
            font-weight: bold;
        }
        .bottom button:first-child {
            background-color: #198754;
            color: white;
        }
        .bottom button:last-child {
            background-color: #e9ecef;
        }

        /* Print styles - hide buttons and help section when printing */
        @media print {
            body {
                background-color: white;
            }
            .outer-wrapper {
                min-height: auto;
            }
            .wrapper {
                box-shadow: none;
                page-break-inside: avoid;
            }
            /* Hide buttons when printing */
            .bottom {
                display: none !important;
            }
            /* Hide help center section when printing */
            .others {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    @yield('base')

</body>
</html>
