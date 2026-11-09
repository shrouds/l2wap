<?php

namespace app\models\game;

use Yii;

readonly class Npc
{
    /**
     * @param DropItem[] $drops Список обычного дропа
     * @param DropItem[] $spoils Список спойла
     */
    public function __construct(
        public int    $id,
        public string $systemName,
        public string $type,
        public int    $level,
        public int    $hp,
        public int    $mp,
        public int   $pAtk,
        public int   $mAtk,
        public int   $pDef,
        public int   $mDef,
        public int   $exp,
        public int   $sp,
        public float $hpRegen,
        public float $mpRegen,
        public int   $attackSpeed,
        public int   $critical,
        public array $drops = [],
        public array $spoils = [],
        public array  $skills = []
    ) {}

    /**
     * Локализованное имя моба
     */
    public function getName(?string $lang = null): string
    {
        $lang = $lang ?? Yii::$app->language;
        $messages = @include Yii::getAlias("@app/messages/{$lang}/npcs.php");

        return $messages[$this->id]['name'] ?? $this->systemName;
    }

    /**
     * Является ли существо боссом
     */
    public function isBoss(): bool
    {
        return $this->type === 'boss';
    }

    /**
     * Является ли существо обычным монстром
     */
    public function isMonster(): bool
    {
        return in_array($this->type, ['monster', 'warrior', 'treasure'], true);
    }

    /**
     * Рассчитать весь выпавший дроп после смерти моба
     * @return array<int, array{item: Item, count: int}>
     */
    public function calculateDrop(float $rateMultiplier = 1.0): array
    {
        $result = [];

        foreach ($this->drops as $dropItem) {
            $count = $dropItem->roll($rateMultiplier);
            if ($count > 0) {
                $item = $dropItem->getItem();
                if ($item !== null) {
                    $result[] = [
                        'item' => $item,
                        'count' => $count,
                    ];
                }
            }
        }

        return $result;
    }
}
