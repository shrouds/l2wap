<?php
declare(strict_types=1);

namespace app\models\game;

use Yii;

class PcParamsRepository
{
    private static ?array $data = null;

    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/pc_params.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    /**
     * Конфигурация профессии (ID, имя, таблица HP/MP, базовые STR/CON/...)
     */
    public static function getClass(int $classId): ?array
    {
        self::init();
        return self::$data['classes'][$classId] ?? null;
    }

    /**
     * Множитель первичного стата (str, con, dex, int, men, wit)
     * Преобразует PTS процент (например, 58) в множитель 1.58
     */
    public static function getStatModifier(string $stat, int $statValue): float
    {
        self::init();
        $stat = strtolower($stat);
        $val = max(1, min(99, $statValue));

        $bonusPercent = self::$data['stat_bonus'][$stat][$val] ?? 0;
        return (100.0 + (float)$bonusPercent) / 100.0;
    }

    /**
     * Множитель уровня персонажа (level_bonus)
     * 1 ур. -> 0.90, 11 ур. -> 1.00, 80 ур. -> 1.69
     */
    public static function getLevelModifier(int $level): float
    {
        self::init();
        $lvl = max(1, min(80, $level));
        return (float)(self::$data['level_bonus'][$lvl] ?? 1.0);
    }

    /**
     * Базовое HP из таблицы класса (до умножения на CON)
     */
    public static function getBaseHp(int $classId, int $level): float
    {
        self::init();
        $class = self::getClass($classId);
        if (!$class) {
            return 80.0;
        }

        $table = $class['hp_table'] ?? 'fighter';
        $lvl = max(1, min(80, $level));

        return (float)(self::$data['hp_tables'][$table][$lvl] ?? 80.0);
    }

    /**
     * Базовое MP из таблицы класса (до умножения на MEN)
     */
    public static function getBaseMp(int $classId, int $level): float
    {
        self::init();
        $class = self::getClass($classId);
        if (!$class) {
            return 30.0;
        }

        $table = $class['mp_table'] ?? 'fighter';
        $lvl = max(1, min(80, $level));

        return (float)(self::$data['mp_tables'][$table][$lvl] ?? 30.0);
    }

    /**
     * Базовая регенерация HP за тик (3 сек) по корзинам уровней
     */
    public static function getBaseHpRegen(int $classId, int $level, int $gender = 0): float
    {
        self::init();
        $template = self::getTemplateForClass($classId, $gender);
        $bracket = min(7, (int)(($level - 1) / 10));

        return (float)($template['org_hp_regen'][$bracket] ?? 2.0);
    }

    /**
     * Базовая регенерация MP за тик (3 сек) по корзинам уровней
     */
    public static function getBaseMpRegen(int $classId, int $level, int $gender = 0): float
    {
        self::init();
        $template = self::getTemplateForClass($classId, $gender);
        $bracket = min(7, (int)(($level - 1) / 10));

        return (float)($template['org_mp_regen'][$bracket] ?? 0.9);
    }

    /**
     * Множитель стойки (по умолчанию stand = 1.1)
     */
    public static function getRegenMoveModeBonus(string $mode = 'stand'): float
    {
        self::init();
        $bonus = self::$data['regen_bonus'][$mode] ?? 0.1;
        return 1.0 + (float)$bonus;
    }

    /**
     * Базовые параметры расы/пола (MFighter, FFighter, MMagic...)
     */
    public static function getTemplateForClass(int $classId, int $gender = 0): array
    {
        self::init();
        $class = self::getClass($classId);
        $baseTemplate = $class['template'];

        // Префикс пола M/F (MFighter -> FFighter)
        $templateKey = $gender === 1
            ? 'F' . substr($baseTemplate, 1)
            : $baseTemplate;

        return self::$data['templates'][$templateKey] ?? self::$data['templates']['MFighter'] ?? [];
    }
}
