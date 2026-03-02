<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class CheckDefaultPassword
{
    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        // Check if user is logged in and password is default (safe for non-bcrypt stored values)
        if ($user && passwordVerifyAndUpgrade('password', $user->password, null)) {
            session()->flash('using_default_password', true);
        }

        return $next($request);
    }
}
