<?php

namespace App\Services\Players;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PagcorLookup
{
    /** @return array<string, mixed> */
    public function search(string $name): array
    {
        $url = config('players.pagcor.base_url');
        $password = config('players.pagcor.aes_key');
        $member = config('players.pagcor.member_id');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || strlen($password) !== 16 || strlen($member) !== 16) {
            return ['status' => 'unavailable', 'message' => 'PAGCOR NDRP lookup is not configured.'];
        }

        $cacheKey = 'players:ndrp:'.hash('sha256', $url.$member.$name);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $salt = random_bytes(8);
            $material = '';
            $digest = '';
            while (strlen($material) < 48) {
                $digest = md5($digest.$password.$salt, true);
                $material .= $digest;
            }
            $encrypted = openssl_encrypt($name, 'aes-256-cbc', substr($material, 0, 32), OPENSSL_RAW_DATA, substr($material, 32, 16));
            $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->post(rtrim($url, '/').'/GetAll', [
                'memberId' => $member, 'searchData' => base64_encode('Salted__'.$salt.$encrypted),
            ]);
            $data = $response->json('data');
            if (! $response->successful() || ! is_array($data) || ! is_array($data['getAllResultDtos'] ?? null)) {
                return ['status' => 'error', 'message' => 'PAGCOR NDRP is temporarily unavailable. Please try again.'];
            }

            $result = ['status' => 'ready', 'data' => Arr::only($data, [
                'totalNumberOfRecords', 'numberOfGelRecords', 'numberOfGuestRecords', 'numberOfGovtRecords',
            ]) + ['records' => array_map(fn (array $record) => Arr::only($record, ['firstName', 'middleName', 'lastName', 'birthdate', 'source', 'photoPath']), $data['getAllResultDtos'])]];
            Cache::put($cacheKey, $result, 300);

            return $result;
        } catch (Throwable) {
            return ['status' => 'error', 'message' => 'PAGCOR NDRP is temporarily unavailable. Please try again.'];
        }
    }
}
