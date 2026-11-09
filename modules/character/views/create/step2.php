<?php
/** @var yii\web\View $this */
/** @var app\modules\character\models\StepClassForm $model */
/** @var app\models\game\Race $race */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;
use app\models\game\PcParamsRepository;

$this->title = 'Создание персонажа: Выбор класса';
?>
<div class="wizard-step2">
    <?= $this->render('_steps', ['currentStep' => 2]) ?>

    <div class="alert alert-secondary py-2 small mb-3">
        Выбранная раса: <b><?= Html::encode($race->name) ?></b>
    </div>

    <?php $form = ActiveForm::begin(); ?>

    <div class="list-group mb-3">
        <?php foreach ($race->starterClassIds as $classId): ?>
            <?php
            $classInfo = PcParamsRepository::getClass($classId);
            $className = $classInfo['name'] ?? "Класс {$classId}";
            ?>
            <label class="list-group-item list-group-item-action d-flex align-items-start gap-2" style="cursor: pointer;">
                <input class="form-check-input mt-1" type="radio" name="StepClassForm[class_id]" value="<?= $classId ?>" <?= $model->class_id === $classId ? 'checked' : '' ?> required>
                <div>
                    <b><?= Html::encode($className) ?></b>
                    <div class="small text-muted mt-1">
                        STR: <?= $classInfo['str'] ?> | DEX: <?= $classInfo['dex'] ?> | CON: <?= $classInfo['con'] ?> |
                        INT: <?= $classInfo['int'] ?> | WIT: <?= $classInfo['wit'] ?> | MEN: <?= $classInfo['men'] ?>
                    </div>
                </div>
            </label>
        <?php endforeach; ?>
    </div>

    <?= Html::error($model, 'class_id', ['class' => 'text-danger small mb-2']) ?>

    <div class="d-flex gap-2">
        <a href="<?= Url::to(['step1']) ?>" class="btn btn-outline-secondary w-50">← Назад</a>
        <?= Html::submitButton('Далее → Имя', ['class' => 'btn btn-primary w-50']) ?>
    </div>

    <?php ActiveForm::end(); ?>
</div>
