<?php

namespace app\controllers;

use app\components\filters\CharacterStateFilter;
use app\models\Character;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;

class BaseGameController extends Controller
{
    public function behaviors(): array
    {
        return [
            // Доступ только авторизованным пользователям
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            // Единый фильтр состояния персонажа, регенерации и проверок
            'characterState' => [
                'class' => CharacterStateFilter::class,
            ],
        ];
    }

    /**
     * Быстрый доступ к активному персонажу
     */
    protected function getActiveCharacter(): ?Character
    {
        return Character::getCurrent((int)Yii::$app->user->id);
    }
}
