<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var array $activeSkills */
/** @var array $passiveSkills */

use yii\bootstrap5\Html;

$this->title = 'Умения — ' . Html::encode($character->name);

$typeBadges = [
    'PHYS_DMG'  => ['label' => 'Физ. атака', 'class' => 'bg-danger'],
    'MAGIC_DMG' => ['label' => 'Магия', 'class' => 'bg-primary'],
    'HEAL'      => ['label' => 'Исцеление', 'class' => 'bg-success'],
    'EFFECT'    => ['label' => 'Эффект', 'class' => 'bg-warning text-dark'],
    'PASSIVE'   => ['label' => 'Пассивное', 'class' => 'bg-secondary'],
];

$targetLabels = [
    'enemy'      => 'Враг',
    'enemy_only' => 'Враг',
    'self'       => 'На себя',
    'target'     => 'Цель',
    'party'      => 'Группа',
    'area'       => 'Массовый (AoE)',
    'aura'       => 'Вокруг себя (AoE)',
];
?>

<div class="character-skills">
    <!-- Активные умения -->
    <div class="card p-2 mb-2 border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
            <span class="fw-bold small text-uppercase text-danger" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                Активные умения
            </span>
            <span class="badge bg-light text-dark border" style="font-size: 0.65rem;">
                <?= count($activeSkills) ?>
            </span>
        </div>

        <?php if (empty($activeSkills)): ?>
            <div class="text-muted small py-2 text-center fst-italic">Нет изученных активных умений</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($activeSkills as $row): ?>
                    <?php
                    /** @var app\models\CharacterSkill $charSkill */
                    $charSkill = $row['charSkill'];
                    /** @var app\models\game\Skill $skill */
                    $skill = $row['skill'];
                    if (!$skill) continue;

                    $typeBadge = $typeBadges[$skill->type] ?? ['label' => $skill->type, 'class' => 'bg-secondary'];
                    $targetText = $targetLabels[$skill->targetType] ?? $skill->targetType;
                    $desc = $skill->getDescription();
                    ?>
                    <div class="list-group-item px-0 py-2 border-bottom">
                        <div class="d-flex align-items-start gap-2">
                            <img src="<?= Html::encode($skill->getIconUrl()) ?>"
                                 width="32"
                                 height="32"
                                 class="rounded border border-secondary flex-shrink-0 mt-1"
                                 alt="<?= Html::encode($skill->getName()) ?>">

                            <div class="w-100" style="line-height: 1.25;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark" style="font-size: 0.85rem;">
                                        <?= Html::encode($skill->getName()) ?>
                                    </span>
                                    <span class="badge bg-light text-secondary border py-1" style="font-size: 0.65rem;">
                                        <?= $charSkill->level ?> ур.
                                    </span>
                                </div>

                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="badge <?= $typeBadge['class'] ?> py-0" style="font-size: 0.6rem;">
                                        <?= $typeBadge['label'] ?>
                                    </span>
                                    <span class="badge bg-light text-muted border py-0" style="font-size: 0.6rem;">
                                        Цель: <?= $targetText ?>
                                    </span>
                                    <?php if ($skill->power > 0): ?>
                                        <span class="badge bg-light text-danger border py-0" style="font-size: 0.6rem;">
                                            Сила: <?= (int)$skill->power ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Описание умения -->
                                <?php if ($desc): ?>
                                    <div class="text-dark small mb-1" style="font-size: 0.75rem;">
                                        <?= Html::encode($desc) ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Дополнительные эффекты -->
                                <?php if (!empty($skill->effects)): ?>
                                    <div class="text-primary mb-1" style="font-size: 0.7rem;">
                                        <?php if (isset($skill->effects['debuff'])): ?>
                                            • Эффект: <b><?= strtoupper($skill->effects['debuff']) ?></b> (шанс <?= $skill->effects['chance'] ?? 80 ?>%, <?= $skill->effects['duration'] ?? 9 ?>с)
                                        <?php endif; ?>
                                        <?php if (isset($skill->effects['drain_percent'])): ?>
                                            • Вампиризм: <b><?= $skill->effects['drain_percent'] ?>%</b> от урона
                                        <?php endif; ?>
                                        <?php if (isset($skill->effects['dot'])): ?>
                                            • Периодический урон: <b><?= $skill->effects['dot'] ?></b> (<?= $skill->effects['tick_dmg'] ?? 20 ?> ед/тик)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Технические параметры каста и стоимости -->
                                <div class="text-muted d-flex flex-wrap gap-2" style="font-size: 0.7rem;">
                                    <span>Расход MP: <b class="text-primary"><?= $skill->mpConsume ?></b></span>
                                    <?php if ($skill->hpConsume > 0): ?>
                                        <span>Расход HP: <b class="text-danger"><?= $skill->hpConsume ?></b></span>
                                    <?php endif; ?>
                                    <span>Каст: <b><?= $skill->castTime ?>с</b></span>
                                    <span>Откат: <b><?= $skill->reuseDelay ?>с</b></span>
                                    <?php if ($skill->range > 40): ?>
                                        <span>Дист: <b><?= $skill->range ?></b></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Пассивные умения -->
    <div class="card p-2 mb-3 border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
            <span class="fw-bold small text-uppercase text-primary" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                Пассивные умения
            </span>
            <span class="badge bg-light text-dark border" style="font-size: 0.65rem;">
                <?= count($passiveSkills) ?>
            </span>
        </div>

        <?php if (empty($passiveSkills)): ?>
            <div class="text-muted small py-2 text-center fst-italic">Нет изученных пассивных умений</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($passiveSkills as $row): ?>
                    <?php
                    /** @var app\models\CharacterSkill $charSkill */
                    $charSkill = $row['charSkill'];
                    /** @var app\models\game\Skill $skill */
                    $skill = $row['skill'];
                    if (!$skill) continue;
                    $desc = $skill->getDescription();
                    ?>
                    <div class="list-group-item px-0 py-2 border-bottom">
                        <div class="d-flex align-items-start gap-2">
                            <img src="<?= Html::encode($skill->getIconUrl()) ?>"
                                 width="32"
                                 height="32"
                                 class="rounded border border-secondary flex-shrink-0 mt-1"
                                 alt="<?= Html::encode($skill->getName()) ?>">

                            <div class="w-100" style="line-height: 1.25;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark" style="font-size: 0.85rem;">
                                        <?= Html::encode($skill->getName()) ?>
                                    </span>
                                    <span class="badge bg-light text-secondary border py-1" style="font-size: 0.65rem;">
                                        <?= $charSkill->level ?> ур.
                                    </span>
                                </div>

                                <span class="badge bg-secondary py-0 mb-1" style="font-size: 0.6rem;">Пассивное</span>

                                <?php if ($desc): ?>
                                    <div class="text-muted small" style="font-size: 0.75rem;">
                                        <?= Html::encode($desc) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($skill->effects['passives'])): ?>
                                    <div class="text-success mt-1" style="font-size: 0.7rem;">
                                        <?php foreach ($skill->effects['passives'] as $pass): ?>
                                            <div>
                                                • <?= Html::encode($pass['stat']) ?>:
                                                <b><?= $pass['val'] > 0 ? "+{$pass['val']}" : $pass['val'] ?><?= $pass['mode'] === 'per' ? '%' : '' ?></b>
                                                <?= !empty($pass['condition']) ? '(' . Html::encode($pass['condition']) . ')' : '' ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
