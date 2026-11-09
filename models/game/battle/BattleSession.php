<?php

namespace app\models\game\battle;

class BattleSession
{
    /** @var BattleMob[] */
    public array $mobs = [];
    /** @var string[] */
    public array $logs = [];

    public function __construct(
        public readonly int $characterId,
        public int $round = 1,
        public int $targetIndex = 0,
        array $mobs = [],
        array $logs = [],
        public int $updatedAt = 0
    ) {
        $this->mobs =$mobs;
        $this->logs =$logs;
        $this->updatedAt =$updatedAt ?: time();
    }

    public function getTarget(): ?BattleMob
    {
        if (isset($this->mobs[$this->targetIndex]) && $this->mobs[$this->targetIndex]->isAlive()) {
            return $this->mobs[$this->targetIndex];
        }

        $firstAlive =$this->getFirstAliveMob();
        if ($firstAlive !== null) {
            $this->targetIndex =$firstAlive->index;
            return $firstAlive;
        }

        return null;
    }

    public function setTarget(int $index): bool
    {
        if (isset($this->mobs[$index]) && $this->mobs[$index]->isAlive()) {
            $this->targetIndex =$index;
            return true;
        }
        return false;
    }

    public function getFirstAliveMob(): ?BattleMob
    {
        foreach ($this->mobs as$mob) {
            if ($mob->isAlive()) {
                return $mob;
            }
        }
        return null;
    }

    /**
     * @return BattleMob[]
     */
    public function getAliveMobs(): array
    {
        return array_values(array_filter($this->mobs, fn(BattleMob $m) =>$m->isAlive()));
    }

    public function isAllDead(): bool
    {
        return empty($this->getAliveMobs());
    }

    /**
     * Выборка мобов под удар с приоритетом выбранной цели
     * @return BattleMob[]
     */
    public function resolveTargets(int $maxTargets = 1): array
    {
        $alive =$this->getAliveMobs();
        if (empty($alive)) {
            return [];
        }

        $targets = [];
        $currentTarget =$this->getTarget();
        if ($currentTarget !== null) {
            $targets[] =$currentTarget;
        }

        foreach ($alive as$mob) {
            if (count($targets) >=$maxTargets) {
                break;
            }
            if ($currentTarget !== null && $mob->index ===$currentTarget->index) {
                continue;
            }
            $targets[] =$mob;
        }

        return $targets;
    }

    public function addLog(string $message): void
    {
        $this->logs[] =$message;
        if (count($this->logs) > 6) {
            $this->logs = array_slice($this->logs, -6);
        }
    }

    public function toArray(): array
    {
        return [
            'character_id' => $this->characterId,
            'round'        => $this->round,
            'target_index' => $this->targetIndex,
            'mobs'         => array_map(fn(BattleMob $m) => $m->toArray(),$this->mobs),
            'log'          => $this->logs,
            'updated_at'   => $this->updatedAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        $mobs = [];
        foreach ($data['mobs'] ?? [] as $mobData) {
            $mobs[$mobData['index']] = BattleMob::fromArray($mobData);
        }

        return new self(
            characterId: (int) $data['character_id'],
            round: (int) ($data['round'] ?? 1),
            targetIndex: (int) ($data['target_index'] ?? 0),
            mobs: $mobs,
            logs: (array) ($data['log'] ?? []),
            updatedAt: (int) ($data['updated_at'] ?? time())
        );
    }
}
