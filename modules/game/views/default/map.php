<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var app\models\game\Location|null $currentLocation */
/** @var array $regions */
/** @var string $activeRegion */

use yii\bootstrap5\Html;
use yii\helpers\Url;
use app\models\game\LocationRepository;

$this->title = 'Карта Мира — Телепорты';
?>

<div class="game-map">
    <!-- Шапка карты и баланс -->
    <div class="card p-2 mb-2 bg-light border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <b class="small text-uppercase text-muted" style="font-size: 0.72rem;">Телепортация по Карте</b>
                <div class="fw-bold text-dark" style="font-size: 0.85rem;">
                    Текущее место: <span class="text-primary"><?= Html::encode($currentLocation?->getName() ?? 'Неизвестно') ?></span>
                </div>
            </div>
            <div class="text-end small">
                <span class="text-muted">Адена:</span><br>
                <b class="text-warning text-dark"><?= number_format($character->getAdena(), 0, '', ' ') ?></b> <small>а</small>
            </div>
        </div>
    </div>

    <!-- Список регионов (Горизонтальные кнопки с прокруткой) -->
    <div class="d-flex gap-1 overflow-auto pb-2 mb-2" style="white-space: nowrap;">
        <?php foreach ($regions as $regId => $regData): ?>
            <?php $isActive = ($regId === $activeRegion); ?>
            <a href="<?= Url::to(['map', 'region' => $regId]) ?>"
               class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline-secondary bg-white' ?> py-1 px-2"
               style="font-size: 0.75rem;">
                <?= Html::encode($regData['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Локации выбранного региона -->
    <div class="card p-2 mb-3 border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
            <span class="fw-bold small text-uppercase text-muted" style="font-size: 0.72rem;">
                <?= Html::encode($regions[$activeRegion]['name']) ?>
            </span>
            <span class="badge bg-light text-dark border" style="font-size: 0.65rem;">
                <?= count($regions[$activeRegion]['locations']) ?> зон
            </span>
        </div>

        <div class="list-group list-group-flush">
            <?php foreach ($regions[$activeRegion]['locations'] as $loc): ?>
                <?php
                /** @var app\models\game\Location $loc */
                $isCurrent = ($currentLocation && $currentLocation->id === $loc->id);
                $cost = LocationRepository::calculateTeleportCost($loc, $currentLocation);
                $canAfford = ($character->getAdena() >= $cost);
                $isDangerous = ($loc->minLevel > $character->level);
                ?>
                <div class="list-group-item px-0 py-2 border-bottom <?= $isCurrent ? 'bg-light' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="text-truncate" style="line-height: 1.25;">
                            <div class="d-flex align-items-center gap-1">
                                <b class="text-dark" style="font-size: 0.82rem;"><?= Html::encode($loc->getName()) ?></b>
                                <?php if ($loc->isPeace): ?>
                                    <span class="badge bg-success py-0" style="font-size: 0.6rem;">Город</span>
                                <?php else: ?>
                                    <span class="badge <?= $isDangerous ? 'bg-danger' : 'bg-secondary' ?> py-0" style="font-size: 0.6rem;">
                                        Ур. <?= $loc->minLevel ?>–<?= $loc->maxLevel ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted text-truncate" style="font-size: 0.7rem;">
                                <?= Html::encode($loc->getDescription() ?: 'Окрестности региона') ?>
                            </div>
                        </div>

                        <!-- Кнопка телепорта -->
                        <div class="flex-shrink-0">
                            <?php if ($isCurrent): ?>
                                <span class="badge bg-light text-muted border py-1 px-2" style="font-size: 0.7rem;">Вы здесь</span>
                            <?php else: ?>
                                <?= Html::beginForm(['teleport', 'target' => $loc->id], 'post', ['class' => 'm-0']) ?>
                                <button type="submit"
                                        class="btn btn-sm <?= $canAfford ? 'btn-outline-primary' : 'btn-outline-secondary disabled' ?> py-1 px-2"
                                        style="font-size: 0.72rem;"
                                    <?= !$canAfford ? 'disabled' : '' ?>>
                                    <?= number_format($cost, 0, '', ' ') ?> а
                                </button>
                                <?= Html::endForm() ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Кнопка возврата в текущую локацию -->
    <a href="<?= Url::to(['index']) ?>" class="btn btn-outline-secondary w-100 py-2 small" style="font-size: 0.8rem;">
        ← Вернуться в игру
    </a>
</div>
