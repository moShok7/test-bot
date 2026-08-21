<?php

namespace App\Services\Bot;

use App\Services\Bolt;

class BoltHandler
{
    public function handle($message, $telegram): bool
    {
        $bolt = app(Bolt::class);

        $data = $bolt->generate();

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => $data['text'],
            'reply_markup' => json_encode($data['reply_markup']),
        ]);

        return true;
    }
}