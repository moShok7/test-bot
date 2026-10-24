<?php

namespace App\Telegram;

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
    private const CHAT_ID = -1004344778682;

    private ?int $selfId = null;

    private function ownId(): int
    {
        return $this->selfId ??= (int) $this->getSelf()['id'];
    }

    /**
     * Команды администраторов, отправленные ответом
     * на сообщение нашего аккаунта.
     */
    #[Handler]
    public function handleMuteCommand(
        Incoming&Message&FromAdmin&IsReply $message
    ): void {
        if ((int) $message->chatId !== self::CHAT_ID) {
            return;
        }

        $reply = $message->getReply(Message::class);

        if (!$reply || (int) $reply->senderId !== $this->ownId()) {
            return;
        }

        $text = mb_strtolower(trim($message->message));

        // Снять мут.
        if (preg_match('/^снять\s+мут[!.]?$/u', $text)) {
            TelegramUserMute::query()
                ->where('chat_id', self::CHAT_ID)
                ->where('user_id', $this->ownId())
                ->delete();

            $message->reply('Мут снят.');

            return;
        }

        // Примеры: мут 2 часа, мут 30 минут, мут 1 день.
        if (!preg_match(
            '/^мут\s+(\d+)\s+(минут(?:у|ы)?|час(?:а|ов)?|д(?:ень|ня|ней))[!.]?$/u',
            $text,
            $matches
        )) {
            return;
        }

        $amount = (int) $matches[1];
        $unit = $matches[2];

        if ($amount < 1 || $amount > 30) {
            $message->reply(
                'Укажи срок от 1 до 30 единиц: например, мут 2 часа.'
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
     * Удаление собственных сообщений во время активного мута.
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

        $mute = TelegramUserMute::query()
            ->where('chat_id', self::CHAT_ID)
            ->where('user_id', $this->ownId())
            ->where('muted_until', '>', now())
            ->first();

        if (!$mute) {
            return;
        }

        // Не удаляем команду администратора или чужие сообщения:
        // сюда попадают только исходящие сообщения аккаунта.
        $this->channels->deleteMessages(
            channel: self::CHAT_ID,
            id: [$message->id],
        );
    }
}