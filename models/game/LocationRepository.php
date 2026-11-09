<?php

namespace app\models\game;

use Yii;

class LocationRepository
{
    private static ?array $data = null;
    /** @var array<string, Location> */
    private static array $instances = [];

    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/locations.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    public static function find(string $id): ?Location
    {
        self::init();

        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        if (!isset(self::$data[$id])) {
            return null;
        }

        $row = self::$data[$id];

        $loc = new Location(
            id: (string)($row['id'] ?? $id),
            regionId: (string)($row['region_id'] ?? 'talking_island'),
            respawnId: (string)($row['respawn_id'] ?? $id),
            type: (string)($row['type'] ?? 'field'),
            isPeace: (bool)($row['is_peace'] ?? false),
            minLevel: (int)($row['min_level'] ?? 1),
            maxLevel: (int)($row['max_level'] ?? 1),
            connected: $row['connected'] ?? [],
            teleports: $row['teleports'] ?? [],
            npcs: $row['npcs'] ?? [],
            monsters: $row['monsters'] ?? []
        );

        self::$instances[$id] = $loc;
        return $loc;
    }

    /**
     * Все локации мира
     * @return Location[]
     */
    public static function all(): array
    {
        self::init();

        if (empty(self::$data)) {
            return [];
        }

        $all = [];
        foreach (array_keys(self::$data) as $id) {
            $loc = self::find((string)$id);
            if ($loc !== null) {
                $all[$id] = $loc;
            }
        }

        return $all;
    }

    /**
     * Получение всех локаций, сгруппированных по регионам
     * @return array<string, array{name: string, locations: Location[]}>
     */
    public static function getGroupedByRegion(): array
    {
        self::init();
        $grouped = [];

        foreach (self::$data as $id => $row) {
            $loc = self::find((string)$id);
            if (!$loc) continue;

            $regId = $loc->regionId;
            if (!isset($grouped[$regId])) {
                $grouped[$regId] = [
                    'name' => $loc->getRegionName(),
                    'locations' => [],
                ];
            }
            $grouped[$regId]['locations'][] = $loc;
        }

        return $grouped;
    }

    /**
     * Расчет стоимости телепорта по Карте Мира (по канону Essence)
     */
    public static function calculateTeleportCost(Location $targetLoc, ?Location $currentLoc = null): int
    {
        // 1. Если задана фиксированная цена у гейткипера
        if ($currentLoc && isset($currentLoc->teleports[$targetLoc->id])) {
            return (int)$currentLoc->teleports[$targetLoc->id];
        }

        // 2. В пределах одной стартовой зоны новичков (до 20 ур.) телепорт бесплатный или символический
        if ($targetLoc->maxLevel <= 20) {
            return 100;
        }

        // 3. Динамическая стоимость от уровня локации: minLevel * 80 адены
        $base = max(1, $targetLoc->minLevel);
        $cost = (int)round($base * 85);

        // Города имеют фиксированный межрегиональный тариф
        if ($targetLoc->isTown()) {
            return 3000;
        }

        return max(300, $cost);
    }
}
