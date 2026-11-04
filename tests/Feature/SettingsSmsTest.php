<?php

namespace Tests\Feature;

use App\Mail\PlayerSmsMessage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mime\Email;
use Tests\Support\EndingFixtures;
use Tests\Support\PlayerFixtures;
use Tests\TestCase;

class SettingsSmsTest extends TestCase
{
    use EndingFixtures, PlayerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.mailers.sms' => ['transport' => 'array', 'username' => 'sms@example.test', 'password' => 'test-only'],
            'admin.player_sms.from_address' => 'sms@example.test',
            'admin.player_sms.gateway' => 'gateway@example.test',
        ]);
    }

    private function manager(): void
    {
        $this->playerFixtures();
        $this->endingAdmin([])->givePermissionTo('SMS');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'message' => 'GameXSports update', 'blast' => false, 'player_ids' => [9]];
    }

    private function transport(): ArrayTransport
    {
        $transport = Mail::mailer('sms')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    public function test_guests_cannot_view_or_send_sms(): void
    {
        $this->get('/settings/sms')->assertRedirect('/login');
        $this->post('/settings/sms', $this->payload())->assertRedirect('/login');
        $this->assertDatabaseCount('admin_sms_deliveries', 0);
    }

    public function test_administrators_without_sms_permission_receive_403(): void
    {
        $this->endingAdmin([15]);

        $this->get('/settings/sms')->assertForbidden();
        $this->post('/settings/sms', $this->payload())->assertForbidden();
        $this->assertCount(0, $this->transport()->messages());
    }

    public function test_lists_only_eligible_players_and_applies_exemptions_to_legacy_ids(): void
    {
        $this->manager();
        DB::table('players')->where('id', 10)->update(['status' => 'Inactive']);
        DB::table('legacy_player_mappings')->insert([
            'source' => config('players.legacy_source'), 'legacy_player_id' => 190, 'player_id' => 11, 'quarantined' => false,
        ]);

        $this->get('/settings/sms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Sms')->has('players.data', 1)->where('players.data.0.id', 9)
            ->where('players.data.0.mobile_number', '+639123456709')->where('totalRecipients', 1)
            ->missing('players.data.0.password')->missing('players.data.0.email'));
    }

    public function test_rejected_missing_mobile_and_quarantined_players_are_excluded(): void
    {
        $this->manager();
        DB::table('player_profiles')->where('player_id', 9)->update(['kyc_status' => 'Rejected']);
        DB::table('players')->where('id', 10)->update(['mobile_number' => null]);
        DB::table('legacy_player_mappings')->insert([
            'source' => config('players.legacy_source'), 'legacy_player_id' => 99999, 'player_id' => 11, 'quarantined' => true,
        ]);

        $this->get('/settings/sms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('players.data', 0)->where('totalRecipients', 0));
    }

    public function test_search_pagination_and_sort_do_not_change_the_blast_total(): void
    {
        $this->manager();
        DB::table('players')->where('id', 10)->update(['name' => 'Zelda Player']);

        $this->get('/settings/sms?search=Zelda&direction=desc&per_page=25')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('players.data', 1)->where('players.data.0.id', 10)
            ->where('players.per_page', 25)->where('totalRecipients', 3));
    }

    public function test_page_changes_reuse_counts_and_keep_search_and_sorting(): void
    {
        $this->manager();
        foreach (range(100, 111) as $id) {
            DB::table('players')->insert(['id' => $id, 'name' => 'Search Player '.($id - 100), 'mobile_number' => '+639123456'.str_pad((string) $id, 3, '0', STR_PAD_LEFT)]);
            DB::table('player_profiles')->insert(['player_id' => $id, 'kyc_status' => 'Verified']);
        }
        $counts = [];
        DB::listen(function (QueryExecuted $query) use (&$counts): void {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'players')) {
                $counts[] = $query->sql;
            }
        });
        $this->get('/settings/sms?search=Search&direction=desc')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 12)->where('totalRecipients', 15));
        $this->assertCount(2, $counts);

        $this->get('/settings/sms?search=Search&direction=desc&page=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('players.total', 12)
                ->where('players.current_page', 2)->has('players.data', 2)
                ->where('players.data.0.player_name', 'Search Player 1')->where('players.data.1.player_name', 'Search Player 0')
                ->where('totalRecipients', 15));

        $this->assertCount(2, $counts);
    }

    public function test_unfiltered_pagination_counts_recipients_once(): void
    {
        $this->manager();
        $counts = [];
        DB::listen(function (QueryExecuted $query) use (&$counts): void {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'players')) {
                $counts[] = $query->sql;
            }
        });

        $this->get('/settings/sms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('players.total', 3)->where('totalRecipients', 3));

        $this->assertCount(1, $counts);
    }

    public function test_cached_counts_expire_and_numeric_zero_search_is_applied(): void
    {
        $this->manager();
        $this->freezeTime();
        $this->get('/settings/sms')->assertOk();
        DB::table('players')->where('id', 9)->update(['status' => 'Inactive']);
        $this->travel(31)->seconds();

        $this->get('/settings/sms?search=0')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('players.total', 1)->where('players.data.0.id', 10)->where('totalRecipients', 2));
    }

    public function test_recipient_eligibility_is_rechecked_even_when_counts_are_cached(): void
    {
        $this->manager();
        $this->get('/settings/sms')->assertOk();
        DB::table('players')->where('id', 9)->update(['status' => 'Inactive']);

        $this->postJson('/settings/sms', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('player_ids');

        $this->assertCount(0, $this->transport()->messages());
        $this->assertDatabaseCount('admin_sms_deliveries', 0);
    }

    public function test_selected_sms_uses_the_gateway_format_and_does_not_resend_a_duplicate_request(): void
    {
        $this->manager();
        $payload = $this->payload();

        $this->from('/settings/sms')->post('/settings/sms', $payload)->assertRedirect('/settings/sms')->assertSessionHasNoErrors();
        $this->from('/settings/sms')->post('/settings/sms', $payload)->assertRedirect('/settings/sms')->assertSessionHasNoErrors();

        $messages = $this->transport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('GAMEXSPORTS', $email->getSubject());
        $this->assertSame('gateway@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('sms@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('GameXSports update', trim($email->getTextBody()));
        $this->assertSame("MSISDN\n9123456709\n", $email->getAttachments()[0]->getBody());
        $this->assertDatabaseHas('admin_sms_deliveries', ['request_id' => $payload['request_id'], 'recipient_count' => 1, 'is_blast' => false, 'status' => 'submitted']);
        $this->assertDatabaseCount('admin_sms_deliveries', 1);
    }

    public function test_blast_uses_all_eligible_recipients_and_deduplicates_mobile_numbers_in_memory_only(): void
    {
        $this->manager();
        DB::table('players')->where('id', 10)->update(['mobile_number' => '+639123456709']);
        $payload = [...$this->payload(), 'blast' => true, 'player_ids' => []];

        $this->from('/settings/sms?search=absent')->post('/settings/sms', $payload)->assertSessionHasNoErrors();

        $messages = $this->transport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame("MSISDN\n9123456709\n9123456711\n", $email->getAttachments()[0]->getBody());
        $this->assertDatabaseHas('admin_sms_deliveries', ['is_blast' => true, 'recipient_count' => 2, 'status' => 'submitted']);
    }

    #[TestWith(['message', ''])]
    #[TestWith(['message', '                                                                                                                                                                 '])]
    #[TestWith(['message', '12345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901'])]
    #[TestWith(['player_ids', []])]
    #[TestWith(['player_ids', [999999]])]
    #[TestWith(['player_ids', [9, 10]])]
    #[TestWith(['player_ids.1', [9, 9]])]
    #[TestWith(['request_id', 'invalid'])]
    #[TestWith(['blast', 'invalid'])]
    public function test_invalid_or_stale_recipients_and_messages_send_nothing(string $error, mixed $value): void
    {
        $this->manager();
        DB::table('players')->where('id', 10)->update(['status' => 'Inactive']);
        $field = str_starts_with($error, 'player_ids') ? 'player_ids' : $error;

        $this->postJson('/settings/sms', [...$this->payload(), $field => $value])->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertCount(0, $this->transport()->messages());
        $this->assertDatabaseCount('admin_sms_deliveries', 0);
    }

    public function test_missing_credentials_prevents_submitting_and_retains_a_retryable_request(): void
    {
        $this->manager();
        config(['mail.mailers.sms.password' => '']);

        $this->postJson('/settings/sms', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('delivery');

        $this->assertCount(0, $this->transport()->messages());
        $this->assertDatabaseCount('admin_sms_deliveries', 0);
    }

    public function test_unconfirmed_delivery_cannot_be_submitted_again(): void
    {
        $this->manager();
        $payload = $this->payload();
        Event::listen(MessageSending::class, fn (): bool => false);
        $this->postJson('/settings/sms', $payload)->assertUnprocessable()->assertJsonValidationErrors('delivery');

        $this->postJson('/settings/sms', $payload)->assertUnprocessable()->assertJsonValidationErrors('delivery');

        $this->assertCount(0, $this->transport()->messages());
        $this->assertDatabaseHas('admin_sms_deliveries', ['request_id' => $payload['request_id'], 'status' => 'unconfirmed']);
    }

    public function test_gateway_body_keeps_sms_text_literal(): void
    {
        $message = new PlayerSmsMessage('Hello <player> & friends', ['+639123456789']);

        $message->assertSeeInText('Hello <player> & friends', false);
    }
}
