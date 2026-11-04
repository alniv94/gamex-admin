<?php

namespace App\Services\Players;

use App\Mail\PlayerSmsMessage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PlayerSms
{
    private const EXEMPTED_LEGACY_IDS = [190, 22, 513, 254, 23, 501, 806, 26934, 51282, 53497, 57054, 3390, 58171, 20266, 1952, 55935, 58349, 6, 25, 24298, 30599, 41356, 52710, 53062, 54890, 55589, 55638, 55639, 55644, 57493, 59835, 14, 9, 11, 12, 13, 24, 26, 27];

    public function eligiblePlayers(): Builder
    {
        $excludedMappings = DB::table('legacy_player_mappings')->select('player_id')->whereNotNull('player_id')
            ->where('source', config('players.legacy_source'));

        return DB::table('players as p')->join('player_profiles as i', 'i.player_id', '=', 'p.id')
            ->where('p.status', 'Active')->where('i.kyc_status', '<>', 'Rejected')
            ->where('p.mobile_number', 'like', '+639_________')
            ->whereNotIn('p.id', (clone $excludedMappings)->whereIn('legacy_player_id', self::EXEMPTED_LEGACY_IDS))
            ->whereNotIn('p.id', (clone $excludedMappings)->where('quarantined', true));
    }

    /** @param list<int> $playerIds */
    public function send(int $userId, string $requestId, string $message, bool $blast, array $playerIds): int
    {
        sort($playerIds);
        $fingerprint = hash('sha256', json_encode([$message, $blast, $blast ? [] : $playerIds], JSON_THROW_ON_ERROR));
        $existing = DB::table('admin_sms_deliveries')->where('request_id', $requestId)->first();
        if ($existing !== null) {
            if ((int) $existing->user_id === $userId && $existing->fingerprint === $fingerprint && $existing->status === 'submitted') {
                return (int) $existing->recipient_count;
            }
            throw ValidationException::withMessages(['delivery' => 'This submission has already been processed or is awaiting confirmation. Check its delivery status before composing another message.']);
        }

        $query = $this->eligiblePlayers()->when(! $blast, fn (Builder $query) => $query->whereIn('p.id', $playerIds));
        $players = $query->select(['p.id', 'p.mobile_number'])->get();
        if ($players->isEmpty() || (! $blast && $players->count() !== count($playerIds))) {
            throw ValidationException::withMessages(['player_ids' => 'Some recipients are no longer eligible. Refresh the list and select players again.']);
        }
        $numbers = [];
        $seen = [];
        foreach ($players as $player) {
            $number = $player->mobile_number;
            if (! is_string($number) || ! preg_match('/\A\+639[0-9]{9}\z/', $number)) {
                throw ValidationException::withMessages(['player_ids' => 'A recipient has an invalid mobile number. Correct it before sending.']);
            }
            if (! isset($seen[$number])) {
                $seen[$number] = true;
                $numbers[] = $number;
            }
        }
        if (! config('mail.mailers.sms.username') || ! config('mail.mailers.sms.password') || ! config('admin.player_sms.from_address')) {
            throw ValidationException::withMessages(['delivery' => 'SMS delivery is unavailable. Ask an administrator to check the SMS configuration.']);
        }
        $created = DB::table('admin_sms_deliveries')->insertOrIgnore([
            'request_id' => $requestId, 'user_id' => $userId, 'fingerprint' => $fingerprint,
            'is_blast' => $blast, 'recipient_count' => count($numbers), 'status' => 'submitting',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($created === 0) {
            throw ValidationException::withMessages(['delivery' => 'This submission is already being processed. Do not send it again.']);
        }

        try {
            $sent = Mail::mailer('sms')->to(config('admin.player_sms.gateway'))->send(new PlayerSmsMessage($message, $numbers));
            if ($sent === null) {
                throw new \RuntimeException('SMS mail was not accepted.');
            }
        } catch (\Throwable) {
            DB::table('admin_sms_deliveries')->where('request_id', $requestId)->update(['status' => 'unconfirmed', 'updated_at' => now()]);
            Log::warning('Player SMS submission could not be confirmed.', ['request_id' => $requestId]);
            throw ValidationException::withMessages(['delivery' => 'SMS submission could not be confirmed. Check delivery with the provider before sending again to avoid duplicate charges.']);
        }
        DB::table('admin_sms_deliveries')->where('request_id', $requestId)->update(['status' => 'submitted', 'updated_at' => now()]);

        return count($numbers);
    }
}
