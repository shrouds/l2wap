<?php

namespace app\models\game;

use Yii;

class ExpTableRepository
{
    private static ?array $data = null;
    private static function init(): void
    {
        if (self::$data === null) {
            $path = Yii::getAlias('@app/data/game/exp_table.php');
            self::$data = file_exists($path) ? require $path : [];
        }
    }

    public static function getCurrentLvlExp(int $lvl): int
    {
        self::init();
        return self::$data[$lvl] ?? 0;
    }

    public static function getNextLvlExp(int $lvl): int
    {
        self::init();
        $targetLvl = min(80, $lvl + 1);
        return self::$data[$targetLvl] ?? (self::$data[$lvl] ?? 0);
    }
}
