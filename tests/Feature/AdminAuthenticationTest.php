<?php

namespace Tests\Feature;

use App\Models\AdminProfile;
use App\Models\User;
use App\Services\Auth\AdminSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private string $sentCode = '';

    private function postSessionJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $response = $this->postJson($uri, $data, $headers);
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie) {
            $this->withCookie(config('session.cookie'), $cookie->getValue());
        }

        return $response;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->mock(AdminSms::class)->shouldReceive('send')->andReturnUsing(function ($mobile, $code): void {
            $this->sentCode = $code;
        });
    }

    private function admin(string $status = 'Active'): User
    {
        $user = User::factory()->create(['status' => $status, 'mobile_number' => '+639171234567']);
        AdminProfile::create([
            'user_id' => $user->id, 'legacy_admin_id' => $user->id, 'source_checksum' => str_repeat('a', 64),
            'legacy_payload' => ['fixture' => true],
        ]);

        return $user;
    }

    private function requestCode(): string
    {
        return $this->postSessionJson('/api/login/otp', ['mobile_number' => '0917 123 4567'])
            ->assertOk()->json('challenge_id');
    }

    private function login(): User
    {
        $user = $this->admin();
        $id = $this->requestCode();
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $this->sentCode])
            ->assertOk()->assertJsonPath('redirect', '/dashboard');

        return $user;
    }

    public function test_guests_cannot_access_admin_pages_or_api(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->getJson('/api/user')->assertUnauthorized();
        $this->get('/register')->assertNotFound();
        $this->postSessionJson('/login', ['password' => 'password'])->assertStatus(405);
        $this->get('/login')->assertOk();
    }

    public function test_otp_login_session_and_logout(): void
    {
        $user = $this->login();
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.profile.legacy_payload');
        $this->get('/dashboard')->assertOk();
        $this->postSessionJson('/api/logout')->assertOk();
        $this->assertGuest();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseHas('admin_auth_logs', ['user_id' => $user->id, 'action' => 'login']);
        $this->assertDatabaseHas('admin_auth_logs', ['user_id' => $user->id, 'action' => 'logout']);
    }

    public function test_unknown_and_inactive_accounts_never_receive_a_challenge(): void
    {
        $this->requestCode();
        $this->assertDatabaseCount('admin_otp_challenges', 0);
        $this->travel(61)->seconds();
        $this->admin('Inactive');
        $this->requestCode();
        $this->assertDatabaseCount('admin_otp_challenges', 0);
        $this->assertSame('', $this->sentCode);
    }

    public function test_players_cannot_request_an_admin_code(): void
    {
        Schema::create('players', function ($table): void {
            $table->id();
            $table->string('mobile_number');
            $table->string('status');
        });
        DB::table('players')->insert(['mobile_number' => '+639171234567', 'status' => 'Active']);
        $this->requestCode();
        $this->assertDatabaseCount('admin_otp_challenges', 0);
        $this->assertGuest();
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->admin();
        $id = $this->requestCode();
        $this->travel(5)->minutes();
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $this->sentCode])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_five_failed_attempts_permanently_exhaust_a_code(): void
    {
        $this->admin();
        $id = $this->requestCode();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => '000000'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('admin_otp_challenges', ['id' => $id, 'attempts' => 5]);
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $this->sentCode])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_challenge_is_bound_to_the_requesting_session(): void
    {
        $this->admin();
        $id = $this->requestCode();
        $this->withCookie(config('session.cookie'), Str::random(40));
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $this->sentCode])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_resend_invalidates_the_previous_code(): void
    {
        $this->admin();
        $old = $this->requestCode();
        $oldCode = $this->sentCode;
        $this->travel(61)->seconds();
        $new = $this->requestCode();
        $this->postSessionJson('/api/login', ['challenge_id' => $old, 'otp' => $oldCode])->assertUnprocessable();
        $this->postSessionJson('/api/login', ['challenge_id' => $new, 'otp' => $this->sentCode])->assertOk();
    }

    public function test_used_codes_cannot_be_replayed(): void
    {
        $this->admin();
        $id = $this->requestCode();
        $code = $this->sentCode;
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $code])->assertOk();
        $this->postSessionJson('/api/logout')->assertOk();
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $code])->assertUnprocessable();
    }

    public function test_resend_cooldown_and_phone_limit_are_enforced(): void
    {
        $this->admin();
        $this->requestCode();
        $this->postSessionJson('/api/login/otp', ['mobile_number' => '09171234567'])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->requestCode();
        $this->travel(61)->seconds();
        $this->postSessionJson('/api/login/otp', ['mobile_number' => '09171234567'])->assertTooManyRequests();
    }

    public function test_deactivation_and_another_login_end_existing_sessions(): void
    {
        $user = $this->login();
        $user->profile()->increment('session_version');
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_deactivation_is_checked_on_every_authenticated_request(): void
    {
        $user = $this->login();
        $user->update(['status' => 'Inactive']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_csrf_is_required_for_mutation_requests(): void
    {
        $this->app['env'] = 'local';
        $this->withSession(['_token' => 'known-csrf-token']);
        $this->postSessionJson('/api/login/otp', ['mobile_number' => '09171234567'])->assertStatus(419);
        $this->postSessionJson('/api/login/otp', ['mobile_number' => 'invalid'], ['X-CSRF-TOKEN' => 'known-csrf-token'])->assertUnprocessable();
    }

    public function test_sms_failure_does_not_leave_a_usable_code(): void
    {
        $this->admin();
        $this->app->forgetInstance(AdminSms::class);
        config(['admin.sms.driver' => 'm360', 'admin.sms.app_key' => 'fixture', 'admin.sms.app_secret' => 'fixture']);
        Http::fake(['api.m360.com.ph/*' => Http::response(['code' => 500], 500)]);
        $this->postSessionJson('/api/login/otp', ['mobile_number' => '09171234567'], ['X-CSRF-TOKEN' => csrf_token()])->assertStatus(503);
        $this->assertDatabaseCount('admin_otp_challenges', 0);
    }

    public function test_m360_accepted_response_marks_a_delivered_challenge(): void
    {
        $this->admin();
        $this->app->forgetInstance(AdminSms::class);
        config(['admin.sms.driver' => 'm360', 'admin.sms.app_key' => 'fixture', 'admin.sms.app_secret' => 'fixture']);
        Http::fake(['api.m360.com.ph/*' => Http::response(['code' => 201, 'messageId' => 'fixture-message-1'], 201)]);
        $id = $this->requestCode();
        $this->assertNotNull(DB::table('admin_otp_challenges')->where('id', $id)->value('delivered_at'));
        Http::assertSent(fn ($request) => $request['msisdn'] === '639171234567'
            && $request['app_key'] === 'fixture'
            && str_contains($request['content'], 'admin sign-in code'));
    }

    public function test_ip_limits_apply_across_distinct_mobile_numbers(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postSessionJson('/api/login/otp', ['mobile_number' => '0919'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)])->assertOk();
        }
        $this->postSessionJson('/api/login/otp', ['mobile_number' => '09190000010'])->assertTooManyRequests();
    }

    public function test_a_second_browser_login_ends_only_the_first_session(): void
    {
        $this->login();
        $first = $this->defaultCookies[config('session.cookie')];
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->withCookie(config('session.cookie'), Str::random(40));
        $this->travel(61)->seconds();
        $id = $this->requestCode();
        $this->postSessionJson('/api/login', ['challenge_id' => $id, 'otp' => $this->sentCode])->assertOk();
        $second = $this->defaultCookies[config('session.cookie')];
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->withCookie(config('session.cookie'), $first);
        $this->getJson('/api/user')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->withCookie(config('session.cookie'), $second);
        $this->getJson('/api/user')->assertOk();
    }

    public function test_capture_mode_is_local_only_and_otp_is_hashed(): void
    {
        $this->admin();
        $this->app->forgetInstance(AdminSms::class);
        Storage::fake('local');
        config(['admin.sms.driver' => 'capture']);
        $id = $this->requestCode();
        $files = Storage::disk('local')->allFiles('notifications');
        $this->assertCount(1, $files);
        $data = json_decode(Storage::disk('local')->get($files[0]), true);
        preg_match('/\\b([0-9]{6})\\b/', $data['message'], $matches);
        $hash = DB::table('admin_otp_challenges')->where('id', $id)->value('code_hash');
        $this->assertNotSame($matches[1], $hash);
        $this->assertTrue(Hash::check($matches[1], $hash));
        $this->travel(61)->seconds();
        $this->app['env'] = 'production';
        $this->postSessionJson('/api/login/otp', ['mobile_number' => '09171234567'], ['X-CSRF-TOKEN' => csrf_token()])->assertStatus(503);
    }
}
