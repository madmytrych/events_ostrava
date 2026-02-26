<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\EventSubmission;
use App\Models\TelegramUser;
use App\Services\Bot\TelegramSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramSubmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(TelegramSubmissionService::class);
    }

    private function createUser(array $overrides = []): TelegramUser
    {
        return TelegramUser::forceCreate(array_merge([
            'chat_id' => random_int(100000, 999999),
            'language' => 'en',
            'timezone' => 'Europe/Prague',
        ], $overrides));
    }

    public function test_start_sets_state_to_url(): void
    {
        $user = $this->createUser();

        $this->service->start($user);

        $user->refresh();
        $this->assertSame('url', $user->submission_state);
        $this->assertNull($user->submission_url);
        $this->assertNull($user->submission_name);
        $this->assertNull($user->submission_description);
        $this->assertNull($user->submission_contact);
    }

    public function test_is_in_progress_returns_true_during_flow(): void
    {
        $user = $this->createUser();

        $this->assertFalse($this->service->isInProgress($user));

        $this->service->start($user);
        $this->assertTrue($this->service->isInProgress($user));
    }

    public function test_valid_url_advances_to_name_step(): void
    {
        $user = $this->createUser();
        $this->service->start($user);

        $result = $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);

        $user->refresh();
        $this->assertSame('name', $user->submission_state);
        $this->assertSame('https://example.com/event/123', $user->submission_url);
        $this->assertArrayHasKey('text', $result);
    }

    public function test_invalid_url_stays_on_url_step(): void
    {
        $user = $this->createUser();
        $this->service->start($user);

        $result = $this->service->handleInput($user, 'not a url', 'en', null);

        $user->refresh();
        $this->assertSame('url', $user->submission_state);
        $this->assertNull($user->submission_url);
        $this->assertArrayHasKey('text', $result);
    }

    public function test_skip_advances_through_optional_name_step(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);

        $result = $this->service->handleInput($user, '', 'en', 'skip');

        $user->refresh();
        $this->assertSame('description', $user->submission_state);
        $this->assertNull($user->submission_name);
    }

    public function test_skip_advances_through_optional_description_step(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);
        $this->service->handleInput($user, 'My Event', 'en', null);

        $result = $this->service->handleInput($user, '', 'en', 'skip');

        $user->refresh();
        $this->assertSame('contact', $user->submission_state);
        $this->assertNull($user->submission_description);
    }

    public function test_skip_advances_through_optional_contact_step_and_saves(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);
        $this->service->handleInput($user, 'My Event', 'en', null);
        $this->service->handleInput($user, '', 'en', 'skip');

        $result = $this->service->handleInput($user, '', 'en', 'skip');

        $user->refresh();
        $this->assertNull($user->submission_state);
        $this->assertDatabaseHas('event_submissions', [
            'chat_id' => $user->chat_id,
            'url' => 'https://example.com/event/123',
            'name' => 'My Event',
            'description' => null,
            'contact' => null,
            'status' => 'pending',
        ]);
    }

    public function test_cancel_resets_state(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);

        $result = $this->service->handleInput($user, '', 'en', 'cancel');

        $user->refresh();
        $this->assertNull($user->submission_state);
        $this->assertNull($user->submission_url);
    }

    public function test_full_flow_creates_event_submission_record(): void
    {
        $user = $this->createUser();
        $this->service->start($user);

        $this->service->handleInput($user, 'https://example.com/event/456', 'en', null);
        $this->service->handleInput($user, 'Family Festival', 'en', null);
        $this->service->handleInput($user, 'A great family event in Ostrava', 'en', null);
        $result = $this->service->handleInput($user, 'contact@example.com', 'en', null);

        $user->refresh();
        $this->assertNull($user->submission_state);

        $submission = EventSubmission::where('chat_id', $user->chat_id)->first();
        $this->assertNotNull($submission);
        $this->assertSame('https://example.com/event/456', $submission->url);
        $this->assertSame('Family Festival', $submission->name);
        $this->assertSame('A great family event in Ostrava', $submission->description);
        $this->assertSame('contact@example.com', $submission->contact);
        $this->assertSame('pending', $submission->status);
    }

    public function test_too_long_name_stays_on_name_step(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);

        $longName = str_repeat('a', 201);
        $result = $this->service->handleInput($user, $longName, 'en', null);

        $user->refresh();
        $this->assertSame('name', $user->submission_state);
    }

    public function test_too_long_description_stays_on_description_step(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);
        $this->service->handleInput($user, 'Name', 'en', null);

        $longDesc = str_repeat('a', 1001);
        $result = $this->service->handleInput($user, $longDesc, 'en', null);

        $user->refresh();
        $this->assertSame('description', $user->submission_state);
    }

    public function test_reset_clears_all_submission_fields(): void
    {
        $user = $this->createUser();
        $this->service->start($user);
        $this->service->handleInput($user, 'https://example.com/event/123', 'en', null);
        $this->service->handleInput($user, 'Name', 'en', null);

        $this->service->reset($user);
        $user->refresh();

        $this->assertNull($user->submission_state);
        $this->assertNull($user->submission_url);
        $this->assertNull($user->submission_name);
        $this->assertNull($user->submission_description);
        $this->assertNull($user->submission_contact);
    }
}
