<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\Event;
use App\Models\TelegramUser;
use App\Services\Bot\TelegramMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TelegramMessageHandlerTest extends TestCase
{
    use RefreshDatabase;

    private TelegramMessageHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = $this->app->make(TelegramMessageHandler::class);
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
        ], $overrides));
    }

    private function createEvent(array $overrides = []): Event
    {
        return Event::forceCreate(array_merge([
            'source' => 'test',
            'source_url' => 'https://example.com/event/' . uniqid(),
            'source_event_id' => (string) random_int(10000, 99999),
            'title' => 'Test Event',
            'start_at' => Carbon::now()->addHours(2),
            'venue' => 'Test Venue',
            'location_name' => 'Test Venue',
            'fingerprint' => sha1(uniqid('', true)),
            'status' => 'new',
            'is_active' => true,
        ], $overrides));
    }

    // --- New user without language ---

    public function test_new_user_without_language_gets_language_prompt(): void
    {
        $chatId = random_int(100000, 999999);

        $response = $this->handler->handle($chatId, '/start');

        $this->assertNotNull($response);
        $this->assertStringContainsString('language', strtolower($response['text']));
    }

    // --- Language selection ---

    public function test_language_selection_saves_language(): void
    {
        $chatId = random_int(100000, 999999);

        $response = $this->handler->handle($chatId, 'lang:uk');

        $this->assertNotNull($response);
        $user = TelegramUser::query()->where('chat_id', $chatId)->first();
        $this->assertSame('uk', $user->language);
    }

    public function test_invalid_language_selection_is_ignored(): void
    {
        $chatId = random_int(100000, 999999);

        $this->handler->handle($chatId, 'lang:fr');

        $user = TelegramUser::query()->where('chat_id', $chatId)->first();
        $this->assertNull($user->language);
    }

    // --- /start and /help ---

    public function test_start_command_returns_welcome(): void
    {
        $user = $this->createUser();

        $response = $this->handler->handle($user->chat_id, '/start');

        $this->assertNotNull($response);
        $this->assertStringContainsString('KidsEvents', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    public function test_help_command_returns_welcome(): void
    {
        $user = $this->createUser();

        $response = $this->handler->handle($user->chat_id, '/help');

        $this->assertNotNull($response);
        $this->assertStringContainsString('KidsEvents', $response['text']);
    }

    // --- Event queries ---

    public function test_today_button_returns_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 09:00:00', 'Europe/Prague'));

        $user = $this->createUser();
        $this->createEvent([
            'title' => 'Today Event',
            'start_at' => Carbon::now('Europe/Prague')->setTime(14, 0)->utc(),
        ]);

        $todayButton = '📅 Today';
        $response = $this->handler->handle($user->chat_id, $todayButton);

        $this->assertNotNull($response);
        $this->assertStringContainsString('Today Event', $response['text']);
    }

    public function test_today_slash_command_returns_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 09:00:00', 'Europe/Prague'));

        $user = $this->createUser();
        $this->createEvent([
            'title' => 'Today Slash Event',
            'start_at' => Carbon::now('Europe/Prague')->setTime(14, 0)->utc(),
        ]);

        $response = $this->handler->handle($user->chat_id, '/today');

        $this->assertNotNull($response);
        $this->assertStringContainsString('Today Slash Event', $response['text']);
    }

    public function test_weekend_button_returns_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-09 09:00:00', 'Europe/Prague'));

        $user = $this->createUser();
        $this->createEvent([
            'title' => 'Weekend Fun',
            'start_at' => Carbon::parse('2026-03-14 10:00:00', 'Europe/Prague')->utc(),
        ]);

        $weekendButton = '🎉 This weekend';
        $response = $this->handler->handle($user->chat_id, $weekendButton);

        $this->assertNotNull($response);
        $this->assertStringContainsString('Weekend Fun', $response['text']);
    }

    // --- Settings ---

    public function test_settings_button_returns_settings(): void
    {
        $user = $this->createUser();

        $settingsButton = '⚙️ Settings';
        $response = $this->handler->handle($user->chat_id, $settingsButton);

        $this->assertNotNull($response);
        $this->assertStringContainsString('Settings', $response['text']);
    }

    // --- Notify toggle ---

    public function test_notify_command_on(): void
    {
        $user = $this->createUser(['notify_enabled' => false]);

        $response = $this->handler->handle($user->chat_id, '/notify on');

        $this->assertNotNull($response);
        $user->refresh();
        $this->assertTrue($user->notify_enabled);
    }

    public function test_notify_command_off(): void
    {
        $user = $this->createUser(['notify_enabled' => true]);

        $response = $this->handler->handle($user->chat_id, '/notify off');

        $this->assertNotNull($response);
        $user->refresh();
        $this->assertFalse($user->notify_enabled);
    }

    // --- Prefs ---

    public function test_prefs_command_returns_user_settings(): void
    {
        $user = $this->createUser(['age_min' => 3, 'age_max' => 6]);

        $response = $this->handler->handle($user->chat_id, '/prefs');

        $this->assertNotNull($response);
        $this->assertStringContainsString('3–6', $response['text']);
    }

    // --- Age selection ---

    public function test_age_range_button_saves_preference(): void
    {
        $user = $this->createUser();

        $response = $this->handler->handle($user->chat_id, '🧒 3–6 years');

        $this->assertNotNull($response);
        $user->refresh();
        $this->assertSame(3, $user->age_min);
        $this->assertSame(6, $user->age_max);
    }

    // --- Unknown text ---

    public function test_unknown_text_returns_use_buttons(): void
    {
        $user = $this->createUser();

        $response = $this->handler->handle($user->chat_id, 'random gibberish');

        $this->assertNotNull($response);
        $this->assertStringContainsString('buttons', strtolower($response['text']));
    }

    public function test_unknown_slash_command_returns_help(): void
    {
        $user = $this->createUser();

        $response = $this->handler->handle($user->chat_id, '/unknown');

        $this->assertNotNull($response);
        $this->assertStringContainsString('buttons', strtolower($response['text']));
    }

    // --- Empty input ---

    public function test_empty_text_returns_null(): void
    {
        $this->assertNull($this->handler->handle(12345, ''));
        $this->assertNull($this->handler->handle(12345, '   '));
    }

    // --- getUserLanguage ---

    public function test_get_user_language_returns_stored_language(): void
    {
        $user = $this->createUser(['language' => 'uk']);
        $this->assertSame('uk', $this->handler->getUserLanguage($user));
    }

    public function test_get_user_language_defaults_to_en(): void
    {
        $user = $this->createUser(['language' => null]);
        $this->assertSame('en', $this->handler->getUserLanguage($user));
    }

    public function test_get_user_language_rejects_invalid(): void
    {
        $user = $this->createUser(['language' => 'fr']);
        $this->assertSame('en', $this->handler->getUserLanguage($user));
    }
}
