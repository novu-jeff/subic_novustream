<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function verify(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'Invalid verification link.');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('auth.index')
                ->with('success', 'Your email is already verified. You can now log in.');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect()->route('auth.index')
            ->with('success', 'Email verified successfully. You can now log in.');
    }
}
