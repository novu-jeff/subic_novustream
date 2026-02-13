@php
    $exception = $exception ?? new \Exception('Unknown error');
    $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
    $is4xx = $statusCode >= 400 && $statusCode < 500;
    $loginUrl = url('/login');
    $logoutUrl = url('/logout');
    $primaryUrl = auth()->check() ? url('/admin/dashboard') : $loginUrl;
    $backUrl = url()->previous() !== url()->current() ? url()->previous() : $loginUrl;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', config('app.name', 'Error'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --error-bg: #f8fafc;
            --card-bg: #ffffff;
            --code-4xx: #dc2626;
            --code-5xx: #ea580c;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --border: #e2e8f0;
            --primary-btn: #0f172a;
            --primary-btn-hover: #1e293b;
            --secondary-btn-bg: #f1f5f9;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
            background: var(--error-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        .error-container {
            max-width: 28rem;
            width: 100%;
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 2.5rem;
            box-shadow: var(--shadow);
            text-align: center;
        }
        .error-icon {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1.5rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-icon-4xx { background: #fef2f2; color: var(--code-4xx); }
        .error-icon-5xx { background: #fff7ed; color: var(--code-5xx); }
        .error-code {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }
        .error-code-4xx { color: var(--code-4xx); }
        .error-code-5xx { color: var(--code-5xx); }
        .error-label {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
        }
        .error-message {
            font-size: 0.9375rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .error-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 600;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: background-color 0.2s, color 0.2s;
            font-family: inherit;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: var(--primary-btn);
            color: white;
        }
        .btn-primary:hover { background: var(--primary-btn-hover); }
        .btn-secondary {
            background: var(--secondary-btn-bg);
            color: var(--text-primary);
        }
        .btn-secondary:hover { background: #e2e8f0; }
        @media (min-width: 480px) {
            .error-actions { flex-direction: row; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon {{ $is4xx ? 'error-icon-4xx' : 'error-icon-5xx' }}">
            @if($statusCode === 401)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            @elseif($statusCode === 403)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            @elseif($statusCode === 404)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            @elseif($statusCode === 419)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            @elseif($statusCode === 429)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            @elseif($statusCode === 503)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
            @elseif($statusCode >= 500)
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/></svg>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            @endif
        </div>
        <div class="error-code {{ $is4xx ? 'error-code-4xx' : 'error-code-5xx' }}">@yield('code', $statusCode)</div>
        @php
            $labels = [401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Page Not Found', 419 => 'Page Expired', 429 => 'Too Many Requests', 500 => 'Server Error', 503 => 'Service Unavailable'];
        @endphp
        <div class="error-label">@yield('label', __($labels[$statusCode] ?? 'Error'))</div>
        <div class="error-message">@yield('message', __('An error occurred. Please try again or contact support if the problem persists.'))</div>
        <div class="error-actions">
            @if(auth()->check())
                <a href="{{ $primaryUrl }}" class="btn btn-primary">{{ __('Go to Dashboard') }}</a>
                @if($statusCode >= 500)
                    <a href="{{ $logoutUrl }}" class="btn btn-secondary">{{ __('Return to Login') }}</a>
                @else
                    <a href="{{ $backUrl }}" class="btn btn-secondary">← {{ __('Go Back') }}</a>
                @endif
            @else
                <a href="{{ $loginUrl }}" class="btn btn-primary">{{ __('Go to Login') }}</a>
                <a href="{{ $backUrl }}" class="btn btn-secondary">← {{ __('Go Back') }}</a>
            @endif
        </div>
    </div>
</body>
</html>
