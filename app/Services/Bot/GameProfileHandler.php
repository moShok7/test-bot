<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;
use App\Models\GameProfile;
use App\Models\BotSession;
use Telegram\Bot\FileUpload\InputFile;

class GameProfileHandler
{

    public function handle($message, $telegram): bool
    {

        $text = $message->text ?? '';
        $telegramId = $message->from->id ?? null;


        if (!$telegramId) {
            return false;
        }


        $user = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();


        if (!$user) {
            return false;
        }


        $chatId = $message->chat->id;



        /*
        |--------------------------------------------------------------------------
        | Изменение профиля
        |--------------------------------------------------------------------------
        */


        if ($text === '✏️ Изменить профиль') {


            BotSession::updateOrCreate(

                [
                    'telegram_user_id' => $user->id
                ],

                [
                    'step' => 'edit_nickname',
                    'temp_game_nickname' => null,
                    'temp_game_id' => null
                ]

            );


            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "✏️ Введите новый ник в игре:"

            ]);


            return true;

        }




        /*
        |--------------------------------------------------------------------------
        | Отмена заявки
        |--------------------------------------------------------------------------
        */


        if ($text === '❌ Отменить заявку') {


            GameProfile::where(
                'telegram_user_id',
                $user->id
            )
            ->where(
                'verified',
                false
            )
            ->delete();



            BotSession::where(
                'telegram_user_id',
                $user->id
            )->delete();



            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "🗑 Заявка отменена.\n\n".
                    "Теперь можете привязать аккаунт заново."

            ]);


            return true;

        }




        /*
        |--------------------------------------------------------------------------
        | Начало привязки аккаунта
        |--------------------------------------------------------------------------
        */


        if ($text === '🔗 Привязать игровой аккаунт') {



            $existingProfile = GameProfile::where(
                'telegram_user_id',
                $user->id
            )->first();



            if ($existingProfile) {



                if ($existingProfile->verified) {


                    $telegram->sendMessage([

                        'chat_id' => $chatId,

                        'text' =>
                            "✅ У вас уже есть подтверждённый профиль.\n\n".
                            "🎮 Ник: {$existingProfile->game_nickname}\n".
                            "🆔 ID: {$existingProfile->game_id}"

                    ]);


                } else {


                    $telegram->sendMessage([

                        'chat_id' => $chatId,

                        'text' =>
                            "⏳ Ваша заявка ожидает проверки.\n\n".
                            "🎮 Ник: {$existingProfile->game_nickname}\n".
                            "🆔 ID: {$existingProfile->game_id}\n\n".
                            "Если нашли ошибку, можете изменить данные.",



                        'reply_markup' => json_encode([

                            'keyboard' => [

                                [
                                    [
                                        'text' => '✏️ Изменить профиль'
                                    ]
                                ],

                                [
                                    [
                                        'text' => '❌ Отменить заявку'
                                    ]
                                ]

                            ],

                            'resize_keyboard' => true

                        ])

                    ]);

                }


                return true;

            }




            BotSession::updateOrCreate(

                [
                    'telegram_user_id' => $user->id
                ],

                [
                    'step' => 'nickname',
                    'temp_game_nickname' => null,
                    'temp_game_id' => null
                ]

            );



            $telegram->sendPhoto([

                'chat_id' => $chatId,

                'photo' => InputFile::create(
                    public_path('images/ник.png')
                ),

                'caption' =>
                    "📷 Пример ника игрока.\n\n".
                    "Введите свой ник так, как он указан в игре."

            ]);



            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "🎮 Введите ваш ник в игре:"

            ]);



            return true;

        }
                /*
        |--------------------------------------------------------------------------
        | Получение текущей сессии
        |--------------------------------------------------------------------------
        */


        $session = BotSession::where(
            'telegram_user_id',
            $user->id
        )->first();



        if (!$session) {
            return false;
        }




        /*
        |--------------------------------------------------------------------------
        | Изменение ника
        |--------------------------------------------------------------------------
        */


        if ($session->step === 'edit_nickname') {



            $session->update([

                'temp_game_nickname' => $text,

                'step' => 'edit_game_id'

            ]);



            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "🆔 Введите новый ID игрока:"

            ]);



            return true;

        }




        /*
        |--------------------------------------------------------------------------
        | Изменение ID
        |--------------------------------------------------------------------------
        */


        if ($session->step === 'edit_game_id') {



            $exists = GameProfile::where(

                'game_id',

                $text

            )
            ->where(

                'telegram_user_id',

                '!=',

                $user->id

            )
            ->exists();



            if ($exists) {


                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' =>
                        "❌ Этот игровой ID уже привязан к другому аккаунту."

                ]);


                return true;

            }




            $session->update([

                'temp_game_id' => $text,

                'step' => 'edit_screenshot'

            ]);



            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "📸 Теперь отправьте новый скриншот профиля."

            ]);



            return true;

        }





        /*
        |--------------------------------------------------------------------------
        | Обычный ввод ника
        |--------------------------------------------------------------------------
        */


        if ($session->step === 'nickname') {



            $session->update([

                'temp_game_nickname' => $text,

                'step' => 'game_id'

            ]);



            $telegram->sendPhoto([

                'chat_id' => $chatId,

                'photo' => InputFile::create(

                    public_path('images/id.png')

                ),

                'caption' =>
                    "Пример ID игрока.\n\n".
                    "Найдите свой ID и отправьте его."

            ]);



            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "🆔 Теперь введите ваш ID игрока:"

            ]);



            return true;

        }




        /*
        |--------------------------------------------------------------------------
        | Обычный ввод ID
        |--------------------------------------------------------------------------
        */


        if ($session->step === 'game_id') {



            $exists = GameProfile::where(

                'game_id',

                $text

            )->exists();



            if ($exists) {


                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' =>
                        "❌ Этот игровой ID уже используется."

                ]);


                $session->delete();


                return true;

            }




            $session->update([

                'temp_game_id' => $text,

                'step' => 'screenshot'

            ]);




            $telegram->sendPhoto([

                'chat_id' => $chatId,

                'photo' => InputFile::create(

                    public_path('images/full.png')

                ),

                'caption' =>
                    "📸 Теперь отправьте полный скриншот профиля.\n\n".
                    "На нём должно быть видно:\n".
                    "✅ Ник игрока\n".
                    "✅ ID игрока"

            ]);



            return true;

        }        /*
        |--------------------------------------------------------------------------
        | Новый скриншот при редактировании
        |--------------------------------------------------------------------------
        */


        if ($session->step === 'edit_screenshot') {



            if (!isset($message->photo)) {


                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' =>
                        "❌ Отправьте именно изображение."

                ]);


                return true;

            }




            $photo = $message->photo->last();




            $profile = GameProfile::where(

                'telegram_user_id',

                $user->id

            )
            ->where(

                'verified',

                false

            )
            ->first();



            if (!$profile) {


                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' =>
                        "❌ Профиль для изменения не найден."

                ]);


                $session->delete();


                return true;

            }




            $profile->update([

                'game_nickname' =>
                    $session->temp_game_nickname,

                'game_id' =>
                    $session->temp_game_id,

                'screenshot' =>
                    $photo->file_id

            ]);





            $nickname = $profile->game_nickname;

            $gameId = $profile->game_id;




            $session->delete();




            $telegram->sendMessage([

                'chat_id' => $chatId,

                'text' =>
                    "✅ Профиль обновлён!\n\n".
                    "🎮 Ник: {$nickname}\n".
                    "🆔 ID: {$gameId}\n\n".
                    "Ожидайте повторной проверки администратора."

            ]);



            return true;

        }





        /*
        |--------------------------------------------------------------------------
        | Первый скриншот привязки
        |--------------------------------------------------------------------------
        */


        if ($session->step === 'screenshot') {



            if (!isset($message->photo)) {


                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' =>
                        "❌ Отправьте именно изображение."

                ]);


                return true;

            }




            $photo = $message->photo->last();





            $gameExists = GameProfile::where(

                'game_id',

                $session->temp_game_id

            )->exists();





            if ($gameExists) {



                $telegram->sendMessage([

                    'chat_id' => $chatId,

                    'text' =>
                        "❌ Этот игровой ID уже привязан."

                ]);



                $session->delete();


                return true;

            }





            GameProfile::create([

                'telegram_user_id' =>
                    $user->id,

                'game_nickname' =>
                    $session->temp_game_nickname,

                'game_id' =>
                    $session->temp_game_id,

                'screenshot' =>
                    $photo->file_id,

                'verified' =>
                    false

            ]);





            $nickname =
                $session->temp_game_nickname;


            $gameId =
                $session->temp_game_id;





            $session->delete();





            $telegram->sendMessage([

    'chat_id' => $chatId,

    'text' =>
        "✅ Заявка отправлена на проверку!\n\n".
        "🎮 Ник: {$nickname}\n".
        "🆔 ID: {$gameId}\n\n".
        "Ожидайте подтверждения администратора.",


    'reply_markup' => json_encode([

        'keyboard' => [

            [
                [
                    'text' => '✏️ Изменить профиль'
                ]
            ],

            [
                [
                    'text' => '❌ Отменить заявку'
                ]
            ]

        ],

        'resize_keyboard' => true

    ])

]);



            return true;

        }





        return false;


    }

}