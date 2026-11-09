<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var app\models\game\Location $location */

use app\models\game\LocationRepository;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = $location->getName() . ' — ' . $location->getRegionName();
$isDead = $character->cur_hp <= 0;
?>

<div class="game-world">
    <?php if ($isDead): ?>
        <!-- Экран гибели персонажа -->
        <div class="card p-3 mb-2 border-danger text-center bg-dark text-white shadow-sm">
            <h5 class="text-danger mb-2">Вы погибли!</h5>
            <p class="small text-muted mb-3">Ваша душа парит над телом. Вернитесь к жизни в ближайшем поселении.</p>
            <?= Html::beginForm(['respawn'], 'post') ?>
            <button type="submit" class="btn btn-danger w-100 py-2 fw-bold">В город (To Village)</button>
            <?= Html::endForm() ?>
        </div>
    <?php else: ?>

        <!-- Карточка текущей локации -->
        <div class="card p-2 mb-2 border-0 shadow-sm">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="text-muted small" style="font-size: 0.7rem;"><?= Html::encode($location->getRegionName()) ?></span>
                    <h6 class="mb-0 fw-bold text-dark"><?= Html::encode($location->getName()) ?></h6>
                </div>
                <div>
                    <?php if ($location->isPeace): ?>
                        <span class="badge bg-success">Мирная зона</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Боевая зона (<?= $location->minLevel ?>–<?= $location->maxLevel ?> ур.)</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($desc = $location->getDescription()): ?>
                <div class="small text-muted mt-2 border-top pt-1" style="font-size: 0.75rem;">
                    <?= Html::encode($desc) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 1. ЕСЛИ ЭТО БОЕВАЯ ЗОНА (ОХОТА) -->
        <?php if (!$location->isPeace): ?>
            <div class="card p-2 mb-2 bg-light border-0 shadow-sm">
                <a href="<?= Url::to(['/game/battle/hunt']) ?>" class="btn btn-danger w-100 py-2 fw-bold mb-2 shadow-sm">
                    ⚔ Найти противника (Охота)
                </a>

                <!-- Список уникальных монстров зоны -->
                <div class="fw-bold small border-bottom pb-1 mb-1 text-muted" style="font-size: 0.72rem;">Монстры в этой зоне:</div>
                <?php $regularMonsters = $location->getRegularMonsters(); ?>
                <?php if (empty($regularMonsters)): ?>
                    <div class="text-muted small py-1 fst-italic">Здесь сейчас спокойно</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($regularMonsters as $mob): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-1 bg-transparent border-bottom">
                                <div>
                                    <span class="badge bg-secondary"><?= $mob->level ?></span>
                                    <b class="ms-1" style="font-size: 0.8rem;"><?= Html::encode($mob->getName()) ?></b>
                                    <small class="text-muted">(HP: <?= $mob->hp ?>)</small>
                                </div>
                                <a href="<?= Url::to(['/game/battle/start', 'npc_id' => $mob->id]) ?>" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.72rem;">
                                    Атаковать
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- 2. ГОРОДСКИЕ СЛУЖБЫ -->
            <div class="card p-2 mb-2 border-0 shadow-sm">
                <div class="fw-bold small border-bottom pb-1 mb-2 text-muted" style="font-size: 0.72rem;">Городские службы</div>
                <div class="list-group list-group-flush small" style="font-size: 0.78rem;">
                    <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between py-2 disabled">
                        <span>Оружие и броня</span>
                        <span class="text-muted">скоро</span>
                    </a>
                    <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between py-2 disabled">
                        <span>Бакалея (зелья, свитки)</span>
                        <span class="text-muted">скоро</span>
                    </a>
                    <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between py-2 disabled">
                        <span>Склад (Warehouse)</span>
                        <span class="text-muted">скоро</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- ПЕРЕМЕЩЕНИЕ: БОЛЬШАЯ КНОПКА КАРТЫ МИРА -->
        <div class="card p-2 mb-2 border-0 shadow-sm">
            <a href="<?= Url::to(['map']) ?>" class="btn btn-primary w-100 py-2 fw-bold d-flex align-items-center justify-content-center gap-2">
                <span>🗺 Карта Мира (Телепорты)</span>
            </a>
            <?php if (!$location->isPeace): ?>
                <?= Html::beginForm(['teleport', 'target' => $location->getRespawnTownId()], 'post', ['class' => 'mt-2 mb-0']) ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary w-100 py-1" style="font-size: 0.75rem;">
                    Вернуться в ближайший город (<?= number_format(LocationRepository::calculateTeleportCost(LocationRepository::find($location->getRespawnTownId())), 0, '', ' ') ?> а)
                </button>
                <?= Html::endForm() ?>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>
