<?php
/** @var yii\web\View $this */
/** @var app\models\Character $character */
/** @var app\models\game\battle\BattleSession $session */

use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Арена боя';
$target = $session->getTarget();
$avatar = $character->getBattleAvatar();
$location = $character->getLocation();
$bgType = $location ? $location->type : 'field';
?>

<style>
    .battle-container {
        max-width: 480px;
        margin: 0 auto;
    }
    .arena-stage {
        position: relative;
        background: #151515 url('/images/locations/bg_<?= $bgType ?>.jpg') center/cover no-repeat;
        border: 2px solid #3e3529;
        border-radius: 6px;
        padding: 10px;
        min-height: 200px;
    }
    .char-portrait {
        width: 64px;
        height: 64px;
        border-radius: 4px;
        border: 2px solid #554433;
        object-fit: cover;
        background: #000;
    }
    .gear-slot {
        width: 30px;
        height: 30px;
        border: 1px solid #444;
        border-radius: 3px;
        background: #111;
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .gear-slot img {
        width: 26px;
        height: 26px;
    }
    .gear-enchant {
        position: absolute;
        top: -4px;
        right: -4px;
        font-size: 0.6rem;
        font-weight: bold;
        color: #38bdf8;
        background: rgba(0,0,0,0.8);
        padding: 0 2px;
        border-radius: 2px;
    }
    .ss-active {
        box-shadow: 0 0 6px #2563eb, inset 0 0 4px #3b82f6;
        border-color: #60a5fa !important;
    }
    .mob-card {
        cursor: pointer;
        border: 2px solid #333;
        border-radius: 4px;
        background: rgba(15, 15, 15, 0.85);
        padding: 4px;
        text-align: center;
        width: 76px;
        transition: all 0.15s ease;
    }
    .mob-card.targeted {
        border-color: #ef4444;
        box-shadow: 0 0 8px rgba(239, 68, 68, 0.7);
        transform: translateY(-2px);
    }
    .floating-hit {
        position: absolute;
        font-weight: 900;
        font-size: 1.25rem;
        pointer-events: none;
        z-index: 100;
        animation: floatUp 0.85s forwards ease-out;
    }
    @keyframes floatUp {
        0% { opacity: 1; transform: translate(-50%, 0) scale(0.9); }
        50% { transform: translate(-50%, -24px) scale(1.2); }
        100% { opacity: 0; transform: translate(-50%, -40px) scale(0.85); }
    }
</style>

<div class="battle-container">
    <!-- 1. ПОЛЕ БОЯ -->
    <div class="arena-stage mb-2 d-flex justify-content-between align-items-center" id="arena-stage">
        <!-- СТОРОНА ИГРОКА -->
        <div class="d-flex flex-column align-items-center" style="width: 140px;">
            <div class="position-relative mb-1">
                <img src="<?= $avatar['portrait'] ?>" class="char-portrait" alt="<?= Html::encode($character->name) ?>">
            </div>

            <!-- Слоты экипировки -->
            <div class="d-flex gap-1 mb-1">
                <div class="gear-slot <?= $avatar['has_ss'] ? 'ss-active' : '' ?>" title="<?= $avatar['weapon']['name'] ?? 'Кулаки' ?>">
                    <?php if ($avatar['weapon']): ?>
                        <img src="<?= $avatar['weapon']['icon'] ?>" alt="">
                        <?php if ($avatar['weapon']['enchant'] > 0): ?>
                            <span class="gear-enchant">+<?= $avatar['weapon']['enchant'] ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="gear-slot" title="<?= $avatar['armor']['name'] ?? 'Нет брони' ?>">
                    <?php if ($avatar['armor']): ?>
                        <img src="<?= $avatar['armor']['icon'] ?>" alt="">
                    <?php endif; ?>
                </div>
                <div class="gear-slot" title="<?= $avatar['shield']['name'] ?? 'Нет щита' ?>">
                    <?php if ($avatar['shield']): ?>
                        <img src="<?= $avatar['shield']['icon'] ?>" alt="">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Шкалы CP / HP / MP персонажа с числовым отображением -->
            <div class="w-100" style="font-size: 0.65rem;">
                <div class="d-flex justify-content-between text-muted" style="font-size: 0.62rem;">
                    <span>HP:</span>
                    <b id="player-hp-text" class="text-danger"><?= (int)$character->cur_hp ?> / <?= $character->maxHp ?></b>
                </div>
                <div class="progress mb-1 bg-dark" style="height: 6px;">
                    <div id="player-hp" class="progress-bar bg-danger" style="width: <?= $character->getHpPercent() ?>%"></div>
                </div>
                <div class="progress mb-1 bg-dark" style="height: 4px;">
                    <div id="player-cp" class="progress-bar bg-warning" style="width: <?= $character->getCpPercent() ?>%"></div>
                </div>
                <div class="progress bg-dark" style="height: 3px;">
                    <div id="player-mp" class="progress-bar bg-primary" style="width: <?= $character->getMpPercent() ?>%"></div>
                </div>
            </div>
        </div>

        <!-- СТОРОНА МОБОВ -->
        <div class="d-flex flex-wrap gap-2 justify-content-end" style="max-width: 260px;" id="mobs-container">
            <?php foreach ($session->mobs as $mob): ?>
                <?php $isTarget = ($target && $target->index === $mob->index); ?>
                <div class="mob-card <?= $isTarget ? 'targeted' : '' ?> <?= $mob->isDead() ? 'd-none' : '' ?>"
                     id="mob-card-<?= $mob->index ?>"
                     onclick="setBattleTarget(<?= $mob->index ?>)">

                    <img src="/images/icons/npc/<?= $mob->npcId ?>.png"
                         onerror="this.src='/images/icons/no_icon.png';"
                         style="width: 36px; height: 36px;" class="mb-1" alt="">

                    <div class="text-white text-truncate fw-bold" style="font-size: 0.68rem;">
                        <?= Html::encode($mob->getName()) ?>
                    </div>

                    <div class="progress bg-black mt-1" style="height: 4px;">
                        <div id="mob-hp-bar-<?= $mob->index ?>" class="progress-bar bg-danger" style="width: <?= $mob->getHpPercent() ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 2. БОЕВОЙ ЛОГ -->
    <div class="card p-2 mb-2 bg-black text-light border-secondary small" style="height: 100px; overflow-y: auto; font-size: 0.73rem;" id="combat-log">
        <?php foreach (array_reverse($session->logs) as $l): ?>
            <div><?= $l ?></div>
        <?php endforeach; ?>
    </div>

    <!-- 3. ПАНЕЛЬ ДЕЙСТВИЙ -->
    <div class="card p-2 bg-dark border-secondary">
        <div class="d-flex gap-2 mb-2">
            <button onclick="sendAction('attack')" class="btn btn-danger w-50 py-2 fw-bold" id="btn-attack">
                ⚔ Атака
            </button>
            <button onclick="sendAction('flee')" class="btn btn-outline-secondary w-50 py-2" id="btn-flee">
                🏃 Побег
            </button>
        </div>

        <!-- Панель скиллов -->
        <div class="d-flex gap-1 overflow-auto pb-1">
            <?php foreach ($character->characterSkills as $cs): ?>
                <?php
                $skill = \app\models\game\SkillRepository::find($cs->skill_id, $cs->level);
                if (!$skill || $skill->isPassive()) continue;
                ?>
                <button onclick="sendSkill(<?= $skill->id ?>)" class="btn btn-sm btn-outline-info py-1 px-2 d-flex align-items-center flex-shrink-0" style="font-size: 0.72rem;">
                    <img src="<?= $skill->getIconUrl() ?>" width="16" height="16" class="me-1" alt="">
                    <?= Html::encode($skill->getName()) ?> (<?= $skill->mpConsume ?> MP)
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    const CSRF_TOKEN = '<?= Yii::$app->request->csrfToken ?>';
    const BASE_BATTLE_URL = '<?= Url::to(['/game/battle']) ?>';
    let isActionPending = false;

    // Всплывающий урон (isPlayer = true отображает урон над игроком красным)
    function showFloatingDamage(text, color = '#facc15', isPlayer = false) {
        const stage = document.getElementById('arena-stage');
        if (!stage) return;
        const el = document.createElement('div');
        el.className = 'floating-hit';
        el.innerHTML = text;
        el.style.color = color;
        el.style.left = isPlayer ? '25%' : '75%';
        el.style.top = '40%';
        stage.appendChild(el);
        setTimeout(() => el.remove(), 850);
    }

    function sendAction(action, params = {}) {
        if (isActionPending && action === 'idle') return;
        if (action !== 'idle') isActionPending = true;

        const url = BASE_BATTLE_URL + '/' + action;
        const formData = new URLSearchParams(params);

        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': CSRF_TOKEN,
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                isActionPending = false;
                if (!data.success) {
                    if (data.redirect) window.location.href = data.redirect;
                    return;
                }

                // Если игрок или мобы погибли
                if (data.finished) {
                    clearInterval(heartbeatTimer);
                    window.location.href = data.redirect;
                    return;
                }

                // 1. Обновление здоровья персонажа и чисел
                if (data.player) {
                    const hpPct = Math.round((data.player.cur_hp / Math.max(1, data.player.max_hp)) * 100);
                    const mpPct = Math.round((data.player.cur_mp / Math.max(1, data.player.max_mp)) * 100);
                    const cpPct = Math.round((data.player.cur_cp / Math.max(1, data.player.max_cp)) * 100);

                    const hpBar = document.getElementById('player-hp');
                    const hpText = document.getElementById('player-hp-text');
                    if (hpBar) hpBar.style.width = hpPct + '%';
                    if (hpText) hpText.innerText = data.player.cur_hp + ' / ' + data.player.max_hp;

                    const mpBar = document.getElementById('player-mp');
                    if (mpBar) mpBar.style.width = mpPct + '%';

                    const cpBar = document.getElementById('player-cp');
                    if (cpBar) cpBar.style.width = cpPct + '%';
                }

                // 2. Обновление состояния мобов
                if (data.session && data.session.mobs) {
                    data.session.mobs.forEach(mob => {
                        const card = document.getElementById('mob-card-' + mob.index);
                        const hpBar = document.getElementById('mob-hp-bar-' + mob.index);
                        if (mob.cur_hp <= 0) {
                            if (card) card.classList.add('d-none');
                        } else if (hpBar) {
                            const pct = Math.round((mob.cur_hp / Math.max(1, mob.max_hp)) * 100);
                            hpBar.style.width = pct + '%';
                        }
                    });
                }

                // 3. Журнал боя и анимация урона
                if (data.logs && data.logs.length > 0) {
                    const logBox = document.getElementById('combat-log');
                    logBox.innerHTML = data.logs.slice().reverse().map(l => `<div>${l}</div>`).join('');
                    logBox.scrollTop = 0;

                    const lastLog = data.logs[data.logs.length - 1];
                    if (action !== 'idle') {
                        if (lastLog.includes('КРИТ')) {
                            showFloatingDamage('CRIT!', '#f59e0b', false);
                        } else if (lastLog.includes('Вы нанесли')) {
                            showFloatingDamage('HIT', '#ffffff', false);
                        }
                    }

                    // Если в ответе зафиксирован урон по игроку
                    if (lastLog.includes('Ответный урон') || lastLog.includes('непрерывно атаковали')) {
                        showFloatingDamage('DMG', '#ef4444', true);
                    }
                }
            })
            .catch(err => {
                isActionPending = false;
                console.error('Battle error:', err);
            });
    }

    function sendSkill(skillId) {
        sendAction('skill', { id: skillId });
    }

    function setBattleTarget(targetIndex) {
        sendAction('target', { index: targetIndex });
    }

    // Запуск фонового тика атак моба (каждые 2.5 секунды)
    const heartbeatTimer = setInterval(() => {
        sendAction('idle');
    }, 2500);
</script>
