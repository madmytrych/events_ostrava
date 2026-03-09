<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Event;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifyNewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token-123']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createUser(array $overrides = []): TelegramUser
    {
        return TelegramUser::forceCreate(array_merge([
            'chat_id' => random_int(100000, 999999),
            'language' => 'en',
            'timezone' => 'Europe/Prague',
            'notify_new_events' => true,
        ], $overrides));
    }

    private function createNewEvent(array $overrides = []): Event
    {
        $now = Carbon::now('Europe/Prague');

        return Event::forceCreate(array_merge([
            'source' => 'test',
            'source_url' => 'https://example.com/event/' . uniqid(),
            'source_event_id' => (string) random_int(10000, 99999),
            'title' => 'New Event',
            'start_at' => $now->copy()->addDays(2),
            'venue' => 'Test Venue',
            'location_name' => 'Test Venue',
            'fingerprint' => md5(uniqid()),
            'status' => 'approved',
            'is_active' => true,
            'created_at' => $now->copy()->addMinute(),
        ], $overrides));
    }

    public function test_age_filter_excludes_non_matching_events(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $user = $this->createUser(['age_min' => 3, 'age_max' => 6]);
        $this->createNewEvent(['age_min' => 10, 'age_max' => 15]);

        $this->artisan('telegram:notify-new')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_age_filter_includes_matching_events(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $user = $this->createUser(['age_min' => 3, 'age_max' => 6]);
        $this->createNewEvent(['age_min' => 3, 'age_max' => 6]);

        $this->artisan('telegram:notify-new')
            ->assertExitCode(0);

        Http::assertSent(function ($request) use ($user) {
            return str_contains($request->url(), 'sendMessage')
                && $request->data()['chat_id'] === $user->chat_id;
        });
    }
}
