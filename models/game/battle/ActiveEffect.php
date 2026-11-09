<?php

namespace app\models\game\battle;

class ActiveEffect
{
    public function __construct(
        public readonly string $id,                 // Системный ID: 'might', 'shield', 'stun', 'hex', 'poison'
        public readonly string $abnormalType,       // Группа стекирования: 'pa_up', 'pd_up', 'stun', 'poison'
        public readonly int $abnormalLv,           // Уровень силы эффекта (1, 2, 3...)
        public readonly string $type,               // 'STAT', 'CONTROL', 'DOT'
        public readonly ?string $stat = null,       // 'p_physical_attack', 'p_physical_defence', 'max_hp'
        public readonly float $value = 0.0,         // Значение (+15.0%, -23.0%, 25.0 DoT)
        public readonly string $mode = 'per',       // 'per' (процент) или 'diff' (число)
        public int $duration = 1200,                // Оставшаяся длительность (секунды для игрока, раунды для моба)
        public readonly ?string $icon = null        // Имя иконки для UI (например, 'skill1068.png')
    ) {}

    public function isControl(): bool
    {
        return $this->type === 'CONTROL';
    }

    public function isDot(): bool
    {
        return $this->type === 'DOT';
    }

    public function isStat(): bool
    {
        return $this->type === 'STAT';
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'abnormal_type' => $this->abnormalType,
            'abnormal_lv'   => $this->abnormalLv,
            'type'          => $this->type,
            'stat'          => $this->stat,
            'value'         => $this->value,
            'mode'          => $this->mode,
            'duration'      => $this->duration,
            'icon'          => $this->icon,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)$data['id'],
            abnormalType: (string)($data['abnormal_type'] ?? $data['id']),
            abnormalLv: (int)($data['abnormal_lv'] ?? 1),
            type: (string)($data['type'] ?? 'STAT'),
            stat: $data['stat'] ?? null,
            value: (float)($data['value'] ?? 0.0),
            mode: (string)($data['mode'] ?? 'per'),
            duration: (int)($data['duration'] ?? 1200),
            icon: $data['icon'] ?? null
        );
    }

    /**
     * Прямой веб-путь к иконке эффекта
     */
    public function getIconUrl(): string
    {
        $base = \Yii::getAlias('@web') ?: '';

        if (!empty($this->icon)) {
            $filename = ltrim($this->icon, '/');
            if (!str_contains($filename, '.')) {
                $filename .= '.png';
            }
            return $base . '/images/icons/' . $filename;
        }

        return $base . '/images/icons/no_icon.png';
    }

    /**
     * Форматирование оставшегося времени (например, 19м или 45с)
     */
    public function getFormattedTime(): string
    {
        if ($this->duration <= 0) {
            return '0с';
        }

        $minutes = (int)floor($this->duration / 60);
        $seconds = $this->duration % 60;

        if ($minutes > 0) {
            return "{$minutes}м";
        }

        return "{$seconds}с";
    }
}
