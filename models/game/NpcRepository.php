<?php

namespace app\models\game;

use Yii;

class NpcRepository
{
    private static ?array $data = null;
    /** @var array<int, Npc> */
    private static array $instances = [];

    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/npcs.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    public static function find(int $id): ?Npc
    {
        self::init();

        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        if (!isset(self::$data[$id])) {
            return null;
        }

        $row = self::$data[$id];

        // Создаем коллекцию объектов DropItem для дропа
        $drops = [];
        foreach ($row['drops'] as $d) {
            $drops[] = new DropItem(
                itemId: $d['item_id'],
                systemName: $d['name'],
                min: $d['min'],
                max: $d['max'],
                chance: $d['chance'],
                isSpoil: false
            );
        }

        // Создаем коллекцию объектов DropItem для спойла
        $spoils = [];
        foreach ($row['spoils'] as $s) {
            $spoils[] = new DropItem(
                itemId: $s['item_id'],
                systemName: $s['name'],
                min: $s['min'],
                max: $s['max'],
                chance: $s['chance'],
                isSpoil: true
            );
        }

        $npc = new Npc(
            id: $id,
            systemName: $row['name'],
            type: $row['type'],
            level: $row['level'],
            hp: $row['hp'],
            mp: $row['mp'],
            pAtk: $row['p_atk'],
            mAtk: $row['m_atk'],
            pDef: $row['p_def'],
            mDef: $row['m_def'],
            exp: $row['exp'],
            sp: $row['sp'],
            hpRegen: $row['hp_regen'],
            mpRegen: $row['mp_regen'],
            attackSpeed: $row['spd'],
            critical: $row['critical'],
            drops: $drops,
            spoils: $spoils,
            skills: (array)($row['skills'] ?? [])
        );

        self::$instances[$id] = $npc;
        return $npc;
    }
}
