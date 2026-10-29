<?php

namespace App\Services\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminSms
{
    public function send(string $mobile, string $code): void
    {
        $message = 'Your GameXSports admin sign-in code is '.$code.'. It expires in 5 minutes. Do not share this code.';
        if (config('admin.sms.driver') === 'capture' && app()->environment(['local', 'testing'])) {
            $folder = getenv('ADMIN_BROWSER_TEST') === '1' ? 'browser-notifications' : 'notifications';
            Storage::disk('local')->put($folder.'/'.Str::uuid().'.json', json_encode([
                'recipient' => $mobile, 'message' => $message, 'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));

            return;
        }
        if (config('admin.sms.driver') !== 'm360' || ! config('admin.sms.app_key')
            || ! config('admin.sms.app_secret') || ! config('admin.sms.mask')) {
            $this->unavailable();
        }
        try {
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->post('https://api.m360.com.ph/v3/api/broadcast', [
                    'app_key' => config('admin.sms.app_key'), 'app_secret' => config('admin.sms.app_secret'),
                    'msisdn' => ltrim($mobile, '+'), 'content' => $message,
                    'shortcode_mask' => config('admin.sms.mask'), 'is_intl' => false,
                ]);
        } catch (\Throwable) {
            $this->unavailable();
        }
        $id = $response->json('messageId') ?? $response->json('transid');
        if (! $response->successful() || ! in_array($response->json('code'), [201, '201'], true)
            || (! is_string($id) && ! is_int($id))
            || ! preg_match('/\\A[A-Za-z0-9_-]{1,128}\\z/', (string) $id)) {
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        Log::warning('Admin SMS delivery unavailable. Check M360 configuration and provider connectivity.');
        throw new HttpResponseException(response()->json([
            'message' => 'SMS delivery is temporarily unavailable. Please try again later.',
        ], 503));
    }
}
