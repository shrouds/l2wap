<?php
declare(strict_types=1);

namespace app\models\game;

use Yii;

readonly class Skill
{
    public function __construct(
        public int     $id,
        public int     $level,
        public string  $systemName,
        public string  $type,        // PHYS_DMG, MAGIC_DMG, HEAL, EFFECT, PASSIVE
        public string  $targetType,  // enemy, enemy_only, self, target, party, area, aura
        public bool    $isMagic,       // true - магия (Spiritshot), false - физ. (Soulshot)
        public int     $mpConsume,
        public int     $hpConsume,
        public float   $power,
        public float   $castTime,     // Базовое время применения в секундах
        public int     $reuseDelay,     // Время отката в секундах
        public int     $range,          // Дистанция атаки
        public array   $effects = [], // debuff, stun, dot, drain_percent
        public ?string $icon = null // Название файла из web/images/icons/
    ) {}

    public function getName(?string $lang = null): string
    {
        $lang = $lang ?? Yii::$app->language;
        $messages = @include Yii::getAlias("@app/messages/{$lang}/skills.php");

        return $messages[$this->id]['name'] ?? str_replace(['s_', '_'], ['', ' '], $this->systemName);
    }

    public function getDescription(?string $lang = null): string
    {
        $lang = $lang ?? Yii::$app->language;
        $messages = @include Yii::getAlias("@app/messages/{$lang}/skills.php");

        return $messages[$this->id]['desc'] ?? '';
    }

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

        return $base . '/images/icons/no_icon.png';
    }

    public function isPassive(): bool
    {
        return $this->type === 'PASSIVE';
    }

    public function isOffensive(): bool
    {
        return in_array($this->type, ['PHYS_DMG', 'MAGIC_DMG'], true)
            || in_array($this->targetType, ['enemy', 'enemy_only', 'area', 'aura'], true);
    }

    public function isAoe(): bool
    {
        return in_array($this->targetType, ['area', 'aura'], true);
    }

    public function isTargetRequired(): bool
    {
        return in_array($this->targetType, ['enemy', 'enemy_only', 'area', 'target'], true);
    }

    public function getMaxTargets(): int
    {
        if (!$this->isAoe()) {
            return 1;
        }

        return match ($this->systemName) {
            's_whirlwind1', 's_whirlwind2' => 5,
            's_earthquake'                 => 8,
            's_spoil_festival'             => 6,
            default                        => 5,
        };
    }
}
