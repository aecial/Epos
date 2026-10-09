<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The back office is for active managers/admins. LoginRequest already refuses everyone else;
 * this signs out a web session that slipped through anyway - one started before that rule, or
 * an account deactivated or demoted to cashier since. Web group only, so the POS API (Sanctum
 * tokens) is unaffected.
 */
class EnsureBackOfficeUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $refusal = Auth::guard('web')->user()?->backOfficeRefusal();

        if ($refusal === null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['username' => $refusal]);
    }
}
