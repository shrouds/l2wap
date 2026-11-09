<?php
/** @var yii\web\View $this */
/** @var array $data */
/** @var app\models\game\Race $race */
/** @var array $classData */
/** @var app\models\game\InitialItem[] $equipment */

use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Подтверждение персонажа';
?>
<div class="wizard-confirm">
    <?= $this->render('_steps', ['currentStep' => 4]) ?>

    <div class="card p-3 mb-3">
        <h5 class="text-danger mb-3"><?= Html::encode($data['name']) ?></h5>

        <table class="table table-sm table-borderless small mb-0">
            <tr>
                <td class="text-muted">Раса:</td>
                <td><b><?= Html::encode($race->name) ?></b></td>
            </tr>
            <tr>
                <td class="text-muted">Класс:</td>
                <td><b><?= Html::encode($classData['name'] ?? '') ?></b></td>
            </tr>
            <tr>
                <td class="text-muted">Пол:</td>
                <td><?= $data['gender'] === 1 ? 'Женский' : 'Мужской' ?></td>
            </tr>
            <tr>
                <td class="text-muted">Стартовый город:</td>
                <td>Деревня новичков (<?= $race->startLocationId ?>)</td>
            </tr>
        </table>
    </div>

    <div class="card p-3 mb-3 small">
        <b>Стартовое снаряжение:</b>
        <ul class="mb-0 mt-1 ps-3 text-muted">
            <?php foreach ($equipment as $initItem): ?>
                <li><img height="16" width="16" src="<?=$initItem->item->getIconUrl()?>" alt=""> <?= Html::encode($initItem->item->getName('en')) ?> (x<?= $initItem->count ?>) </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?= Html::beginForm(['save'], 'post') ?>
    <div class="d-flex gap-2">
        <a href="<?= Url::to(['step3']) ?>" class="btn btn-outline-secondary w-50">← Назад</a>
        <?= Html::submitButton('Войти в мир!', ['class' => 'btn btn-danger w-50 py-2 fw-bold']) ?>
    </div>
    <?= Html::endForm() ?>
</div>
