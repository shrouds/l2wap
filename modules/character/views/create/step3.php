<?php
/** @var yii\web\View $this */
/** @var app\modules\character\models\StepProfileForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Создание персонажа: Имя и пол';
?>
<div class="wizard-step3">
    <?= $this->render('_steps', ['currentStep' => 3]) ?>

    <div class="card p-3 mb-3">
        <?php $form = ActiveForm::begin(); ?>

        <?= $form->field($model, 'name')->textInput([
            'maxlength' => 16,
            'placeholder' => 'Никнейм (от 3 до 16 букв)',
            'autofocus' => true
        ]) ?>

        <?= $form->field($model, 'gender')->radioList([
            0 => 'Мужской (Male)',
            1 => 'Женский (Female)',
        ], ['class' => 'mt-2']) ?>

        <div class="d-flex gap-2 mt-3">
            <a href="<?= Url::to(['step2']) ?>" class="btn btn-outline-secondary w-50">← Назад</a>
            <?= Html::submitButton('Далее → Проверка', ['class' => 'btn btn-primary w-50']) ?>
        </div>

        <?php ActiveForm::end(); ?>
    </div>
</div>
