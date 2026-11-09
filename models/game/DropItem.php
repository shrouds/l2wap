<?php

namespace app\models\game;

readonly class DropItem
{
    public function __construct(
        public int    $itemId,
        public string $systemName,
        public int    $min,
        public int    $max,
        public float  $chance, // Итоговый шанс в процентах (0.0001% - 100%)
        public bool   $isSpoil = false
    ) {}

    /**
     * Получить связанный объект Item
     */
    public function getItem(): ?Item
    {
        return ItemRepository::find($this->itemId);
    }

    /**
     * Бросок кубика на выпадение:
     * Возвращает количество выпавших предметов (или 0, если не выпало)
     */
    public function roll(float $rateMultiplier = 1.0): int
    {
        // Переводим проценты в число с плавающей точкой
        $effectiveChance = min(100.0, $this->chance * $rateMultiplier);
        $random = mt_rand(1, 1000000) / 10000.0; // от 0.0001 до 100.0000

        if ($random <= $effectiveChance) {
            return mt_rand($this->min, $this->max);
        }

        return 0;
    }
}
