<?php
declare(strict_types=1);

namespace app\models\game\battle;

use app\models\game\Npc;
use app\models\game\NpcRepository;

class BattleMob
{
    /** @var ActiveEffect[] */
    public array $effects = [];

    public function __construct(
        public readonly int $index,
        public readonly int $npcId,
        public float $curHp,
        public float $curMp,
        public readonly int $maxHp,
        array $effects = []
    ) {
        $this->effects = $effects;
    }

    public function getNpc(): ?Npc
    {
        return NpcRepository::find($this->npcId);
    }

    public function getName(): string
    {
        return $this->getNpc()?->getName() ?? "Монстр #{$this->npcId}";
    }

    public function getLevel(): int
    {
        return (int)($this->getNpc()?->level ?? 1);
    }

    public function getAttackSpeed(): int
    {
        return max(100, (int)($this->getNpc()?->attackSpeed ?? 253));
    }

    /**
     * Интервал между ударами в секундах (по формуле PTS: 500 / Atk.Spd)
     * При spd = 253 интервал составляет ~1.98 сек.
     */
    public function getAttackInterval(): float
    {
        return round(500.0 / $this->getAttackSpeed(), 2);
    }

    /**
     * Оставшаяся длительность стана/контроля в секундах
     */
    public function getControlRemainingDuration(): int
    {
        foreach ($this->effects as $e) {
            if ($e->isControl()) {
                return max(0, $e->duration);
            }
        }
        return 0;
    }

    /**
     * P.Def с учетом дебаффов (Hex, Gloom: stat = 'p_physical_defence')
     */
    public function getPDef(): int
    {
        $baseDef = (float)max(10, (int)($this->getNpc()?->pDef ?? 40));

        foreach ($this->effects as $eff) {
            if ($eff->isStat() && $eff->stat === 'p_physical_defence') {
                if ($eff->mode === 'per') {
                    $baseDef *= (1.0 + ($eff->value / 100.0));
                } elseif ($eff->mode === 'diff') {
                    $baseDef += $eff->value;
                }
            }
        }

        return max(1, (int)round($baseDef));
    }

    /**
     * M.Def с учетом дебаффов
     */
    public function getMDef(): int
    {
        $baseDef = (float)max(10, (int)($this->getNpc()?->mDef ?? 30));

        foreach ($this->effects as $eff) {
            if ($eff->isStat() && $eff->stat === 'p_magic_defence' && $eff->mode === 'per') {
                $baseDef *= (1.0 + ($eff->value / 100.0));
            }
        }

        return max(1, (int)round($baseDef));
    }

    /**
     * P.Atk моба (с учетом дебаффов на атаку)
     */
    public function getPAtk(): int
    {
        $baseAtk = (float)max(8, (int)($this->getNpc()?->pAtk ?? 20));

        foreach ($this->effects as $eff) {
            if ($eff->isStat() && $eff->stat === 'p_physical_attack' && $eff->mode === 'per') {
                $baseAtk *= (1.0 + ($eff->value / 100.0));
            }
        }

        return max(1, (int)round($baseAtk));
    }

    /**
     * Скорость перемещения моба с учетом контроля и дебаффов
     */
    public function getRunSpeed(): int
    {
        // Базовая скорость из шаблона NPC (или 120 как fallback)
        $baseSpeed = (int)($this->getNpc()?->speed ?? 120);

        foreach ($this->effects as $eff) {
            // Если моб полностью обездвижен (Root / Hold / Sleep / Stun)
            if ($eff->isControl() && in_array($eff->id, ['root', 'hold', 'sleep', 'stun'], true)) {
                return 0;
            }

            // Модификаторы скорости (Slow, Cripple, Frost Bolt, ускорения)
            if ($eff->isStat() && in_array($eff->stat, ['p_speed', 'speed', 'run_speed'], true)) {
                if ($eff->mode === 'per') {
                    $baseSpeed = (int)round($baseSpeed * (1.0 + ($eff->value / 100.0)));
                } elseif ($eff->mode === 'diff') {
                    $baseSpeed = (int)round($baseSpeed + $eff->value);
                }
            }
        }

        return max(0, $baseSpeed);
    }

    public function hasEffect(string $abnormalType): bool
    {
        foreach ($this->effects as $e) {
            if ($e->abnormalType === $abnormalType || $e->id === $abnormalType) {
                return true;
            }
        }
        return false;
    }

    public function isStunned(): bool
    {
        return $this->getControlRemainingDuration() > 0;
    }

    public function addEffect(ActiveEffect $newEffect): void
    {
        foreach ($this->effects as $key => $existing) {
            if ($existing->abnormalType === $newEffect->abnormalType) {
                if ($existing->abnormalLv > $newEffect->abnormalLv) {
                    return;
                }
                unset($this->effects[$key]);
                break;
            }
        }
        $this->effects[$newEffect->id] = $newEffect;
    }

    /**
     * Списание секунд эффектов и расчет тиков DoT за прошедшее время
     * @return array [суммарный урон DoT, логи]
     */
    public function processElapsedEffects(int $elapsed): array
    {
        $dotDamage = 0;
        $logs = [];

        foreach ($this->effects as $key => $effect) {
            if ($effect->isDot()) {
                // Тик яда/кровотечения каждые 3 секунды
                $ticks = (int)floor($elapsed / 3);
                if ($ticks > 0) {
                    $dmg = (int)round($effect->value * $ticks);
                    $this->takeDamage((float)$dmg);
                    $dotDamage += $dmg;
                    $logs[] = "{$this->getName()} получил {$dmg} урона от {$effect->id} ({$ticks} тиков).";
                }
            }

            // Списываем честные секунды
            $effect->duration -= $elapsed;
            if ($effect->duration <= 0) {
                unset($this->effects[$key]);
            }
        }

        return [$dotDamage, $logs];
    }

    /**
     * Список изученных умений моба
     * @return array<int, array{trigger: string, skill_id: int, level: int, name: string}>
     */
    public function getSkills(): array
    {
        return $this->getNpc()?->skills ?? [];
    }


    /**
     * Прогон DoT-тиков и уменьшение раундов контроля
     * @return array [суммарный DoT-урон, лог раунда]
     */
    public function processRoundEffects(): array
    {
        $dotDamage = 0;
        $logs = [];

        foreach ($this->effects as $key => $effect) {
            if ($effect->isDot()) {
                $dmg = (int)$effect->value;
                $this->takeDamage((float)$dmg);
                $dotDamage += $dmg;
                $logs[] = "{$this->getName()} получает {$dmg} урона от {$effect->id}.";
            }

            $effect->duration--;
            if ($effect->duration <= 0) {
                unset($this->effects[$key]);
            }
        }

        return [$dotDamage, $logs];
    }

    public function isAlive(): bool
    {
        return $this->curHp > 0.0;
    }

    public function isDead(): bool
    {
        return $this->curHp <= 0.0;
    }

    public function takeDamage(float $damage): float
    {
        $actual = min($this->curHp, $damage);
        $this->curHp = max(0.0, $this->curHp - $damage);
        return $actual;
    }

    public function getHpPercent(): int
    {
        return min(100, (int)round(($this->curHp / max(1, $this->maxHp)) * 100));
    }

    public function toArray(): array
    {
        return [
            'index'   => $this->index,
            'npc_id'  => $this->npcId,
            'cur_hp'  => $this->curHp,
            'cur_mp'  => $this->curMp,
            'max_hp'  => $this->maxHp,
            'effects' => array_map(fn(ActiveEffect $e) => $e->toArray(), array_values($this->effects)),
        ];
    }

    public static function fromArray(array $data): self
    {
        $effects = [];
        foreach ($data['effects'] ?? [] as $effData) {
            $effects[$effData['id']] = ActiveEffect::fromArray($effData);
        }

        return new self(
            index: (int)$data['index'],
            npcId: (int)$data['npc_id'],
            curHp: (float)$data['cur_hp'],
            curMp: (float)$data['cur_mp'],
            maxHp: (int)$data['max_hp'],
            effects: $effects
        );
    }
}
