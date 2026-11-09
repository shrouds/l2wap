<?php

namespace app\components\filters;

use app\models\Character;
use Yii;
use yii\base\ActionFilter;
use yii\base\InvalidRouteException;
use yii\db\Exception;

class CharacterCheckFilter extends ActionFilter
{
    /**
     * Маршруты-исключения, на которых проверка персонажа НЕ выполняется:
     */
    public array $whitelist = [
        // Системные действия и аккаунт
        'site/login',
        'site/signup',
        'site/logout',
        'site/error',
        'site/captcha',

        // Действия управления персонажами (выбор, создание, удаление)
        'character/*',
        'character/character/*',
        'character/create/*',

        // Отладка и генераторы
        'gii/*',
        'debug/*',
    ];

    /**
     * @throws InvalidRouteException
     * @throws Exception
     */
    public function beforeAction($action): bool
    {
        // 1. Гостей не трогаем (сработает AccessControl контроллера)
        if (Yii::$app->user->isGuest) {
            return true;
        }

        // 2. Получаем полный уникальный маршрут с учетом модуля
        // Для CreateController::actionStep1() это будет строго "character/create/step1"
        $currentRoute = $action->uniqueId;

        // 3. Проверяем совпадение с белым списком (включая маски вида character/*)
        foreach ($this->whitelist as $pattern) {
            if ($currentRoute === $pattern || fnmatch($pattern, $currentRoute)) {
                return true;
            }
        }

        $userId = (int)Yii::$app->user->id;

        // 4. Проверяем наличие активного персонажа
        $character = Character::getCurrent($userId);

        if ($character === null) {
            $hasAnyCharacter = Character::find()->where(['user_id' => $userId])->exists();

            if ($hasAnyCharacter) {
                // Если персонажи есть — отправляем в лобби со списком
                $targetUrl = ['/character/character/index'];
            } else {
                // Если персонажей нет — отправляем на первый шаг создания
                $targetUrl = ['/character/create/step1'];
            }

            // Защита от редиректа на саму себя
            if ($currentRoute !== ltrim($targetUrl[0], '/')) {
                Yii::$app->response->redirect($targetUrl)->send();
            }

            return false;
        }

        return true;
    }
}
