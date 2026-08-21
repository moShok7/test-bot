<?php

namespace App\Services\Bot;

use App\Models\BotAdmin;
use App\Models\GameProfile;

class AdminHandler
{

    public function handle($message, $telegram): bool
    {

        $text = $message->text ?? '';

        $chatId = $message->chat->id;

        $telegramId = $message->from->id;


        $isAdmin = BotAdmin::where(
            'telegram_id',
            $telegramId
        )->exists();


        if (!$isAdmin) {
            return false;
        }



        /*
        |--------------------------------------------------------------------------
        | Просмотр заявок
        |--------------------------------------------------------------------------
        */


        if ($text === '/profiles') {


            $profiles = GameProfile::where(
                'verified',
                false
            )->get();



            if ($profiles->isEmpty()) {

                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' => '📭 Новых заявок нет.'

                ]);

                return true;
            }




            foreach ($profiles as $profile) {


                $telegram->sendPhoto([


                    'chat_id' => $chatId,


                    'photo' => $profile->screenshot,


                    'caption' =>
                        "📝 Заявка #{$profile->id}\n\n".
                        "🎮 Ник: {$profile->game_nickname}\n".
                        "🆔 ID: {$profile->game_id}\n\n".
                        "Выберите действие:",



                    'reply_markup' => json_encode([

                        'inline_keyboard' => [

                            [

                                [
                                    'text' => '✅ Подтвердить',
                                    'callback_data' => 'approve_'.$profile->id
                                ],

                                [
                                    'text' => '❌ Отклонить',
                                    'callback_data' => 'reject_'.$profile->id
                                ]

                            ]

                        ]

                    ])

                ]);

            }



            return true;

        }



        return false;

    }






    /*
    |--------------------------------------------------------------------------
    | Обработка кнопок
    |--------------------------------------------------------------------------
    */


    public function handleCallback($callback, $telegram): bool
    {


        $data = $callback->data;


        $adminChatId = $callback->message->chat->id;




        /*
        |--------------------------------------------------------------------------
        | Подтверждение
        |--------------------------------------------------------------------------
        */


        if (str_starts_with($data, 'approve_')) {


            $id = str_replace(
                'approve_',
                '',
                $data
            );



            $profile = GameProfile::find($id);



            if (!$profile) {

                return false;

            }



            $profile->update([

                'verified' => true

            ]);




            // Сообщение игроку

            // Сообщение игроку
if ($profile->telegramUser) {

    $telegram->sendMessage([

        'chat_id' => $profile->telegramUser->telegram_id,

        'text' =>
            "🎉 Ваш игровой профиль подтверждён!\n\n".
            "🎮 Ник: {$profile->game_nickname}\n".
            "🆔 ID: {$profile->game_id}\n\n".
            "Теперь вы можете создавать и искать лобби YkSUS.",


    'reply_markup' => json_encode([

    'keyboard' => [

        [
            [
                'text'=>'➕ Создать лобби'
            ],
            [
                'text'=>'🔍 Найти лобби'
            ]
        ],

        [
            [
                'text'=>'🎮 Моё лобби'
            ]
        ]

    ],

    'resize_keyboard'=>true

])

    ]);

}




            // Сообщение админу

            $telegram->sendMessage([

                'chat_id' => $adminChatId,


                'text' =>
                    "✅ Игрок подтверждён!\n\n".
                    "🎮 Ник: {$profile->game_nickname}\n".
                    "🆔 ID: {$profile->game_id}"

            ]);



            return true;

        }







        /*
        |--------------------------------------------------------------------------
        | Отклонение
        |--------------------------------------------------------------------------
        */


        if (str_starts_with($data, 'reject_')) {


            $id = str_replace(
                'reject_',
                '',
                $data
            );



            $profile = GameProfile::find($id);



            if (!$profile) {

                return false;

            }



            // Уведомляем игрока перед удалением

            if ($profile->telegramUser) {


                $telegram->sendMessage([


                    'chat_id' => $profile->telegramUser->telegram_id,


                    'text' =>
                        "❌ Ваш игровой профиль не был подтверждён.\n\n".
                        "🎮 Ник: {$profile->game_nickname}\n".
                        "🆔 ID: {$profile->game_id}\n\n".
                        "Вы можете отправить заявку повторно."

                ]);

            }





            // Удаляем заявку

            $profile->delete();






            // Сообщаем админу

            $telegram->sendMessage([


                'chat_id' => $adminChatId,


                'text' =>
                    "❌ Заявка отклонена."

            ]);



            return true;

        }





        return false;

    }

}