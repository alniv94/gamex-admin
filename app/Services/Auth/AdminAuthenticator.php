<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthenticator
{
    public function __construct(private AdminSms $sms) {}

    /** @return array<string, mixed> */
    public function requestCode(Request $request, string $mobile): array
    {
        $key = hash('sha256', $mobile);
        foreach ([
            ['admin:send:ip:'.$request->ip(), 10, 1800],
            ['admin:send:phone:'.$key, 3, 1800],
            ['admin:send:cooldown:'.$key, 1, (int) config('admin.resend_interval')],
        ] as [$limit, $maximum, $seconds]) {
            if (RateLimiter::tooManyAttempts($limit, $maximum)) {
                abort(429, 'Too many requests. Please try again later.');
            }
            RateLimiter::hit($limit, $seconds);
        }
        $challenge = (string) Str::uuid();
        $user = User::where('mobile_number', $mobile)->where('status', 'Active')->whereHas('profile')->first();
        if ($user) {
            $lock = Cache::lock('admin:send:lock:'.$key, 30);
            if (! $lock->get()) {
                abort(429, 'Please wait before requesting another code.');
            }
            try {
                $code = (string) random_int(100000, 999999);
                DB::table('admin_otp_challenges')->insert([
                    'id' => $challenge, 'user_id' => $user->id,
                    'session_fingerprint' => $this->fingerprint($request),
                    'code_hash' => Hash::make($code), 'attempts' => 0,
                    'expires_at' => now()->addSeconds((int) config('admin.otp_lifetime')),
                    'created_at' => now(),
                ]);
                try {
                    $this->sms->send($mobile, $code);
                } catch (\Throwable $exception) {
                    DB::table('admin_otp_challenges')->where('id', $challenge)->delete();
                    throw $exception;
                }
                DB::transaction(function () use ($user, $challenge): void {
                    DB::table('admin_profiles')->where('user_id', $user->id)->lockForUpdate()->first();
                    DB::table('admin_otp_challenges')->where('user_id', $user->id)
                        ->where('id', '!=', $challenge)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                    DB::table('admin_otp_challenges')->where('id', $challenge)->update(['delivered_at' => now()]);
                });
            } finally {
                $lock->release();
            }
        }

        return [
            'challenge_id' => $challenge,
            'message' => 'If this number belongs to an active administrator, a sign-in code has been sent.',
            'delivery' => config('admin.sms.driver') === 'capture' && app()->environment(['local', 'testing']) ? 'captured' : 'sms',
            'expires_in' => (int) config('admin.otp_lifetime'),
            'resend_after' => (int) config('admin.resend_interval'),
        ];
    }

    public function login(Request $request, string $challengeId, string $code): User
    {
        $limit = 'admin:verify:ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limit, 30)) {
            abort(429, 'Too many attempts. Please try again later.');
        }
        RateLimiter::hit($limit, 300);

        $result = DB::transaction(function () use ($request, $challengeId, $code): ?array {
            $challenge = DB::table('admin_otp_challenges')->where('id', $challengeId)->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || ! $challenge->delivered_at
                || now()->greaterThanOrEqualTo($challenge->expires_at)
                || $challenge->attempts >= (int) config('admin.otp_max_attempts')
                || ! hash_equals($challenge->session_fingerprint, $this->fingerprint($request))) {
                return null;
            }
            DB::table('admin_otp_challenges')->where('id', $challengeId)->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                return null;
            }
            $user = User::whereKey($challenge->user_id)->lockForUpdate()->first();
            $profile = $user?->profile()->lockForUpdate()->first();
            if (! $user || $user->status !== 'Active' || ! $profile) {
                return null;
            }
            DB::table('admin_otp_challenges')->where('user_id', $user->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $profile->increment('session_version');
            DB::table('admin_auth_logs')->insert([
                'user_id' => $user->id, 'action' => 'login', 'ip_address' => $request->ip(), 'created_at' => now(),
            ]);

            return [$user, $profile->session_version];
        });
        if (! $result) {
            throw ValidationException::withMessages(['otp' => 'The code is invalid or expired. Please request a new code.']);
        }
        [$user, $version] = $result;
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('admin_version', $version);

        return $user;
    }

    private function fingerprint(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }
}
