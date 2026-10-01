<?php

namespace App\Services\Bot\Clan;

use Telegram\Bot\Api;
use Throwable;

class ClanChatService
{
    public function __construct(
        private Api $telegram
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Получить Telegram-чат
    |--------------------------------------------------------------------------
    */

    public function getChat(string|int $chatId): ?object
    {
        try {
            return $this->telegram->getChat([
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            \Log::warning(
                'Clan Telegram chat lookup failed',
                [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Получить количество участников
    |--------------------------------------------------------------------------
    */

    public function getMemberCount(string|int $chatId): int
    {
        try {
            return (int) $this->telegram->getChatMemberCount([
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            \Log::warning(
                'Clan Telegram member count failed',
                [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]
            );

            return 0;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Получить участника
    |--------------------------------------------------------------------------
    */

    public function getMember(
        string|int $chatId,
        int $userId
    ): ?object {
        try {
            return $this->telegram->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);
        } catch (Throwable $e) {
            \Log::warning(
                'Clan Telegram member lookup failed',
                [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Проверка владельца
    |--------------------------------------------------------------------------
    */

    public function isOwner(
        string|int $chatId,
        int $userId
    ): bool {
        $member = $this->getMember(
            $chatId,
            $userId
        );

        if (!$member) {
            return false;
        }

        return ($member->status ?? null) === 'creator';
    }

    /*
    |--------------------------------------------------------------------------
    | Проверка, является ли пользователь участником
    |--------------------------------------------------------------------------
    */

    public function isMember(
        string|int $chatId,
        int $userId
    ): bool {
        $member = $this->getMember(
            $chatId,
            $userId
        );

        if (!$member) {
            return false;
        }

        return in_array(
            $member->status ?? null,
            [
                'creator',
                'administrator',
                'member',
            ],
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Замутить пользователя в Telegram-чате
    |--------------------------------------------------------------------------
    */

    public function mute(
        string|int $chatId,
        int $userId,
        int $untilTimestamp
    ): bool {
        try {
            $this->telegram->restrictChatMember([
                'chat_id' => $chatId,

                'user_id' => $userId,

                'until_date' => $untilTimestamp,

                'permissions' => [
                    'can_send_messages' => false,

                    'can_send_audios' => false,

                    'can_send_documents' => false,

                    'can_send_photos' => false,

                    'can_send_videos' => false,

                    'can_send_video_notes' => false,

                    'can_send_voice_notes' => false,

                    'can_send_polls' => false,

                    'can_send_other_messages' => false,

                    'can_add_web_page_previews' => false,

                    'can_change_info' => false,

                    'can_invite_users' => false,

                    'can_pin_messages' => false,

                    'can_manage_topics' => false,
                ],
            ]);

            return true;
        } catch (Throwable $e) {
            \Log::error(
                'Clan Telegram mute failed',
                [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'until_date' => $untilTimestamp,
                    'error' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Снять мут в Telegram-чате
    |--------------------------------------------------------------------------
    */

    public function unmute(
        string|int $chatId,
        int $userId
    ): bool {
        try {
            $this->telegram->restrictChatMember([
                'chat_id' => $chatId,

                'user_id' => $userId,

                'permissions' => [
                    'can_send_messages' => true,

                    'can_send_audios' => true,

                    'can_send_documents' => true,

                    'can_send_photos' => true,

                    'can_send_videos' => true,

                    'can_send_video_notes' => true,

                    'can_send_voice_notes' => true,

                    'can_send_polls' => true,

                    'can_send_other_messages' => true,

                    'can_add_web_page_previews' => true,

                    'can_change_info' => false,

                    'can_invite_users' => true,

                    'can_pin_messages' => false,

                    'can_manage_topics' => false,
                ],
            ]);

            return true;
        } catch (Throwable $e) {
            \Log::error(
                'Clan Telegram unmute failed',
                [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Получить username чата
    |--------------------------------------------------------------------------
    */

    public function getUsername(object $chat): ?string
    {
        $username = $chat->username ?? null;

        if (!$username) {
            return null;
        }

        return ltrim(
            (string) $username,
            '@'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Получить ссылку на чат
    |--------------------------------------------------------------------------
    */

    public function getLink(object $chat): ?string
    {
        $username = $this->getUsername($chat);

        if (!$username) {
            return null;
        }

        return "https://t.me/{$username}";
    }
}