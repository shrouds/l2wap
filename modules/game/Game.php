<?php

namespace app\modules\game;

use app\models\Character;
use Yii;
use yii\base\InvalidRouteException;

/**
 * game module definition class
 */
class Game extends \yii\base\Module
{
    /**
     * {@inheritdoc}
     */
    public $controllerNamespace = 'app\modules\game\controllers';
    public $layout = 'game';

    /**
     * {@inheritdoc}
     * @throws InvalidRouteException
     */
    public function init(): void
    {
        parent::init();
        // Если игрок гость — отправляем на логин
        if (Yii::$app->user->isGuest) {
            Yii::$app->response->redirect(['/site/login'])->send();
            exit;
        }

        // Проверка активного персонажа для всего игрового модуля
        $character = Character::getCurrent((int)Yii::$app->user->id);
        if ($character === null) {
            Yii::$app->response->redirect(['/character/character/list'])->send();
            exit;
        }
        // custom initialization code goes here
    }
}
