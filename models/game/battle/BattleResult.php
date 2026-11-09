<?php

namespace app\models\game\battle;

use app\components\game\CharacterEffectService;
use app\models\Character;
use app\models\User;

class BattleResult
{
    public function __construct(
        public bool $isFinished,
        public bool $isVictory = false,
        public bool $isDead = false,
        public ?string $message = null,
        public string $flashType = 'info'
    ) {}

    public static function inProgress(): self
    {
        return new self(isFinished: false);
    }

    public static function victory(string $message): self
    {
        CharacterEffectService::flushToDb(Character::getCurrent());
        return new self(
            isFinished: true,
            isVictory: true,
            message: $message,
            flashType: 'success'
        );
    }

    public static function defeat(string $message): self
    {
        CharacterEffectService::flushToDb(Character::getCurrent());
        return new self(
            isFinished: true,
            isDead: true,
            message: $message,
            flashType: 'error'
        );
    }

    public static function fleeSuccess(string $message): self
    {
        return new self(
            isFinished: true,
            message: $message,
            flashType: 'info'
        );
    }
}
