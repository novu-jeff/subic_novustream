<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Admin;

class LoginController extends Controller
{
    public function index()
    {
        if (Auth::guard('admins')->check()) {
            return redirect('/admin/dashboard');
        }

        if (Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();
            return redirect(app(LoginController::class)->redirectTo($user));
        }

        return view('auth.login');
    }


    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])
                    ->where('isActive', 1)
                    ->first();

        $guard = 'web';

        if (!$user) {
            $user = Admin::where('email', $credentials['email'])->first();
            $guard = 'admins';
        }

        if (!$user || !passwordVerifyAndUpgrade($credentials['password'], $user->password, $user)) {
            return back()->withErrors([
                'email' => 'Invalid credentials or account inactive.'
            ]);
        }

        if ($user instanceof User && !$user->hasVerifiedEmail()) {
            return back()->withErrors([
                'email' => 'Please verify your registered email address before logging in.'
            ]);
        }

        if ($user instanceof User && in_array($user->user_type, ['concessionaire', 'user', 'client'])) {
            if ($user->current_session_id && $user->current_session_id !== session()->getId()) {
                session()->getHandler()->destroy($user->current_session_id);
            }

            $user->current_session_id = session()->getId();
            $user->save();
        }

        Auth::guard($guard)->login($user, $request->has('remember'));

        return redirect()->intended($this->redirectTo($user));
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        $guard = ($user instanceof \App\Models\Admin) ? 'admins' : 'web';

        // Clear session tracking for concessionaires
        if ($user instanceof \App\Models\User && in_array($user->user_type, ['concessionaire', 'user'])) {
            $user->current_session_id = null;
            $user->save();
        }

        Auth::guard($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Redirect to login page (browser does a full page load)
        return redirect()->route('auth.index');
    }


    public function redirectTo($user): string
    {
        return match($user->user_type) {
            'admin', 'cashier', 'superadmin' => '/admin/dashboard',
            'technician' => '/admin/reading',
            'concessionaire', 'user', 'client', null => '/concessionaire/my/overview',
            default => '/login',
        };
    }

}
