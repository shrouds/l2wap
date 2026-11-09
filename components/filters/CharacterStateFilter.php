<?php
declare(strict_types=1);

namespace app\components\filters;

use Yii;
use yii\base\ActionFilter;
use app\models\Character;
use app\components\game\CharacterEffectService;
use app\components\game\BattleService;
use yii\base\InvalidRouteException;

class CharacterStateFilter extends ActionFilter
{
    public bool $checkBattle = true;
    public bool $checkAlive = true;
    public bool $applyEffects = true;
    public bool $applyRegen = true;
    public int $minThrottleSeconds = 2;

    /**
     * @throws InvalidRouteException
     */
    public function beforeAction($action): bool
    {
        if (Yii::$app->user->isGuest) {
            return parent::beforeAction($action);
        }

        $character = Character::getCurrent((int)Yii::$app->user->id);
        if ($character === null) {
            return parent::beforeAction($action);
        }

        $now = time();
        $lastAction = (int)($character->last_action_at ?: $now);
        $elapsed = max(0, $now - $lastAction);

        // 1. Если персонаж мертв — пускаем только на воскрешение/в город, минуя арену боя
        if ($character->cur_hp <= 0) {
            return $this->handleLifeState($character, $action);
        }

        // 2. Проверка нахождения в бою (выполняется только для живого персонажа)
        if ($this->checkBattle && !$this->handleBattleState($character, $action)) {
            return false;
        }

        // 3. Обработка регенерации и тиков эффектов
        $this->processTimeAndRegen($character, $elapsed, $now);

        return parent::beforeAction($action);
    }

    /**
     * Контроль боя: если идет бой, разрешены только роуты game/battle/*
     * @throws InvalidRouteException
     */
    /**
     * Контроль боя: если идет бой, разрешены только роуты боевого контроллера
     * @throws InvalidRouteException
     */
    protected function handleBattleState(Character $character, $action): bool
    {
        // 1. Если запрос УЖЕ идет в контроллер боя — блокировка редиректа на саму себя
        if ($action->controller->id === 'battle') {
            return true;
        }

        // 2. Проверяем реальное наличие объекта боя в памяти, а не сырой ключ
        $session = BattleService::getSession($character->id);
        if ($session === null) {
            return true;
        }

        // 3. Если бой действительно идет: AJAX получает 403, обычный переход — редирект
        if (Yii::$app->request->isAjax) {
            Yii::$app->response->statusCode = 403;
            Yii::$app->response->data = [
                'error'    => 'battle_locked',
                'redirect' => \yii\helpers\Url::to(['/game/battle/index']),
            ];
            return false;
        }

        Yii::$app->session->setFlash('warning', 'Вы находитесь в бою!');
        Yii::$app->response->redirect(['/game/battle/index']);
        return false;
    }

    /**
     * Контроль жизни: мертвый персонаж может только возродиться
     * @throws InvalidRouteException
     */
    protected function handleLifeState(Character $character, $action): bool
    {
        if ($character->cur_hp <= 0) {
            $route = $action->uniqueId;
            $allowedRoutes = [
                'game/default/respawn',
                'game/default/index',
                'character/character/list',
                'character/character/select',
                'character/character/index',
            ];

            if (!in_array($route, $allowedRoutes, true)) {
                Yii::$app->response->redirect(['/game/default/index'])->send();
                return false;
            }
        }

        return true;
    }

    protected function processTimeAndRegen(Character $character, int $elapsed, int $now): void
    {
        if ($character->isInBattle() || $character->cur_hp <= 0) {
            return;
        }

        if ($elapsed < $this->minThrottleSeconds) {
            return;
        }

        if ($this->applyEffects) {
            CharacterEffectService::tickElapsed($character, $elapsed);
        }

        if ($this->applyRegen && $character->cur_hp > 0) {
            $character->applyRegen();
        }

        $character->last_action_at = $now;
        $character->save(false, ['last_action_at']);
    }
}
