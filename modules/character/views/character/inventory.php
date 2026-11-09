<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var array $paperdoll */
/** @var app\models\CharacterItem[] $bagItems */
/** @var int $totalWeight */
/** @var int $maxWeight */
/** @var float $weightPercent */
/** @var int $inventorySlotsCount */
/** @var int $maxSlots */

use yii\bootstrap5\Html;

$this->title = 'Инвентарь — ' . Html::encode($character->name);

$slotLabels = [
    'head'      => 'Голова',
    'chest'     => 'Доспех (верх)',
    'legs'      => 'Штаны (низ)',
    'gloves'    => 'Перчатки',
    'feet'      => 'Ботинки',
    'rhand'     => 'Оружие',
    'lhand'     => 'Щит / Стрелы',
    'neck'      => 'Ожерелье',
    'rear_1'    => 'Серьга (R)',
    'rear_2'    => 'Серьга (L)',
    'rfinger_1' => 'Кольцо (R)',
    'rfinger_2' => 'Кольцо (L)',
];

$armorSlots = ['head', 'chest', 'legs', 'gloves', 'feet'];
$weaponJewelSlots = ['rhand', 'lhand', 'neck', 'rear_1', 'rear_2', 'rfinger_1', 'rfinger_2'];

$gradeBadges = [
    'none' => ['label' => 'NG', 'class' => 'bg-secondary'],
    'ng'   => ['label' => 'NG', 'class' => 'bg-secondary'],
    'd'    => ['label' => 'D', 'class' => 'bg-info text-dark'],
    'c'    => ['label' => 'C', 'class' => 'bg-success'],
    'b'    => ['label' => 'B', 'class' => 'bg-danger'],
    'a'    => ['label' => 'A', 'class' => 'bg-primary'],
    's'    => ['label' => 'S', 'class' => 'bg-warning text-dark'],
];
?>

<div class="character-inventory">
    <!-- Статус-панель: Адена, Вес, Слоты, Соски -->
    <div class="card p-2 mb-2 bg-light border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center small mb-1">
            <span>Адена: <b class="text-warning text-dark"><?= number_format($character->getAdena(), 0, '', ' ') ?></b> <small class="text-muted">а</small></span>
            <span>Слоты: <b><?= $inventorySlotsCount ?></b> / <?= $maxSlots ?></span>
            <span class="<?= $weightPercent >= 50 ? 'text-danger fw-bold' : 'text-muted' ?>">
                Вес: <?= number_format($totalWeight, 0, '', ' ') ?> / <?= number_format($maxWeight, 0, '', ' ') ?> (<?= $weightPercent ?>%)
            </span>
        </div>
        <div class="progress mb-2" style="height: 5px;">
            <div class="progress-bar <?= $weightPercent >= 50 ? 'bg-danger' : 'bg-secondary' ?>" style="width: <?= min(100, $weightPercent) ?>%"></div>
        </div>
        <div class="d-flex gap-1">
            <?= Html::beginForm(['toggle-shot', 'type' => 'soulshot'], 'post', ['class' => 'w-50']) ?>
            <button type="submit" class="btn btn-sm py-1 w-100 <?= $character->auto_soulshot ? 'btn-success' : 'btn-outline-secondary' ?>" style="font-size: 0.75rem;">
                Soulshot: <b><?= $character->auto_soulshot ? 'ВКЛ' : 'ВЫКЛ' ?></b>
            </button>
            <?= Html::endForm() ?>

            <?= Html::beginForm(['toggle-shot', 'type' => 'spiritshot'], 'post', ['class' => 'w-50']) ?>
            <button type="submit" class="btn btn-sm py-1 w-100 <?= $character->auto_spiritshot ? 'btn-primary' : 'btn-outline-secondary' ?>" style="font-size: 0.75rem;">
                Spiritshot: <b><?= $character->auto_spiritshot ? 'ВКЛ' : 'ВЫКЛ' ?></b>
            </button>
            <?= Html::endForm() ?>
        </div>
    </div>

    <!-- Снаряжение (Кукла) -->
    <div class="card p-2 mb-2 border-0 shadow-sm">
        <div class="fw-bold small border-bottom pb-1 mb-2 text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.5px;">
            Надетое снаряжение
        </div>

        <?php
        $renderSlotRow = function(string $slotKey) use ($paperdoll, $slotLabels, $gradeBadges) {
            if ($slotKey === 'legs' && !empty($paperdoll['chest']) && $paperdoll['chest']->equip_slot === 'onepiece') {
                return '<div class="py-1 text-muted fst-italic small border-bottom" style="font-size:0.75rem;">[Цельный доспех]</div>';
            }
            if ($slotKey === 'lhand' && !empty($paperdoll['rhand']) && $paperdoll['rhand']->equip_slot === 'lrhand') {
                return '<div class="py-1 text-muted fst-italic small border-bottom" style="font-size:0.75rem;">[Двуручное оружие]</div>';
            }

            /** @var app\models\CharacterItem|null $equippedItem */
            $equippedItem = $paperdoll[$slotKey] ?? null;
            $slotTitle = $slotLabels[$slotKey] ?? $slotKey;

            ob_start();
            ?>
            <div class="d-flex align-items-center justify-content-between py-1 border-bottom" style="font-size: 0.8rem;">
                <div class="d-flex align-items-center gap-2 text-truncate me-1" style="max-width: 80%;">
                    <?php if ($equippedItem): ?>
                        <?php
                        $tpl = $equippedItem->getItem();
                        $grade = strtolower($tpl?->grade ?? 'none');
                        $gradeBadge = $gradeBadges[$grade] ?? ['label' => strtoupper($grade), 'class' => 'bg-secondary'];
                        ?>
                        <img src="<?= Html::encode($tpl?->getIconUrl()) ?>" width="24" height="24" class="rounded border flex-shrink-0" alt="">
                        <div class="text-truncate" style="line-height: 1.2;">
                            <div class="text-truncate">
                                <b><?= Html::encode($tpl?->getName()) ?></b>
                                <?= $equippedItem->enchant > 0 ? "<span class='text-danger fw-bold'>+{$equippedItem->enchant}</span>" : '' ?>
                            </div>
                            <div class="text-muted" style="font-size: 0.68rem;">
                                <span><?= $slotTitle ?></span>
                                <?php if ($tpl?->isWeapon()): ?>
                                    • Атк: <?= $tpl->pAtk ?>/<?= $tpl->mAtk ?>
                                <?php elseif ($tpl?->isArmor()): ?>
                                    • Защ: <?= $tpl->pDef ?>
                                <?php elseif ($tpl?->type === 'accessary'): ?>
                                    • М.Защ: <?= $tpl->mDef ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-muted" style="line-height: 1.2;">
                            <span class="fst-italic">— <?= $slotTitle ?> —</span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($equippedItem): ?>
                    <?= Html::beginForm(['unequip', 'id' => $equippedItem->id], 'post', ['class' => 'm-0']) ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.72rem;">Снять</button>
                    <?= Html::endForm() ?>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        };
        ?>

        <div class="row g-2">
            <div class="col-6">
                <div class="small fw-bold text-muted mb-1" style="font-size: 0.68rem;">ДОСПЕХИ</div>
                <?php foreach ($armorSlots as $slot) echo $renderSlotRow($slot); ?>
            </div>
            <div class="col-6">
                <div class="small fw-bold text-muted mb-1" style="font-size: 0.68rem;">ОРУЖИЕ И БИЖУТЕРИЯ</div>
                <?php foreach ($weaponJewelSlots as $slot) echo $renderSlotRow($slot); ?>
            </div>
        </div>
    </div>

    <!-- Рюкзак с описанием предметов -->
    <div class="card p-2 mb-3 border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
            <span class="fw-bold small text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                Рюкзак
            </span>
            <span class="badge bg-light text-dark border" style="font-size: 0.65rem;">
                <?= count($bagItems) ?> предм.
            </span>
        </div>

        <?php if (empty($bagItems)): ?>
            <div class="text-muted small py-3 text-center fst-italic">Рюкзак пуст</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($bagItems as $charItem): ?>
                    <?php
                    $tpl = $charItem->getItem();
                    if (!$tpl) continue;

                    $gradeKey = strtolower($tpl->grade ?? 'none');
                    $badgeData = $gradeBadges[$gradeKey] ?? ['label' => strtoupper($gradeKey), 'class' => 'bg-secondary'];
                    $isEquippable = $tpl->isWeapon() || $tpl->isArmor() || $tpl->type === 'accessary';
                    $desc = $tpl->getDescription();
                    ?>
                    <div class="list-group-item px-0 py-2 border-bottom">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="d-flex align-items-start gap-2 text-truncate me-1">
                                <img src="<?= Html::encode($tpl->getIconUrl()) ?>"
                                     width="32"
                                     height="32"
                                     class="rounded border flex-shrink-0 mt-1"
                                     alt="<?= Html::encode($tpl->getName()) ?>">

                                <div class="text-truncate" style="line-height: 1.25;">
                                    <div class="text-truncate" style="font-size: 0.85rem;">
                                        <b><?= Html::encode($tpl->getName()) ?></b>
                                        <?= $charItem->enchant > 0 ? "<span class='text-danger fw-bold'>+{$charItem->enchant}</span>" : '' ?>
                                        <?php if ($charItem->count > 1): ?>
                                            <span class="badge bg-secondary py-0" style="font-size: 0.65rem;">x<?= number_format($charItem->count, 0, '', ' ') ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Боевые характеристики предмета -->
                                    <div class="d-flex flex-wrap gap-1 my-1">
                                        <span class="badge <?= $badgeData['class'] ?> py-0" style="font-size: 0.6rem;">
                                            <?= $badgeData['label'] ?> гр.
                                        </span>
                                        <?php if ($tpl->isWeapon()): ?>
                                            <span class="badge bg-light text-danger border py-0" style="font-size: 0.6rem;">
                                                Физ.Атк: <?= $tpl->pAtk ?> / Маг.Атк: <?= $tpl->mAtk ?>
                                            </span>
                                            <span class="badge bg-light text-muted border py-0" style="font-size: 0.6rem;">
                                                Скор.Атк: <?= $tpl->attackSpeed ?>
                                            </span>
                                        <?php elseif ($tpl->isArmor()): ?>
                                            <span class="badge bg-light text-primary border py-0" style="font-size: 0.6rem;">
                                                Физ.Защ: <?= $tpl->pDef ?>
                                            </span>
                                        <?php elseif ($tpl->isShield()): ?>
                                            <span class="badge bg-light text-info border py-0" style="font-size: 0.6rem;">
                                                Щит: <?= $tpl->shieldDef ?> (<?= $tpl->shieldRate ?>%)
                                            </span>
                                        <?php elseif ($tpl->type === 'accessary'): ?>
                                            <span class="badge bg-light text-success border py-0" style="font-size: 0.6rem;">
                                                Маг.Защ: <?= $tpl->mDef ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Текстовое описание предмета -->
                                    <?php if ($desc): ?>
                                        <div class="text-muted text-truncate" style="font-size: 0.72rem;">
                                            <?= Html::encode($desc) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="text-muted" style="font-size: 0.68rem;">
                                        Вес: <?= number_format($tpl->weight * $charItem->count, 0, '', ' ') ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Кнопка действия -->
                            <div class="flex-shrink-0 mt-1">
                                <?php if ($isEquippable): ?>
                                    <?= Html::beginForm(['equip', 'id' => $charItem->id], 'post') ?>
                                    <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.72rem;">Надеть</button>
                                    <?= Html::endForm() ?>
                                <?php elseif ($tpl->type === 'etcitem'): ?>
                                    <?= Html::beginForm(['use', 'id' => $charItem->id], 'post') ?>
                                    <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size: 0.72rem;">Исп.</button>
                                    <?= Html::endForm() ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
