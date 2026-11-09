<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */

use yii\bootstrap5\Html;
use app\components\game\CharacterEffectService;

$effects = CharacterEffectService::getEffects($character);
?>

<?php if (!empty($effects)): ?>
    <div class="card p-1 mb-2 bg-dark bg-opacity-10 border-0 shadow-sm">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <?php foreach ($effects as $eff): ?>
                <?php
                $isNegative = $eff->isDot() || ($eff->isStat() && $eff->value < 0) || $eff->isControl();
                $borderClass = $isNegative ? 'border-danger' : 'border-secondary';
                $badgeBg = $isNegative ? 'bg-danger' : 'bg-dark';

                // Текст всплывающей подсказки
                $tooltip = Html::encode($eff->id);
                if ($eff->stat) {
                    $sign = $eff->value > 0 ? '+' : '';
                    $unit = $eff->mode === 'per' ? '%' : '';
                    $tooltip .= " ({$eff->stat}: {$sign}{$eff->value}{$unit})";
                }
                ?>
                <div class="position-relative d-inline-block text-center flex-shrink-0"
                     style="width: 28px; height: 32px;"
                     title="<?= $tooltip ?>">
                    <img src="<?= Html::encode($eff->getIconUrl()) ?>"
                         width="26"
                         height="26"
                         class="rounded border <?= $borderClass ?>"
                         alt="<?= Html::encode($eff->id) ?>">

                    <!-- Таймер оставшегося времени -->
                    <span class="badge <?= $badgeBg ?> bg-opacity-75 p-0 position-absolute bottom-0 start-50 translate-middle-x text-white"
                          style="font-size: 0.58rem; line-height: 1; padding: 1px 2px !important; border-radius: 2px;">
                        <?= $eff->getFormattedTime() ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
