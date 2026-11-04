<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait OtsoPromoFixtures
{
    private function promoEventsTable(): void
    {
        Schema::create('otso_panalo_promo_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedInteger('sequence');
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            foreach (['event_id', 'session_id', 'flow_id', 'impression_id', 'tutorial_id'] as $name) {
                $table->string($name, 36)->nullable();
            }
            foreach (['event_name', 'campaign', 'source', 'route_name', 'source_route', 'device_type', 'reason'] as $name) {
                $table->string($name)->nullable();
            }
            foreach (['dialog_step', 'slide_index', 'total_slides'] as $name) {
                $table->unsignedTinyInteger($name)->nullable();
            }
            $table->unique(['player_id', 'event_id']);
        });
    }

    /** @param array<string, mixed> $overrides */
    private function event(string $name, array $overrides = []): void
    {
        DB::table('otso_panalo_promo_events')->insert($overrides + [
            'player_id' => 9, 'sequence' => 1, 'event_id' => (string) Str::uuid(), 'session_id' => 'session',
            'impression_id' => 'first', 'dialog_step' => 1, 'campaign' => 'otso_panalo_login_v1',
            'event_name' => $name, 'source' => 'login_promo', 'source_route' => 'Home', 'route_name' => 'Home',
            'device_type' => 'mobile', 'occurred_at' => '2026-11-02 00:00:00', 'received_at' => '2026-11-02 00:00:01',
        ]);
    }
}
