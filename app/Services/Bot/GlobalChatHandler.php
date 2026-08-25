<?php

namespace App\Services\Bot;

use App\Models\ChatMessage;
use App\Models\TelegramUser;
use Telegram\Bot\Api;

class GlobalChatHandler
{
    public function handle($message, Api $telegram): bool
    {
        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        $telegramUserId = $message->from->id ?? null;

        if (!$telegramUserId) {
            return false;
        }

        $user = TelegramUser::where(
            'telegram_id',
            $telegramUserId
        )->first();

        if (!$user) {
            return false;
        }

        $chatMessage = ChatMessage::create([
            'telegram_user_id' => $user->id,
            'message' => $text,
        ]);

        $authorName = $user->username
            ? '@' . $user->username
            : $user->first_name;

        $chatText =
            $authorName . ":\n" .
            $chatMessage->message;

        $users = TelegramUser::where(
            'telegram_id',
            '!=',
            $user->telegram_id
        )->get();

        foreach ($users as $recipient) {
            try {
                $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $chatText,
                ]);
            } catch (\Throwable $e) {
                \Log::warning(
                    'Global chat send error',
                    [
                        'telegram_user_id' => $recipient->id,
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        return true;
    }
}