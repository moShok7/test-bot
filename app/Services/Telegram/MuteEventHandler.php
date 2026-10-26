<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramUserMute;
use Carbon\Carbon;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\EventHandler\SimpleFilter\FromAdmin;
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
     * Команды администратора в ответ на сообщение владельца аккаунта.
     */
    #[Handler]
    public function handleMuteCommand(
        Incoming&Message&FromAdmin&IsReply $message
    ): void {
        $text = mb_strtolower(trim($message->message));

        $this->logger(
            'MUTE COMMAND DEBUG: chat=' . $message->chatId
            . ', sender=' . $message->senderId
            . ', text=' . $text
        );

        if ((int) $message->chatId !== self::CHAT_ID) {
            return;
        }

        $reply = $message->getReply(Message::class);

        if (!$reply || (int) $reply->senderId !== $this->ownId()) {
            $this->logger('MUTE COMMAND DEBUG: ответ не на сообщение владельца');
            return;
        }

        // Снять мут: "снять мут" или "размутить".
        if (preg_match('/^(?:снять\s+мут|размутить)[!.]?$/u', $text)) {
            TelegramUserMute::query()
                ->where('chat_id', self::CHAT_ID)
                ->where('user_id', $this->ownId())
                ->delete();

            $this->logger('MUTE COMMAND DEBUG: мут снят');
            $message->reply('🔊 Мут снят. Теперь можно писать.');
            return;
        }

        // Поддерживаем: мут 30 минут, мут 2 часа, мут 1 день.
        if (!preg_match(
            '/^мут\s+(\d+)\s+(минут(?:у|ы|а|ой)?|час(?:а|ов)?|д(?:ень|ня|ней))[!.]?$/u',
            $text,
            $matches
        )) {
            $this->logger('MUTE COMMAND DEBUG: неизвестная команда');
            return;
        }

        $amount = (int) $matches[1];
        $unit = $matches[2];

        if ($amount < 1 || $amount > 30) {
            $message->reply('Укажи срок от 1 до 30. Например: мут 2 часа.');
            return;
        }

        if (str_starts_with($unit, 'минут')) {
            $until = Carbon::now()->addMinutes($amount);
        } elseif (str_starts_with($unit, 'час')) {
            $until = Carbon::now()->addHours($amount);
        } else {
            $until = Carbon::now()->addDays($amount);
        }

        try {
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

            $this->logger('MUTE COMMAND DEBUG: мут сохранён до ' . $until);

            $message->reply(
                "🔇 Мут активирован!\n"
                . "Сообщения владельца будут удаляться до "
                . $until->format('d.m.Y H:i') . '.'
            );
        } catch (\Throwable $e) {
            $this->logger('MUTE COMMAND ERROR: ' . $e->getMessage());
            $message->reply('Ошибка при сохранении мута. Проверь логи сервера.');
        }
    }

    /**
     * Удаление исходящих сообщений владельца во время активного мута.
     */
    #[Handler]
    public function deleteMutedMessages(Message $message): void
    {
        if (!$message->out || (int) $message->chatId !== self::CHAT_ID) {
            return;
        }

        try {
            $mute = TelegramUserMute::query()
                ->where('chat_id', self::CHAT_ID)
                ->where('user_id', $this->ownId())
                ->where('muted_until', '>', now())
                ->first();

            if (!$mute) {
                return;
            }

            $this->channels->deleteMessages(
                channel: self::CHAT_ID,
                id: [$message->id],
            );

            $this->logger(
                'MUTE DEBUG: запрос удаления сообщения ' . $message->id
            );
        } catch (\Throwable $e) {
            $this->logger('MUTE DELETE ERROR: ' . $e->getMessage());
        }
    }
}
