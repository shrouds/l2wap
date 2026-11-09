<?php
/** @var int $currentStep */
$steps = [
    1 => '1. Раса',
    2 => '2. Класс',
    3 => '3. Профиль',
    4 => '4. Вход',
];
?>
<div class="card p-2 mb-3 bg-light text-center small">
    <div class="d-flex justify-content-between">
        <?php foreach ($steps as $stepNum => $stepLabel): ?>
            <span class="<?= $currentStep === $stepNum ? 'fw-bold text-danger' : ($currentStep > $stepNum ? 'text-success' : 'text-muted') ?>">
                <?= $stepLabel ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
