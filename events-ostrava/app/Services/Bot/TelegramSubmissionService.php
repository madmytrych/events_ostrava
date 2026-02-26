<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\EventSubmission;
use App\Models\TelegramUser;
use App\Services\Security\UrlSafety;

class TelegramSubmissionService
{
    public function __construct(
        private readonly TelegramTextService $texts,
        private readonly TelegramKeyboardService $keyboards
    ) {}

    public function start(TelegramUser $user): void
    {
        $user->submission_state = 'url';
        $user->submission_url = null;
        $user->submission_name = null;
        $user->submission_description = null;
        $user->submission_contact = null;
        $user->save();
    }

    public function reset(TelegramUser $user): void
    {
        $user->submission_state = null;
        $user->submission_url = null;
        $user->submission_name = null;
        $user->submission_description = null;
        $user->submission_contact = null;
        $user->save();
    }

    public function isInProgress(TelegramUser $user): bool
    {
        return in_array($user->submission_state, ['url', 'name', 'description', 'contact'], true);
    }

    public function handleInput(TelegramUser $user, string $rawText, string $lang, ?string $action): array
    {
        if ($action === 'cancel') {
            $this->reset($user);

            return [
                'text' => $this->texts->text($lang, 'submit_cancelled'),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        if ($user->submission_state === 'url') {
            $url = $this->sanitizeUrl($rawText);
            if ($url === null) {
                return [
                    'text' => $this->texts->text($lang, 'submit_invalid_url'),
                    'reply_markup' => $this->keyboards->submissionCancel($lang),
                ];
            }

            $user->submission_url = $url;
            $user->submission_state = 'name';
            $user->save();

            return [
                'text' => $this->texts->text($lang, 'submit_ask_name'),
                'reply_markup' => $this->keyboards->submissionSkip($lang),
            ];
        }

        if ($user->submission_state === 'name') {
            if ($action !== 'skip') {
                $name = $this->sanitizeText($rawText, 200);
                if ($name === null) {
                    return [
                        'text' => $this->texts->text($lang, 'submit_too_long'),
                        'reply_markup' => $this->keyboards->submissionSkip($lang),
                    ];
                }
                $user->submission_name = $name;
            }
            $user->submission_state = 'description';
            $user->save();

            return [
                'text' => $this->texts->text($lang, 'submit_ask_description'),
                'reply_markup' => $this->keyboards->submissionSkip($lang),
            ];
        }

        if ($user->submission_state === 'description') {
            if ($action !== 'skip') {
                $description = $this->sanitizeText($rawText, 1000);
                if ($description === null) {
                    return [
                        'text' => $this->texts->text($lang, 'submit_too_long'),
                        'reply_markup' => $this->keyboards->submissionSkip($lang),
                    ];
                }
                $user->submission_description = $description;
            }
            $user->submission_state = 'contact';
            $user->save();

            return [
                'text' => $this->texts->text($lang, 'submit_ask_contact'),
                'reply_markup' => $this->keyboards->submissionSkip($lang),
            ];
        }

        if ($user->submission_state === 'contact') {
            if ($action !== 'skip') {
                $contact = $this->sanitizeText($rawText, 200);
                if ($contact === null) {
                    return [
                        'text' => $this->texts->text($lang, 'submit_too_long'),
                        'reply_markup' => $this->keyboards->submissionSkip($lang),
                    ];
                }
                $user->submission_contact = $contact;
            }

            EventSubmission::create([
                'chat_id' => $user->chat_id,
                'url' => $user->submission_url,
                'name' => $user->submission_name,
                'description' => $user->submission_description,
                'contact' => $user->submission_contact,
                'status' => 'pending',
            ]);

            $this->reset($user);

            return [
                'text' => $this->texts->text($lang, 'submit_saved'),
                'reply_markup' => $this->keyboards->mainMenu($lang),
            ];
        }

        $this->reset($user);

        return [
            'text' => $this->texts->text($lang, 'submit_cancelled'),
            'reply_markup' => $this->keyboards->mainMenu($lang),
        ];
    }

    private function sanitizeUrl(string $text): ?string
    {
        $url = trim($text);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (!UrlSafety::isPublicHttpUrl($url)) {
            return null;
        }

        return $url;
    }

    private function sanitizeText(string $text, int $maxLength): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', $text));
        $value = preg_replace('/[[:cntrl:]]/u', '', (string) $value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) > $maxLength) {
            return null;
        }

        return $value;
    }
}
