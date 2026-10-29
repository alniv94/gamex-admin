<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ($user->status !== 'Active' || ! $user->profile
            || $request->session()->get('admin_version') !== $user->profile->session_version)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Your session has ended. Please sign in again.'], 401);
            }

            return redirect('/login');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
