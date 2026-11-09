<?php

namespace app\models\game;

use Yii;

class ItemRepository
{
    private static ?array $data = null;
    /** @var array<int, Item> */
    private static array $instances = [];

    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/items.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    public static function find(int $id): ?Item
    {
        self::init();

        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        if (!isset(self::$data[$id])) {
            return null;
        }

        $row = self::$data[$id];

        $item = new Item(
            id: $id,
            systemName: $row['name'],
            type: $row['type'],
            slot: $row['slot'],
            weaponType: $row['weapon_type'],
            armorType: $row['armor_type'],
            material: $row['material'] ?? 'steel',
            grade: $row['grade'],
            isCrystallizable: (bool)($row['is_crystallizable'] ?? false),
            crystalCount: (int)($row['crystal_count'] ?? 0),
            pAtk: (int)($row['p_atk'] ?? 0),
            mAtk: (int)($row['m_atk'] ?? 0),
            randomDamage: (int)($row['random_damage'] ?? 10),
            pDef: (int)($row['p_def'] ?? 0),
            mDef: (int)($row['m_def'] ?? 0),
            mpBonus: (int)($row['mp_bonus'] ?? 0),
            shieldDef: (int)($row['shield_def'] ?? 0),
            shieldRate: (int)($row['shield_rate'] ?? 0),
            critical: (int)($row['critical'] ?? 0),
            attackSpeed: (int)($row['spd'] ?? 0),
            attackRange: (int)($row['attack_range'] ?? 40),
            mpConsume: (int)($row['mp_consume'] ?? 0),
            weight: (int)($row['weight'] ?? 0),
            price: (int)($row['price'] ?? 0),
            isStackable: (bool)($row['stackable'] ?? false),
            soulshotCount: (int)($row['ss_count'] ?? 0),
            spiritshotCount: (int)($row['sps_count'] ?? 0),
            isMagicWeapon: (bool)($row['is_magic_weapon'] ?? false),
            isTradeable: (bool)($row['is_tradeable'] ?? true),
            isDroppable: (bool)($row['is_droppable'] ?? true),
            isDestroyable: (bool)($row['is_destroyable'] ?? true),
            icon: $row['icon'] ?? null
        );

        self::$instances[$id] = $item;
        return $item;
    }
}
