<?php

namespace App\Telegram;

use App\Models\TelegramUserMute;
use Carbon\Carbon;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\EventHandler\SimpleFilter\IsReply;

final class MuteEventHandler extends SimpleEventHandler
{
    private const CHAT_ID = -1003793441686;

    private ?int $selfId = null;

    private function ownId(): int
    {
        return $this->selfId ??= (int) $this->getSelf()['id'];
    }

    /**
     * Обрабатывает команды администратора,
     * отправленные ответом на сообщение нашего аккаунта.
     */
    #[Handler]
    public function handleMuteCommand(
        Incoming&Message&IsReply $message
    ): void {
        if ((int) $message->chatId !== self::CHAT_ID) {
            return;
        }

        $text = mb_strtolower(trim($message->message));

        $isMute = preg_match(
            '/^мут\s+(\d+)\s+(минут(?:у|ы)?|час(?:а|ов)?|д(?:ень|ня|ней))[!.]?$/u',
            $text,
            $matches
        );

        $isUnmute = preg_match(
            '/^снять\s+мут[!.]?$/u',
            $text
        );

        if (!$isMute && !$isUnmute) {
            return;
        }

        // Команда должна быть ответом на сообщение нашего аккаунта.
        $reply = $message->getReply(Message::class);

        if (!$reply || (int) $reply->senderId !== $this->ownId()) {
            return;
        }

        // Проверяем права отправителя команды.
        try {
            $member = $this->getInfo($message->senderId);
            $senderId = (int) $message->senderId;

            $chat = $this->getInfo(self::CHAT_ID);
            $isAdmin = false;

            // Проверяем статус через информацию о чате.
            $participants =
                $chat['participants']['participants']
                ?? $chat['ChatParticipants']['participants']
                ?? [];

            foreach ($participants as $participant) {
                if (
                    (int) ($participant['user_id'] ?? 0) === $senderId
                    && in_array(
                        $participant['_'] ?? '',
                        [
                            'chatParticipantAdmin',
                            'chatParticipantCreator',
                        ],
                        true
                    )
                ) {
                    $isAdmin = true;
                    break;
                }
            }

            // В некоторых типах чатов данные участников
            // не возвращаются в getInfo(). В этом случае
            // не разрешаем команду без подтверждения прав.
            if (!$isAdmin) {
                $message->reply(
                    'Не удалось подтвердить права администратора.'
                );
                return;
            }
        } catch (\Throwable $e) {
            $message->reply(
                'Не удалось проверить права администратора.'
            );
            return;
        }

        // Снять мут.
        if ($isUnmute) {
            TelegramUserMute::query()
                ->where('chat_id', self::CHAT_ID)
                ->where('user_id', $this->ownId())
                ->delete();

            $message->reply('Мут снят.');
            return;
        }

        $amount = (int) $matches[1];
        $unit = $matches[2];

        if ($amount < 1 || $amount > 30) {
            $message->reply(
                'Укажи срок от 1 до 30 единиц. Например: мут 2 часа.'
            );
            return;
        }

        if (str_starts_with($unit, 'минут')) {
            $until = Carbon::now()->addMinutes($amount);
        } elseif (str_starts_with($unit, 'час')) {
            $until = Carbon::now()->addHours($amount);
        } else {
            $until = Carbon::now()->addDays($amount);
        }

        TelegramUserMute::query()->updateOrCreate(
            [
                'chat_id' => self::CHAT_ID,
                'user_id' => $this->ownId(),
            ],
            [
                'muted_by' => (int) $message->senderId,
                'muted_until' => $until,
            ]
        );

        $message->reply(
            'Мут включён до ' . $until->format('d.m.Y H:i') . '.'
        );
    }

    /**
     * Удаляет исходящие сообщения нашего аккаунта,
     * пока действует мут.
     */
    #[Handler]
    public function deleteMutedMessages(Message $message): void
    {
        if (!$message->out) {
            return;
        }

        if ((int) $message->chatId !== self::CHAT_ID) {
            return;
        }

        $muted = TelegramUserMute::query()
            ->where('chat_id', self::CHAT_ID)
            ->where('user_id', $this->ownId())
            ->where('muted_until', '>', now())
            ->exists();

        if (!$muted) {
            return;
        }

        try {
            $this->channels->deleteMessages(
                channel: self::CHAT_ID,
                id: [$message->id],
            );
        } catch (\Throwable $e) {
            $this->logger('Не удалось удалить сообщение во время мута: ' . $e->getMessage());
        }
    }
}
