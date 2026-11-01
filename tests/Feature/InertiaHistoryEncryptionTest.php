<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InertiaHistoryEncryptionTest extends TestCase
{
    #[DataProvider('origins')]
    public function test_history_encryption_matches_the_environment_and_browser_origin(string $environment, string $origin, bool $configured, bool $encrypted): void
    {
        $this->app->instance('env', $environment);
        config(['inertia.history.encrypt' => $configured]);

        $response = $this->get($origin.'/login')->assertOk();

        $this->assertSame($encrypted, $response->viewData('page')['encryptHistory'] ?? false);
    }

    /** @return array<string, array{string, string, bool, bool}> */
    public static function origins(): array
    {
        return [
            'local HTTP domain' => ['local', 'http://gamexsports-admin.test', true, false],
            'local HTTPS domain' => ['local', 'https://gamexsports-admin.test', true, true],
            'localhost' => ['local', 'http://localhost', true, true],
            'localhost subdomain' => ['local', 'http://admin.localhost', true, true],
            'IPv4 loopback' => ['local', 'http://127.0.0.1', true, true],
            'IPv6 loopback' => ['local', 'http://[::1]', true, true],
            'production HTTP' => ['production', 'http://admin.example.com', true, true],
            'production HTTPS' => ['production', 'https://admin.example.com', true, true],
            'encryption disabled in configuration' => ['local', 'https://gamexsports-admin.test', false, false],
        ];
    }

    public function test_encryption_is_restored_for_a_secure_request_after_a_local_http_request(): void
    {
        $this->app->instance('env', 'local');
        config(['inertia.history.encrypt' => true]);

        $httpResponse = $this->get('http://gamexsports-admin.test/login')->assertOk();
        $this->assertFalse($httpResponse->viewData('page')['encryptHistory'] ?? false);

        $httpsResponse = $this->get('https://gamexsports-admin.test/login')->assertOk();
        $this->assertTrue($httpsResponse->viewData('page')['encryptHistory'] ?? false);
    }
}
