<?php

namespace App\Http\Controllers;
use Telegram\Bot\Api;
use App\Models\TelegramUser;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
       {
   
         $telegram = new Api(
    env('TELEGRAM_BOT_TOKEN')
);
   
   
           $update = $telegram->getWebhookUpdate();
   
   
           $message = $update->getMessage();
   
   
           if(!$message){
               return response()->json();
           }
   
   
           $user = $message->getFrom();
   
   
           if($message->getText() === '/start'){
   
               TelegramUser::updateOrCreate(
                   [
                       'telegram_id'=>$user->getId()
                   ],
                   [
                       'username'=>$user->getUsername(),
                       'first_name'=>$user->getFirstName()
                   ]
               );
   
   
               $telegram->sendMessage([
                   'chat_id'=>$message->getChat()->getId(),
                   'text'=>"🎮 Добро пожаловать!\nВаш аккаунт создан."
               ]);
   
           }
   
   
           return response()->json();
       }
}