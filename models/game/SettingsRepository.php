<?php

namespace app\models\game;

use Yii;

class SettingsRepository
{
    private static ?array $data = null;

    /**
     * Маппинг базовых class_id Lineage 2 C4 на строковые ключи конфига
     */
    private const CLASS_ID_MAP = [
        0  => 'human_fighter',
        10 => 'human_magician',
        18 => 'elf_fighter',
        25 => 'elf_magician',
        31 => 'darkelf_fighter',
        38 => 'darkelf_magician',
        44 => 'orc_fighter',
        49 => 'orc_shaman',
        53 => 'dwarf_apprentice',
    ];

    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/settings.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    /**
     * Получить список стартовых предметов для класса (по class_id или строковому ключу)
     *
     * @param int|string $class
     * @return InitialItem[]
     */
    public static function getEquipment(int|string $class): array
    {
        self::init();

        $key = self::resolveClassKey($class);
        if ($key === null || !isset(self::$data['initial_equipment'][$key])) {
            return [];
        }

        $items = [];
        foreach (self::$data['initial_equipment'][$key] as $row) {
            $items[] = new InitialItem(
                item: ItemRepository::find($row['item_id']),
                count: (int)($row['count'] ?? 1)
            );
        }

        return $items;
    }

    /**
     * Сырой массив предметов (если в сервисе нужен чистый массив)
     *
     * @param int|string $class
     * @return array<int, array{item_id: int, system_name: string, count: int}>
     */
    public static function getRawEquipment(int|string $class): array
    {
        self::init();

        $key = self::resolveClassKey($class);
        if ($key === null) {
            return [];
        }

        return self::$data['initial_equipment'][$key] ?? [];
    }

    /**
     * Стартовая локация появления персонажа
     */
    public static function getStartLocationId(int|string $class, string $default = 'ti_town'): string
    {
        self::init();

        $key = self::resolveClassKey($class);
        if ($key === null) {
            return $default;
        }

        return self::$data['start_locations'][$key] ?? $default;
    }

    /**
     * Проверка, является ли класс валидным стартовым классом
     */
    public static function isStartClass(int|string $class): bool
    {
        return self::resolveClassKey($class) !== null;
    }

    /**
     * Список всех доступных стартовых классов [class_id => 'string_key']
     *
     * @return array<int, string>
     */
    public static function getStartClasses(): array
    {
        return self::CLASS_ID_MAP;
    }

    /**
     * Нормализация class_id (int) или строки в системный строковый ключ
     */
    public static function resolveClassKey(int|string $class): ?string
    {
        if (is_int($class)) {
            return self::CLASS_ID_MAP[$class] ?? null;
        }

        $clean = strtolower(trim($class));
        if (isset(self::$data['initial_equipment'][$clean])) {
            return $clean;
        }

        // Если передан ID в виде числовой строки ("0", "10")
        if (is_numeric($clean)) {
            return self::CLASS_ID_MAP[(int)$clean] ?? null;
        }

        return in_array($clean, self::CLASS_ID_MAP, true) ? $clean : null;
    }

    /**
     * Обратный поиск class_id по строковому ключу
     */
    public static function getClassId(string $classKey): ?int
    {
        $flipped = array_flip(self::CLASS_ID_MAP);
        return $flipped[strtolower(trim($classKey))] ?? null;
    }
}
