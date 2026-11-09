<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var app\models\game\battle\BattleSession $session */

use yii\bootstrap5\Html;
use app\models\game\SkillRepository;

$target = $session->getTarget();
$aliveCount = count($session->getAliveMobs());
$this->title = "Бой ({$aliveCount} вр.)";

// Быстрый слот зелья HP
$hpPotion = $character->getCharacterItems()
    ->where(['in', 'item_id', [1060, 1061]])
    ->andWhere(['>', 'count', 0])
    ->one();

$charSkills = $character->characterSkills;
?>

<div class="battle-arena">
    <!-- Список врагов на арене с переключением фокуса -->
    <div class="card p-2 mb-2 bg-light border-0">
        <div class="small fw-bold border-bottom pb-1 mb-2 text-muted d-flex justify-content-between">
            <span>Противники в бою:</span>
            <span>Раунд: <?= $session->round ?></span>
        </div>

        <?php foreach ($session->mobs as $mob): ?>
            <?php
            $isTarget = $target && $target->index === $mob->index;
            $isDead = $mob->isDead();
            ?>
            <div class="card p-2 mb-1 <?= $isDead ? 'opacity-50 border-0 bg-secondary-subtle' : ($isTarget ? 'border-danger bg-white shadow-sm' : 'border-0 bg-white') ?>">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div>
                        <span class="badge <?= $isDead ? 'bg-secondary' : ($isTarget ? 'bg-danger' : 'bg-dark') ?>">
                            <?= $mob->getLevel() ?> ур.
                        </span>
                        <b class="ms-1 <?= $isTarget ? 'text-danger' : ($isDead ? 'text-decoration-line-through text-muted' : 'text-dark') ?>">
                            <?= Html::encode($mob->getName()) ?>
                        </b>
                        <?= $isTarget ? '🎯' : '' ?>
                    </div>
                    <div>
                        <?php if ($isDead): ?>
                            <small class="text-danger fw-bold">Мертв</small>
                        <?php elseif (!$isTarget): ?>
                            <?= Html::beginForm(['target', 'index' => $mob->index], 'post') ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.72rem;">
                                Фокус
                            </button>
                            <?= Html::endForm() ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Полоска HP моба -->
                <?php if (!$isDead): ?>
                    <div class="d-flex justify-content-between small text-danger" style="font-size: 0.72rem;">
                        <span>HP: <?= (int)$mob->curHp ?> / <?= $mob->maxHp ?></span>
                        <span><?= $mob->getHpPercent() ?>%</span>
                    </div>
                    <div class="progress" style="height: 5px;">
                        <div class="progress-bar bg-danger" style="width: <?= $mob->getHpPercent() ?>%"></div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Основные кнопки действий -->
    <div class="card p-2 mb-2 bg-light border-0">
        <div class="d-flex gap-2 mb-2">
            <!-- Удар -->
            <?= Html::beginForm(['attack'], 'post', ['class' => 'w-75']) ?>
            <button type="submit" class="btn btn-danger w-100 py-2 fw-bold" <?= $target === null ? 'disabled' : '' ?>>
                ⚔ Атаковать <?= $target ? '(' . Html::encode($target->getName()) . ')' : '' ?>
            </button>
            <?= Html::endForm() ?>

            <!-- Побег -->
            <?= Html::beginForm(['flee'], 'post', ['class' => 'w-25']) ?>
            <button type="submit" class="btn btn-outline-secondary w-100 py-2 small" data-confirm="Попробовать сбежать?">
                🏃 Бег
            </button>
            <?= Html::endForm() ?>
        </div>

        <!-- Использование банки HP -->
        <?php if ($hpPotion): ?>
            <?= Html::beginForm(['potion', 'id' => $hpPotion->id], 'post') ?>
            <button type="submit" class="btn btn-sm btn-outline-success w-100 py-1" <?= $character->cur_hp >= $character->maxHp ? 'disabled' : '' ?>>
                🧪 Исцеление (осталось: <?= $hpPotion->count ?>)
            </button>
            <?= Html::endForm() ?>
        <?php endif; ?>
    </div>

    <!-- Боевые умения -->
    <?php if (!empty($charSkills)): ?>
        <div class="card p-2 mb-2 border-0 bg-light">
            <div class="small fw-bold border-bottom pb-1 mb-2 text-muted">Умения:</div>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($charSkills as $cs): ?>
                    <?php
                    $skill = SkillRepository::find($cs->skill_id, $cs->level);
                    if (!$skill || $skill->isPassive()) continue;
                    $hasMp = $character->cur_mp >= $skill->mpConsume;
                    ?>
                    <?= Html::beginForm(['skill', 'id' => $skill->id], 'post', ['class' => 'd-inline']) ?>
                    <button type="submit"
                            class="btn btn-sm <?= $skill->isAoe() ? 'btn-warning' : ($skill->isMagic ? 'btn-outline-primary' : 'btn-outline-danger') ?> py-1 px-2"
                        <?= !$hasMp ? 'disabled' : '' ?>>
                        <?= Html::encode($skill->getName()) ?>
                        <small class="text-muted">(<?= $skill->mpConsume ?> MP)</small>
                        <?= $skill->isAoe() ? ' [AoE]' : '' ?>
                    </button>
                    <?= Html::endForm() ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Лог раундов -->
    <div class="card p-2 mb-2 border-0 shadow-sm bg-white">
        <div class="fw-bold small border-bottom pb-1 mb-2 text-muted" style="font-size: 0.75rem;">
            Хроника боя:
        </div>
        <div class="battle-log small" style="font-size: 0.8rem; line-height: 1.4;">
            <?php $logs = array_reverse($session->logs); ?>
            <?php if (empty($logs)): ?>
                <div class="text-muted fst-italic">Ожидание первого удара...</div>
            <?php else: ?>
                <?php foreach ($logs as $i => $entry): ?>
                    <div class="py-1 border-bottom <?= $i === 0 ? 'fw-bold text-dark' : 'text-muted' ?>">
                        <?= $entry ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
