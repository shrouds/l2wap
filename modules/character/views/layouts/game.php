<?php
/** @var yii\web\View $this */
/** @var string $content */

use yii\bootstrap5\Html;
use yii\helpers\Url;
use app\models\Character;

$character = Character::getCurrent((int)Yii::$app->user->id);

$maxHp = $character?->maxHp ?? 1;
$maxMp = $character?->maxMp ?? 1;
$maxCp = $character?->maxCp ?? 1;

$hpPct = min(100, (int)round((($character?->cur_hp ?? 0) / max(1, $maxHp)) * 100));
$mpPct = min(100, (int)round((($character?->cur_mp ?? 0) / max(1, $maxMp)) * 100));
$cpPct = min(100, (int)round((($character?->cur_cp ?? 0) / max(1, $maxCp)) * 100));

$currentRoute = Yii::$app->controller->route;
?>
<?php $this->beginPage() ?>
    <!DOCTYPE html>
    <html lang="<?= Yii::$app->language ?>">
    <head>
        <meta charset="<?= Yii::$app->charset ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <?= Html::csrfMetaTags() ?>
        <title><?= Html::encode($this->title) ?></title>
        <?php $this->head() ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <style>
            body { background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .wap-container { max-width: 480px; margin: 0 auto; background: #fff; min-height: 100vh; }
            .progress { border-radius: 2px; }
            .badge { font-weight: 500; }
        </style>
    </head>
    <body>
    <?php $this->beginBody() ?>

    <div class="wap-container shadow-sm p-2">

        <?php if ($character !== null): ?>
            <!-- Единая навигация WAP -->
            <div class="game-hud mb-2">
                <div class="btn-group w-100 mb-2">
                    <a href="<?= Url::to(['/game/default/index']) ?>"
                       class="btn btn-sm <?= str_starts_with($currentRoute, 'game/') ? 'btn-primary active' : 'btn-outline-secondary' ?>">
                        Мир
                    </a>
                    <a href="<?= Url::to(['/character/character/index']) ?>"
                       class="btn btn-sm <?= str_contains($currentRoute, 'character/index') ? 'btn-primary active' : 'btn-outline-secondary' ?>">
                        Герой
                    </a>
                    <a href="<?= Url::to(['/character/character/inventory']) ?>"
                       class="btn btn-sm <?= str_contains($currentRoute, 'character/inventory') ? 'btn-primary active' : 'btn-outline-secondary' ?>">
                        Сумка
                    </a>
                    <a href="<?= Url::to(['/character/character/skills']) ?>"
                       class="btn btn-sm <?= str_contains($currentRoute, 'character/skills') ? 'btn-primary active' : 'btn-outline-secondary' ?>">
                        Скиллы
                    </a>
                </div>

                <!-- HUD полосок персонажа -->
                <div class="card p-2 bg-light border-0 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <b><?= Html::encode($character->name) ?></b>
                            <span class="badge bg-secondary ms-1"><?= $character->level ?> ур.</span>
                            <small class="text-muted ms-1"><?= Html::encode($character->className) ?></small>
                        </div>
                        <div class="small">
                            <span class="text-warning text-dark fw-bold"><?= number_format($character->getAdena(), 0, '', ' ') ?></span>
                            <span class="text-muted">а</span>
                        </div>
                    </div>

                    <!-- Полоски CP / HP / MP / EXP -->
                    <div class="d-flex justify-content-between text-warning" style="font-size: 0.7rem; line-height: 1;">
                        <span>CP: <?= (int)$character->cur_cp ?>/<?= $maxCp ?></span>
                        <span><?= $cpPct ?>%</span>
                    </div>
                    <div class="progress mb-1" style="height: 4px;">
                        <div class="progress-bar bg-warning" style="width: <?= $cpPct ?>%"></div>
                    </div>

                    <div class="d-flex justify-content-between text-danger" style="font-size: 0.7rem; line-height: 1;">
                        <span>HP: <?= (int)$character->cur_hp ?>/<?= $maxHp ?></span>
                        <span><?= $hpPct ?>%</span>
                    </div>
                    <div class="progress mb-1" style="height: 5px;">
                        <div class="progress-bar bg-danger" style="width: <?= $hpPct ?>%"></div>
                    </div>

                    <div class="d-flex justify-content-between text-primary" style="font-size: 0.7rem; line-height: 1;">
                        <span>MP: <?= (int)$character->cur_mp ?>/<?= $maxMp ?></span>
                        <span><?= $mpPct ?>%</span>
                    </div>
                    <div class="progress mb-1" style="height: 4px;">
                        <div class="progress-bar bg-primary" style="width: <?= $mpPct ?>%"></div>
                    </div>

                    <div class="d-flex justify-content-between text-success" style="font-size: 0.68rem; line-height: 1;">
                        <span>EXP: <?= number_format($character->exp, 0, '', ' ') ?></span>
                        <span><?= $character->expPercent ?>%</span>
                    </div>
                    <div class="progress" style="height: 3px;">
                        <div class="progress-bar bg-success" style="width: <?= $character->expPercent ?>%"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Активные эффекты и баффы персонажа -->
        <?= $this->render('/common/_buffs', ['character' => $character]) ?>
        <!-- Активные эффекты и баффы персонажа -->
        <?= $this->render('/common/_buffs', ['character' => $character]) ?>
        <!-- Системные уведомления (Flash) -->
        <?php foreach (Yii::$app->session->getAllFlashes() as $type => $message): ?>
            <div class="alert alert-<?= $type === 'error' ? 'danger' : $type ?> py-1 px-2 small mb-2">
                <?= $message ?>
            </div>
        <?php endforeach; ?>

        <!-- Основной контент действия -->
        <main>
            <?= $content ?>
        </main>
    </div>

    <?php $this->endBody() ?>
    </body>
    </html>
<?php $this->endPage() ?>
