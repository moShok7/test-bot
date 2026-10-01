<?php

namespace App\Services\Clan;

use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\ClanMute;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ClanService
{
    /**
     * Основной чат закрытого тестирования кланов.
     */
    private const MAIN_CHAT_ID = -1004344778682;

    /*
    |--------------------------------------------------------------------------
    | Clan
    |--------------------------------------------------------------------------
    */

    /**
     * Получить клан по ID создателя.
     */
    public function getClanByCreator(int $userId): ?Clan
    {
        return Clan::query()
            ->forCreator($userId)
            ->first();
    }

    /**
     * Получить активный клан по Telegram chat ID.
     */
    public function getClanByChatId(int $chatId): ?Clan
    {
        return Clan::query()
            ->active()
            ->forChat($chatId)
            ->first();
    }

    /**
     * Создать клан.
     */
    public function createClan(
        int $creatorId,
        ?string $creatorUsername,
        string $name,
        int $chatId,
        ?string $chatUsername = null,
        ?string $chatLink = null,
        int $memberCount = 0,
    ): Clan {
        return DB::transaction(function () use (
            $creatorId,
            $creatorUsername,
            $name,
            $chatId,
            $chatUsername,
            $chatLink,
            $memberCount,
        ) {
            if ($this->getClanByCreator($creatorId)) {
                throw new RuntimeException(
                    'У вас уже есть зарегистрированный клан.'
                );
            }

            if ($this->getClanByChatId($chatId)) {
                throw new RuntimeException(
                    'Этот Telegram-чат уже зарегистрирован за другим кланом.'
                );
            }

            $clan = Clan::query()->create([
                'name' => $name,
                'creator_id' => $creatorId,
                'creator_username' => $creatorUsername,
                'chat_id' => $chatId,
                'chat_username' => $chatUsername,
                'chat_link' => $chatLink,
                'member_count' => $memberCount,
                'member_count_updated_at' => now(),
                'status' => 'active',
            ]);

            // Создатель автоматически становится участником своего клана.
            ClanMember::query()->create([
                'clan_id' => $clan->id,
                'user_id' => $creatorId,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $clan->fresh();
        });
    }

    /**
     * Проверить, является ли пользователь главой клана.
     */
    public function isLeader(Clan $clan, int $userId): bool
    {
        return $clan->creator_id === $userId;
    }

    /**
     * Проверить, относится ли чат к основному тестовому чату.
     */
    public function isMainChat(int $chatId): bool
    {
        return $chatId === self::MAIN_CHAT_ID;
    }

    /*
    |--------------------------------------------------------------------------
    | Members
    |--------------------------------------------------------------------------
    */

    /**
     * Проверить активное членство пользователя в клане.
     */
    public function isMember(int $clanId, int $userId): bool
    {
        return ClanMember::query()
            ->forClan($clanId)
            ->forUser($userId)
            ->active()
            ->exists();
    }

    /**
     * Получить активного участника клана.
     */
    public function getMember(
        int $clanId,
        int $userId,
    ): ?ClanMember {
        return ClanMember::query()
            ->forClan($clanId)
            ->forUser($userId)
            ->active()
            ->first();
    }

    /**
     * Получить активных участников клана.
     */
    public function getMembers(Clan $clan)
    {
        return $clan->members()
            ->active()
            ->orderBy('joined_at')
            ->get();
    }

    /**
     * Вступить в клан.
     */
    public function joinClan(
        Clan $clan,
        int $userId,
    ): ClanMember {
        if (!$clan->isActive()) {
            throw new RuntimeException(
                'Этот клан сейчас недоступен.'
            );
        }

        $existing = ClanMember::query()
            ->forClan($clan->id)
            ->forUser($userId)
            ->first();

        if ($existing?->isActive()) {
            throw new RuntimeException(
                'Вы уже состоите в этом клане.'
            );
        }

        if ($existing) {
            $existing->update([
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $existing->fresh();
        }

        return ClanMember::query()->create([
            'clan_id' => $clan->id,
            'user_id' => $userId,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Invites
    |--------------------------------------------------------------------------
    */

    /**
     * Создать приглашение в клан.
     */
    public function createInvite(
        Clan $clan,
        int $createdBy,
        ?int $maxUses = null,
        ?DateTimeInterface $expiresAt = null,
    ): ClanInvite {
        if (!$this->isLeader($clan, $createdBy)) {
            throw new RuntimeException(
                'Создавать приглашения может только глава клана.'
            );
        }

        do {
            $token = Str::upper(Str::random(10));
        } while (
            ClanInvite::query()
                ->where('token', $token)
                ->exists()
        );

        return ClanInvite::query()->create([
            'clan_id' => $clan->id,
            'token' => $token,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'uses' => 0,
            'status' => 'active',
        ]);
    }

    /**
     * Найти действительное приглашение.
     */
    public function getValidInvite(
        string $token,
    ): ?ClanInvite {
        return ClanInvite::query()
            ->byToken($token)
            ->valid()
            ->with('clan')
            ->first();
    }

    /**
     * Использовать приглашение.
     */
    public function useInvite(
        ClanInvite $invite,
        int $userId,
    ): ClanMember {
        return DB::transaction(function () use (
            $invite,
            $userId,
        ) {
            $invite = ClanInvite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$invite->isValid()) {
                throw new RuntimeException(
                    'Это приглашение недействительно или уже использовано.'
                );
            }

            $clan = $invite->clan;

            $member = $this->joinClan(
                $clan,
                $userId
            );

            $invite->increment('uses');

            if (
                $invite->max_uses !== null
                && ($invite->uses + 1) >= $invite->max_uses
            ) {
                $invite->update([
                    'status' => 'disabled',
                ]);
            }

            return $member;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Mutes
    |--------------------------------------------------------------------------
    */

    /**
     * Выдать мут участнику своего клана.
     */
    public function mute(
        Clan $clan,
        int $targetUserId,
        int $mutedBy,
        DateTimeInterface $expiresAt,
    ): ClanMute {
        if (!$this->isLeader($clan, $mutedBy)) {
            throw new RuntimeException(
                'Выдавать мут может только глава клана.'
            );
        }

        if (!$this->isMember($clan->id, $targetUserId)) {
            throw new RuntimeException(
                'Пользователь не является участником вашего клана.'
            );
        }

        if ($targetUserId === $mutedBy) {
            throw new RuntimeException(
                'Нельзя замутить самого себя.'
            );
        }

        return ClanMute::query()->updateOrCreate(
            [
                'clan_id' => $clan->id,
                'user_id' => $targetUserId,
            ],
            [
                'muted_by' => $mutedBy,
                'expires_at' => $expiresAt,
            ],
        );
    }

    /**
     * Снять мут с участника.
     */
    public function unmute(
        Clan $clan,
        int $targetUserId,
        int $unmutedBy,
    ): bool {
        if (!$this->isLeader($clan, $unmutedBy)) {
            throw new RuntimeException(
                'Снимать мут может только глава клана.'
            );
        }

        return ClanMute::query()
            ->forClan($clan->id)
            ->forUser($targetUserId)
            ->delete() > 0;
    }

    /**
     * Проверить действующий мут.
     */
    public function isMuted(
        int $clanId,
        int $userId,
    ): bool {
        return ClanMute::query()
            ->forClan($clanId)
            ->forUser($userId)
            ->active()
            ->exists();
    }

    /**
     * Получить действующий мут.
     */
    public function getActiveMute(
        int $clanId,
        int $userId,
    ): ?ClanMute {
        return ClanMute::query()
            ->forClan($clanId)
            ->forUser($userId)
            ->active()
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Clan list
    |--------------------------------------------------------------------------
    */

    /**
     * Получить список активных кланов.
     */
    public function getActiveClans(
        int $perPage = 10,
    ): LengthAwarePaginator {
        return Clan::query()
            ->active()
            ->orderByDesc('member_count')
            ->paginate($perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Main chat
    |--------------------------------------------------------------------------
    */

    /**
     * ID основного чата.
     */
    public function getMainChatId(): int
    {
        return self::MAIN_CHAT_ID;
    }
}