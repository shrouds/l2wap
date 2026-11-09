<?php
/** @var yii\web\View $this */
/** @var app\models\Character[] $characters */

use yii\bootstrap5\Html;
use yii\helpers\Url;
use app\models\game\PcParamsRepository;

$this->title = 'Персонажи аккаунта';
?>

<div class="character-list">
    <h5 class="mb-3">Ваши персонажи</h5>

    <div class="list-group mb-3">
        <?php foreach ($characters as $char): ?>
            <?php
            $classInfo = PcParamsRepository::getClass($char->class_id);
            $className = $classInfo['name'] ?? 'Воин';
            $loc = $char->getLocation();
            $locName = $loc ? $loc->getName() : $char->location_id;
            ?>
            <div class="list-group-item d-flex justify-content-between align-items-center <?= $char->current ? 'border-primary' : '' ?>">
                <div>
                    <b><?= Html::encode($char->name) ?></b>
                    <small class="text-muted">(<?= $char->level ?> ур. <?= Html::encode($className) ?>)</small>
                    <div class="small text-muted">Локация: <?= Html::encode($locName) ?></div>
                </div>

                <div class="d-flex gap-2 align-items-center">
                    <?php if ($char->current): ?>
                        <a href="<?= Url::to(['/game/index']) ?>" class="btn btn-sm btn-success">В игру</a>
                    <?php else: ?>
                        <a href="<?= Url::to(['select', 'id' => $char->id]) ?>" class="btn btn-sm btn-outline-primary">Выбрать</a>
                    <?php endif; ?>

                    <?= Html::beginForm(['delete', 'id' => $char->id], 'post') ?>
                    <?= Html::submitButton('✕', [
                        'class' => 'btn btn-sm btn-outline-danger',
                        'data-confirm' => "Удалить персонажа {$char->name}?",
                    ]) ?>
                    <?= Html::endForm() ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div>
        <a href="<?= Url::to(['/character/create/step1']) ?>" class="btn btn-primary w-100">+ Создать нового персонажа</a>
    </div>
</div>
