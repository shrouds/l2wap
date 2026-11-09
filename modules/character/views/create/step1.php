<?php
/** @var yii\web\View $this */
/** @var app\modules\character\models\StepRaceForm $model */
/** @var app\models\game\Race[] $races */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Создание персонажа: Выбор расы';
?>
<div class="wizard-step1">
    <?= $this->render('_steps', ['currentStep' => 1]) ?>

    <?php $form = ActiveForm::begin(); ?>

    <div class="list-group mb-3">
        <?php foreach ($races as $race): ?>
            <label class="list-group-item list-group-item-action d-flex align-items-start gap-2" style="cursor: pointer;">
                <input class="form-check-input mt-1" type="radio" name="StepRaceForm[race_id]" value="<?= $race->id ?>" <?= $model->race_id === $race->id ? 'checked' : '' ?> required>
                <div>
                    <b><?= Html::encode($race->name) ?></b>
                    <div class="small text-muted mt-1"><?= Html::encode($race->description) ?></div>
                </div>
            </label>
        <?php endforeach; ?>
    </div>

    <?= Html::error($model, 'race_id', ['class' => 'text-danger small mb-2']) ?>

    <div class="d-flex gap-2">
        <a href="<?= Url::to(['/character/default/index']) ?>" class="btn btn-outline-secondary w-50">Отмена</a>
        <?= Html::submitButton('Далее → Класс', ['class' => 'btn btn-primary w-50']) ?>
    </div>

    <?php ActiveForm::end(); ?>
</div>
