<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var array $classInfo */
/** @var app\models\game\Race|null $race */
/** @var float $expPercent */
/** @var int $nextLevelExp */

use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = $character->name . ' — Характеристики';
$maxHp = $character->getMaxHp();
$maxMp = $character->getMaxMp();
$maxCp = $character->getMaxCp();

$hpPct = min(100, round(($character->cur_hp / max(1, $maxHp)) * 100));
$mpPct = min(100, round(($character->cur_mp / max(1, $maxMp)) * 100));
$cpPct = min(100, round(($character->cur_cp / max(1, $maxCp)) * 100));
?>

<div class="character-profile">
    <!-- Шапка профиля -->
    <div class="card p-2 mb-2 bg-light border-0 shadow-sm">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <b class="fs-6 text-dark"><?= Html::encode($character->name) ?></b>
                <span class="badge bg-secondary ms-1"><?= $character->level ?> ур.</span>
            </div>
            <a href="<?= Url::to(['list']) ?>" class="small text-muted text-decoration-none">Сменить персонажа</a>
        </div>
        <div class="small text-muted mt-1">
            <?= $race ? Html::encode($race->name) : '' ?> • <?= Html::encode($classInfo['name'] ?? 'Воин') ?>
        </div>
    </div>

    <!-- Ресурсные полосы (CP / HP / MP / EXP) -->
    <div class="card p-2 mb-2 border-0 shadow-sm">
        <div class="d-flex justify-content-between small text-warning mb-1" style="font-size: 0.72rem;">
            <span>CP: <?= (int)$character->cur_cp ?> / <?= $maxCp ?></span>
            <span><?= $cpPct ?>%</span>
        </div>
        <div class="progress mb-2" style="height: 5px;">
            <div class="progress-bar bg-warning" style="width: <?= $cpPct ?>%"></div>
        </div>

        <div class="d-flex justify-content-between small text-danger mb-1" style="font-size: 0.72rem;">
            <span>HP: <?= (int)$character->cur_hp ?> / <?= $maxHp ?></span>
            <span><?= $hpPct ?>%</span>
        </div>
        <div class="progress mb-2" style="height: 6px;">
            <div class="progress-bar bg-danger" style="width: <?= $hpPct ?>%"></div>
        </div>

        <div class="d-flex justify-content-between small text-primary mb-1" style="font-size: 0.72rem;">
            <span>MP: <?= (int)$character->cur_mp ?> / <?= $maxMp ?></span>
            <span><?= $mpPct ?>%</span>
        </div>
        <div class="progress mb-2" style="height: 5px;">
            <div class="progress-bar bg-primary" style="width: <?= $mpPct ?>%"></div>
        </div>

        <div class="d-flex justify-content-between small text-success mb-1" style="font-size: 0.7rem;">
            <span>EXP: <?= number_format($character->exp, 0, '', ' ') ?> / <?= number_format($nextLevelExp, 0, '', ' ') ?></span>
            <span><?= $expPercent ?>%</span>
        </div>
        <div class="progress" style="height: 4px;">
            <div class="progress-bar bg-success" style="width: <?= $expPercent ?>%"></div>
        </div>
    </div>

    <!-- Боевые характеристики -->
    <div class="card p-2 mb-2 border-0 shadow-sm">
        <div class="fw-bold small border-bottom pb-1 mb-2 text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.5px;">
            Боевые параметры
        </div>
        <div class="row g-2 small" style="font-size: 0.78rem;">
            <div class="col-6 d-flex justify-content-between border-bottom pb-1">
                <span class="text-muted">Физ. Атака (P.Atk):</span>
                <b><?= $character->getPAtk() ?></b>
            </div>
            <div class="col-6 d-flex justify-content-between border-bottom pb-1">
                <span class="text-muted">Физ. Защита (P.Def):</span>
                <b><?= $character->getPDef() ?></b>
            </div>
            <div class="col-6 d-flex justify-content-between border-bottom pb-1">
                <span class="text-muted">Маг. Атака (M.Atk):</span>
                <b><?= $character->getMAtk() ?></b>
            </div>
            <div class="col-6 d-flex justify-content-between border-bottom pb-1">
                <span class="text-muted">Маг. Защита (M.Def):</span>
                <b><?= $character->getMDef() ?></b>
            </div>
            <div class="col-6 d-flex justify-content-between">
                <span class="text-muted">Скор. Атаки (Atk.Spd):</span>
                <b><?= $character->getAttackSpeed() ?></b>
            </div>
            <div class="col-6 d-flex justify-content-between">
                <span class="text-muted">Скор. Каста (Cast.Spd):</span>
                <b><?= $character->getCastSpeed() ?></b>
            </div>
        </div>
    </div>

    <!-- Базовые атрибуты расы -->
    <div class="card p-2 mb-2 bg-light border-0 shadow-sm">
        <div class="fw-bold small border-bottom pb-1 mb-2 text-uppercase text-muted" style="font-size: 0.72rem; letter-spacing: 0.5px;">
            Базовые характеристики
        </div>
        <div class="d-flex justify-content-around text-center small">
            <div><span class="text-muted" style="font-size: 0.7rem;">STR</span><br><b><?= $character->str ?></b></div>
            <div><span class="text-muted" style="font-size: 0.7rem;">DEX</span><br><b><?= $character->dex ?></b></div>
            <div><span class="text-muted" style="font-size: 0.7rem;">CON</span><br><b><?= $character->con ?></b></div>
            <div><span class="text-muted" style="font-size: 0.7rem;">INT</span><br><b><?= $character->int ?></b></div>
            <div><span class="text-muted" style="font-size: 0.7rem;">WIT</span><br><b><?= $character->wit ?></b></div>
            <div><span class="text-muted" style="font-size: 0.7rem;">MEN</span><br><b><?= $character->men ?></b></div>
        </div>
    </div>

    <!-- Социальные данные -->
    <div class="card p-2 mb-3 border-0 shadow-sm small" style="font-size: 0.78rem;">
        <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">Очки умений (SP):</span>
            <b><?= number_format($character->sp, 0, '', ' ') ?></b>
        </div>
        <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">Адена:</span>
            <b class="text-warning text-dark"><?= number_format($character->getAdena(), 0, '', ' ') ?> а</b>
        </div>
        <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">PvP / PK:</span>
            <span><?= $character->pvp_kills ?> / <span class="text-danger"><?= $character->pk_kills ?></span></span>
        </div>
        <div class="d-flex justify-content-between py-1">
            <span class="text-muted">Карма:</span>
            <span class="<?= $character->karma > 0 ? 'text-danger fw-bold' : '' ?>"><?= $character->karma ?></span>
        </div>
    </div>
</div>
