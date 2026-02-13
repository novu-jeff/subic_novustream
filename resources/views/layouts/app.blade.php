<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="dns-prefetch" href="//fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=Nunito" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.datatables.net/2.2.2/css/dataTables.bootstrap5.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lightgallery-bundle.min.css">

    <!-- Scripts -->
    @vite(['resources/sass/app.scss', 'resources/sass/dashboard.scss', 'resources/js/app.js'])
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#004aad">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">

</head>
<body>
    @if(auth()->user() && auth()->user()->user_type === 'technician')
        <div id="connection-status"
            style="display:flex;justify-content:center;align-items:center;gap:8px;
                    background:#004aad;color:white;text-align:center;
                    padding:6px 10px;font-weight:600;position:sticky;top:0;z-index:9999;">
            <span id="status-dot"
                style="width:10px;height:10px;border-radius:50%;background:#4caf50;
                        display:inline-block;transition:background 0.3s ease;"></span>
            <span id="status-text">Online</span>
        </div>
    @endif
    <script>
    window.addEventListener('load', () => {
    const dot = document.getElementById('status-dot');
    const text = document.getElementById('status-text');
    const bar = document.getElementById('connection-status');
    if (!dot || !text || !bar) return; // Only technicians have the connection bar

    function updateStatus() {
        if (navigator.onLine) {
        dot.style.background = '#4caf50'; // green
        text.textContent = 'Online';
        bar.style.background = '#004aad';
        } else {
        dot.style.background = '#ff9800'; // orange
        text.textContent = 'Offline - Readings will sync later';
        bar.style.background = '#ff9800';
        }
    }

    window.addEventListener('online', updateStatus);
    window.addEventListener('offline', updateStatus);
    updateStatus();
    });
    </script>
    <div id="app">
        <main>
            @include('layouts.navbar')
            @yield('content')
        </main>
    </div>
</body>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.datatables.net/2.2.2/js/dataTables.js"></script>
    <script src="https://cdn.datatables.net/2.2.2/js/dataTables.bootstrap5.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/lightgallery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/thumbnail/lg-thumbnail.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/zoom/lg-zoom.min.js"></script>
    @yield('script')
</html>
