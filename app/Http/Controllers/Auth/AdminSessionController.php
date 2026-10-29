<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AdminAuthenticator;
use App\Services\Auth\MobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()?->summary(), 'csrf_token' => csrf_token(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function requestCode(Request $request, AdminAuthenticator $auth): JsonResponse
    {
        $data = $request->validate(['mobile_number' => ['required', 'string', 'max:32']]);
        $mobile = MobileNumber::normalize($data['mobile_number']);
        if (! $mobile) {
            throw ValidationException::withMessages(['mobile_number' => 'Enter a valid Philippine mobile number.']);
        }

        return response()->json($auth->requestCode($request, $mobile));
    }

    public function store(Request $request, AdminAuthenticator $auth): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'], 'otp' => ['required', 'string', 'regex:/\\A[0-9]{6}\\z/'],
        ]);
        $user = $auth->login($request, $data['challenge_id'], $data['otp']);

        return response()->json(['user' => $user->summary(), 'redirect' => '/dashboard']);
    }

    public function destroy(Request $request): JsonResponse
    {
        DB::table('admin_auth_logs')->insert([
            'user_id' => $request->user()->id, 'action' => 'logout',
            'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'You have been signed out.', 'redirect' => '/login']);
    }
}
