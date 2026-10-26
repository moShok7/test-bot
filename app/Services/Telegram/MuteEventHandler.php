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
     * Обработка команд администраторов,
     * ответивших на сообщение владельца аккаунта.
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

            $message->reply('Мут снят. Владелец снова может писать.');
            return;
        }

        // Примеры: мут 30 минут, мут 2 часа, мут 1 день.
        if (!preg_match(
            '/^мут\s+(\d+)\s+(минут(?:у|ы|а)?|час(?:а|ов)?|д(?:ень|ня|ней))[!.]?$/u',
            $text,
            $matches
        )) {
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
            "🔇 Владелец замучен!\n"
            . "Его сообщения будут автоматически удаляться до "
            . $until->format('d.m.Y H:i')
            . '.'
        );
    }

    /**
     * Удалять исходящие сообщения владельца во время активного мута.
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
                'MuteEventHandler: удалено сообщение '
                . $message->id
                . ' во время мута.'
            );
        } catch (\Throwable $e) {
            $this->logger(
                'MuteEventHandler delete error: ' . $e->getMessage()
            );
        }
    }
}