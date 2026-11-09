<?php

namespace app\models\game;

use Yii;

class SkillTreeRepository
{
    private static ?array $trees = null;

    private static function init(): void
    {
        if (self::$trees === null) {
            $path = Yii::getAlias('@app/data/game/skill_trees.php');
            self::$trees = file_exists($path) ? require $path : [];
        }
    }

    /**
     * Получить автоматические умения при повышении уровня (Expertise, Lucky и т.д.)
     */
    public static function getAutoSkills(int $classId, int $level): array
    {
        self::init();
        $classTree = self::$trees[$classId] ?? [];

        return array_filter($classTree, function ($skill) use ($level) {
            return $skill['auto_get'] && $skill['req_level'] === $level;
        });
    }

    /**
     * Получить умения, доступные для изучения у NPC
     * @param int $classId ID класса игрока
     * @param int $playerLevel Уровень игрока
     * @param array<int, int> $learnedSkills Массив уже изученных скиллов вида [skill_id => current_level]
     */
    public static function getAvailableSkillsToLearn(int $classId, int $playerLevel, array $learnedSkills = []): array
    {
        self::init();
        $classTree = self::$trees[$classId] ?? [];

        $available = [];

        foreach ($classTree as $entry) {
            // Пропускаем автоматические скиллы
            if ($entry['auto_get']) {
                continue;
            }

            // Пропускаем, если уровень персонажа слишком мал
            if ($entry['req_level'] > $playerLevel) {
                continue;
            }

            $skillId = $entry['skill_id'];
            $targetLevel = $entry['level'];
            $currentLearnedLevel = $learnedSkills[$skillId] ?? 0;

            // Можно изучить только следующий по порядку уровень умения
            if ($targetLevel === $currentLearnedLevel + 1) {
                $available[] = $entry;
            }
        }

        return $available;
    }

    /**
     * Получить умения по классу
     * @param int $classId
     * @param int $level
     * @return array
     */
    public static function getSkillTreeFromClass(int $classId): array
    {
        self::init();
        return self::$trees[$classId] ?? [];
    }
}
