<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Event;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifyTest extends TestCase
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
            'notify_enabled' => true,
        ], $overrides));
    }

    private function createWeekendEvent(array $overrides = []): Event
    {
        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));

        $saturday = Carbon::parse('2026-03-07 14:00:00', 'Europe/Prague');

        return Event::forceCreate(array_merge([
            'source' => 'test',
            'source_url' => 'https://example.com/event/' . uniqid(),
            'source_event_id' => (string) random_int(10000, 99999),
            'title' => 'Weekend Event',
            'start_at' => $saturday,
            'venue' => 'Test Venue',
            'location_name' => 'Test Venue',
            'fingerprint' => md5(uniqid()),
            'status' => 'approved',
            'is_active' => true,
        ], $overrides));
    }

    public function test_users_with_notify_enabled_receive_messages(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $user = $this->createUser();
        $this->createWeekendEvent();

        $this->artisan('telegram:notify')
            ->assertExitCode(0);

        Http::assertSent(function ($request) use ($user) {
            return str_contains($request->url(), 'sendMessage')
                && $request->data()['chat_id'] === $user->chat_id;
        });
    }

    public function test_users_with_no_weekend_events_are_skipped(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $this->createUser();

        $this->artisan('telegram:notify')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_send_messages(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $this->createUser();
        $this->createWeekendEvent();

        $this->artisan('telegram:notify', ['--dry-run' => true])
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_notify_disabled_users_are_not_contacted(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $this->createUser(['notify_enabled' => false]);
        $this->createWeekendEvent();

        $this->artisan('telegram:notify')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_notify_updates_last_sent_timestamp(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-04 10:00:00', 'Europe/Prague'));
        $user = $this->createUser();
        $this->createWeekendEvent();

        $this->assertNull($user->notify_last_sent_at);

        $this->artisan('telegram:notify')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertNotNull($user->notify_last_sent_at);
    }

    public function test_fails_without_bot_token(): void
    {
        config(['services.telegram.bot_token' => null]);

        $this->artisan('telegram:notify')
            ->assertExitCode(1);
    }
}
