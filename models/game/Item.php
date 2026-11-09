<?php

namespace app\models\game;

use Yii;

readonly class Item
{
    public function __construct(
        public  int    $id,
        public string          $systemName,
        public  string $type,
        public string          $slot,
        public  string $weaponType,
        public  string $armorType,
        public  string $material,
        public  string $grade,
        public  bool   $isCrystallizable,
        public  int    $crystalCount,
        public  int    $pAtk,
        public  int    $mAtk,
        public  int    $randomDamage,
        public  int    $pDef,
        public  int $mDef,
        public  int $mpBonus,
        public  int $shieldDef,
        public  int $shieldRate,
        public  int $critical,
        public  int $attackSpeed,
        public  int $attackRange,
        public  int $mpConsume,
        public  int $weight,
        public  int $price,
        public  bool $isStackable,
        public  int $soulshotCount,
        public  int $spiritshotCount,
        public  bool $isMagicWeapon,
        public  bool $isTradeable,
        public  bool $isDroppable,
        public  bool $isDestroyable,
        public  ?string $icon = null
    ) {}

    public function getName(?string $lang = null): string
    {
        $lang = $lang ?? Yii::$app->language;
        $messages = @include Yii::getAlias("@app/messages/{$lang}/items.php");

        return $messages[$this->id]['name'] ?? str_replace('_', ' ', $this->systemName);
    }

    /**
     * Описание предмета
     */
    public function getDescription(?string $lang = null): string
    {
        $lang = $lang ?? Yii::$app->language;
        $messages = @include Yii::getAlias("@app/messages/{$lang}/items.php");

        if (!empty($messages[$this->id]['desc'])) {
            return $messages[$this->id]['desc'];
        }

        // Автоматическое описание на основе характеристик, если текст не задан вручную
        if ($this->isWeapon()) {
            return "Оружие ({$this->weaponType}). Физ. Атк: {$this->pAtk}, Маг. Атк: {$this->mAtk}.";
        }
        if ($this->isArmor()) {
            return "Доспех ({$this->armorType}). Физ. Защита: {$this->pDef}.";
        }
        if ($this->type === 'accessary') {
            return "Бижутерия. Маг. Защита: {$this->mDef}.";
        }

        return '';
    }

    /**
     * Ссылка на иконку предмета для вывода в HTML
     */
    public function getIconUrl(): string
    {
        $base = Yii::getAlias('@web') ?: '';

        if (!empty($this->icon)) {
            $filename = ltrim($this->icon, '/');
            if (!str_contains($filename, '.')) {
                $filename .= '.png';
            }
            return $base . '/images/icons/' . $filename;
        }

        return $base . '/images/icons/default.png';
    }

    public function isWeapon(): bool
    {
        return $this->type === 'weapon';
    }

    public function isArmor(): bool
    {
        return $this->type === 'armor';
    }

    public function isShield(): bool
    {
        return $this->slot === 'lhand' && $this->shieldDef > 0;
    }

    public function isTwoHanded(): bool
    {
        return $this->slot === 'lrhand';
    }

    public function isFullBodyArmor(): bool
    {
        return $this->slot === 'onepiece';
    }
}
