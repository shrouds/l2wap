<?php

namespace app\models\game;

use Yii;
use yii\helpers\ArrayHelper;

class Location
{
    private static array $messagesCache = [];

    public function __construct(
        public string $id,
        public string $regionId,
        public string $respawnId,
        public string $type,
        public bool   $isPeace,
        public int    $minLevel,
        public int    $maxLevel,
        public array  $connected = [],
        public array  $teleports = [],
        public array  $npcs = [],
        public array  $monsters = [] // [monster_id => weight]
    ) {}

    public function isTown(): bool
    {
        return $this->type === 'town';
    }

    public function isArena(): bool
    {
        return $this->type === 'arena';
    }

    public function getRespawnTownId(): string
    {
        return $this->respawnId;
    }

    public function getName(?string $lang = null): string
    {
        $messages = self::getMessages($lang);
        return $messages['locations'][$this->id]['name'] ?? $this->id;
    }

    public function getDescription(?string $lang = null): string
    {
        $messages = self::getMessages($lang);
        return $messages['locations'][$this->id]['desc'] ?? '';
    }

    public function getRegionName(?string $lang = null): string
    {
        $messages = self::getMessages($lang);
        return $messages['regions'][$this->regionId] ?? $this->regionId;
    }

    /**
     * БЫСТРАЯ ОХОТА (1 клик): Случайный моб с учетом его популяции (весов)
     * Исключает рейд-боссов из случайных встреч!
     */
    public function getRandomMonster(): ?Npc
    {
        $pool = [];
        $totalWeight = 0;

        foreach ($this->monsters as $mId => $weight) {
            $npc = NpcRepository::find((int)$mId);
            // Пропускаем боссов — на них нельзя наткнуться случайно
            if ($npc === null || $npc->isBoss()) {
                continue;
            }

            $weight = max(1, (int)$weight);
            $pool[$npc->id] = $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight === 0) {
            return null;
        }

        // Алгоритм «рулетки» (взвешенный случайный выбор)
        $rand = mt_rand(1, $totalWeight);
        foreach ($pool as $npcId => $weight) {
            $rand -= $weight;
            if ($rand <= 0) {
                return NpcRepository::find($npcId);
            }
        }

        return null;
    }

    /**
     * ТОЧЕЧНАЯ ОХОТА: Компактный список уникальных обычных мобов (без дубликатов и без боссов)
     * @return Npc[]
     */
    public function getRegularMonsters(): array
    {
        $list = [];
        foreach (array_keys($this->monsters) as $mId) {
            $npc = NpcRepository::find((int)$mId);
            if ($npc !== null && !$npc->isBoss()) {
                $list[] = $npc;
            }
        }
        return $list;
    }

    /**
     * СПИСОК РЕЙД-БОССОВ ЛОКАЦИИ (если есть в этой зоне)
     * @return Npc[]
     */
    public function getBosses(): array
    {
        $bosses = [];
        foreach (array_keys($this->monsters) as $mId) {
            $npc = NpcRepository::find((int)$mId);
            if ($npc !== null && $npc->isBoss()) {
                $bosses[] = $npc;
            }
        }
        return $bosses;
    }

    public function issetNpcInLocation(int $npc_id): bool
    {
        $npc = NpcRepository::find((int)$npc_id);
        return ArrayHelper::isIn($npc->id, $this->npcs);
    }

    public function issetMonsterInLocation($monster_id): bool
    {
        $npc = NpcRepository::find((int)$monster_id);
        return ArrayHelper::isIn($npc->id, $this->monsters);
    }

    public function hasMonster(int $monsterId): bool
    {
        return isset($this->monsters[$monsterId]);
    }

    public function isConnectedTo(string $targetLocationId): bool
    {
        return in_array($targetLocationId, $this->connected, true);
    }

    public function hasTeleportTo(string $targetLocationId): bool
    {
        return isset($this->teleports[$targetLocationId]);
    }

    public function getTeleportCost(string $targetLocationId): ?int
    {
        return $this->teleports[$targetLocationId] ?? null;
    }

    private static function getMessages(?string $lang = null): array
    {
        $lang = $lang ?? Yii::$app->language;

        if (!isset(self::$messagesCache[$lang])) {
            $path = Yii::getAlias("@app/messages/{$lang}/locations.php");
            self::$messagesCache[$lang] = file_exists($path) ? require $path : [];
        }

        return self::$messagesCache[$lang];
    }
}
