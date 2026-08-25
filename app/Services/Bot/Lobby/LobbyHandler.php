<?php

namespace App\Services\Bot\Lobby;

class LobbyHandler
{
    protected CreateLobbyHandler $createLobbyHandler;
    protected SearchLobbyHandler $searchLobbyHandler;
    protected HostLobbyHandler $hostLobbyHandler;
    protected HostActionHandler $hostActionHandler;
    protected JoinLobbyHandler $joinLobbyHandler;
    protected LeaveLobbyHandler $leaveLobbyHandler;
    protected PlayersHandler $playersHandler;
    protected KickPlayerHandler $kickPlayerHandler;

    public function __construct(LobbyService $lobbyService)
    {
        $this->createLobbyHandler = new CreateLobbyHandler();
        $this->searchLobbyHandler = new SearchLobbyHandler();
        $this->hostLobbyHandler = new HostLobbyHandler();
        $this->hostActionHandler = new HostActionHandler();
        $this->playersHandler = new PlayersHandler();
        $this->joinLobbyHandler = new JoinLobbyHandler();

        $this->leaveLobbyHandler = new LeaveLobbyHandler(
            $lobbyService
        );

        $this->kickPlayerHandler = new KickPlayerHandler();
    }

    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        /*
        |--------------------------------------------------------------------------
        | Поиск лобби
        |--------------------------------------------------------------------------
        */

        if (
            $text === '🔍 Найти лобби' ||
            $text === '🔄 Обновить поиск'
        ) {
            return $this->searchLobbyHandler->handle(
                $message,
                $telegram
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Остальные обработчики
        |--------------------------------------------------------------------------
        */

        if ($this->joinLobbyHandler->handle($message, $telegram)) {
            return true;
        }

        if ($this->playersHandler->handle($message, $telegram)) {
            return true;
        }

        if ($this->leaveLobbyHandler->handle($message, $telegram)) {
            return true;
        }

        if ($this->kickPlayerHandler->handle($message, $telegram)) {
            return true;
        }

        if ($this->hostActionHandler->handle($message, $telegram)) {
            return true;
        }

        if ($this->hostLobbyHandler->handle($message, $telegram)) {
            return true;
        }

        if ($this->createLobbyHandler->handle($message, $telegram)) {
            return true;
        }

        return false;
    }
}