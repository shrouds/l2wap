<?php
declare(strict_types=1);

namespace app\models\game;

use Yii;

class SkillRepository
{
    private static ?array $data = null;
    /** @var array<string, Skill> */
    private static array $instances = [];

    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/skills.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    public static function find(int $id, int $level): ?Skill
    {
        self::init();

        $key = "{$id}:{$level}";
        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        if (!isset(self::$data[$id][$level])) {
            return null;
        }

        $row = self::$data[$id][$level];

        $skill = new Skill(
            id: $id,
            level: $level,
            systemName: (string)($row['name'] ?? "skill_{$id}_{$level}"),
            type: (string)($row['type'] ?? 'EFFECT'),
            targetType: (string)($row['target'] ?? 'enemy'),
            isMagic: (bool)($row['magic'] ?? false),
            mpConsume: (int)($row['mp'] ?? 0),
            hpConsume: (int)($row['hp'] ?? 0),
            power: (float)($row['power'] ?? 0.0),
            castTime: (float)($row['cast'] ?? 0.0),
            reuseDelay: (int)($row['reuse'] ?? 0),
            range: (int)($row['range'] ?? 40),
            effects: (array)($row['effects'] ?? []),
            icon: $row['icon'] ?? null
        );

        self::$instances[$key] = $skill;
        return $skill;
    }

    public static function has(int $id, int $level): bool
    {
        self::init();
        return isset(self::$data[$id][$level]);
    }

    public static function getMaxLevel(int $id): int
    {
        self::init();
        if (!isset(self::$data[$id])) {
            return 0;
        }
        return (int)max(array_keys(self::$data[$id]));
    }
}
