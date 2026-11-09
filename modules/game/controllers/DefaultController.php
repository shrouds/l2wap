<?php

namespace app\modules\game\controllers;

use app\components\game\CharacterEffectService;
use app\controllers\BaseGameController;
use app\models\Character;
use app\models\game\LocationRepository;
use Yii;
use yii\db\Exception;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Default controller for the `game` module
 */
class DefaultController extends BaseGameController
{

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => \yii\filters\VerbFilter::class,
                'actions' => [
                    'teleport' => ['POST'],
                    'respawn'  => ['GET', 'POST'],
                ],
            ],
        ]);
    }

    /**
     * Главный экран локации
     * @throws Exception
     */
    public function actionIndex(): string|Response
    {
        $character = $this->getActiveCharacter();

        // Если идет бой — возвращаем на арену битвы
        if ($character->isInBattle()) {
            return $this->redirect(['/game/battle/index']);
        }

        $location = $character->getLocation();
        if ($location === null) {
            $character->location_id = 'ti_town';
            $character->save(false, ['location_id']);
            $location = LocationRepository::find('ti_town');
        }

        $connectedLocations = [];
        foreach ($location->connected as $connId) {
            $connLoc = LocationRepository::find($connId);
            if ($connLoc !== null) {
                $connectedLocations[] = $connLoc;
            }
        }

        return $this->render('index', [
            'character'          => $character,
            'location'           => $location,
            'connectedLocations' => $connectedLocations,
        ]);
    }

    /**
     * Пеший переход в соседнюю зону с учетом runSpeed
     * @throws Exception|NotFoundHttpException
     */
    public function actionMove(string $target): Response
    {
        $character = $this->getActiveCharacter();

        if ($character->isInBattle()) {
            Yii::$app->session->setFlash('error', 'Вы не можете перемещаться во время боя!');
            return $this->redirect(['/game/battle/index']);
        }

        if ($character->cur_hp <= 0) {
            Yii::$app->session->setFlash('error', 'Мертвый персонаж не может передвигаться.');
            return $this->redirect(['index']);
        }

        $currentLocation = $character->getLocation();
        if (!$currentLocation || !$currentLocation->isConnectedTo($target)) {
            Yii::$app->session->setFlash('error', 'В эту локацию нельзя перейти напрямую.');
            return $this->redirect(['index']);
        }

        $targetLocation = LocationRepository::find($target);
        if (!$targetLocation) {
            throw new NotFoundHttpException('Локация не найдена.');
        }

        // Расчет времени в пути на основе скорости бега
        $now = time();
        $elapsed = max(0, $now - ($character->last_action_at ?: $now));
        $runSpeed = $character->getRunSpeed();

        // Базовые 6 секунд при стандартной скорости 126
        $travelTime = max(2, (int)round(6.0 * (126.0 / max(40, $runSpeed))));

        if ($elapsed < $travelTime) {
            $waitSec = $travelTime - $elapsed;
            Yii::$app->session->setFlash('warning', "Вы еще в пути. До перехода осталось: {$waitSec} сек.");
            return $this->redirect(['index']);
        }

        if ($targetLocation->minLevel > $character->level) {
            Yii::$app->session->setFlash('warning', "Локация опасна! Рекомендуемый уровень: {$targetLocation->minLevel}+");
        }

        // Обновление таймера активных эффектов и контрольная точка сохранения
        CharacterEffectService::tickElapsed($character, $elapsed);
        CharacterEffectService::flushToDb($character);

        $character->location_id = $targetLocation->id;
        $character->last_action_at = $now;
        $character->save(false, ['location_id', 'last_action_at', 'updated_at']);

        return $this->redirect(['index']);
    }

    /**
     * Меню Карты Мира и мгновенных телепортов
     */
    public function actionMap(?string $region = null): string|Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null) {
            return $this->redirect(['/character/character/list']);
        }

        if ($character->isInBattle()) {
            Yii::$app->session->setFlash('error', 'Карта недоступна во время боя!');
            return $this->redirect(['/game/battle/index']);
        }

        $currentLocation = $character->getLocation();
        $regions = LocationRepository::getGroupedByRegion();

        // По умолчанию открываем регион текущего нахождения персонажа
        $activeRegion = $region ?? ($currentLocation ? $currentLocation->regionId : 'talking_island');
        if (!isset($regions[$activeRegion])) {
            $activeRegion = array_key_first($regions);
        }

        return $this->render('map', [
            'character'       => $character,
            'currentLocation' => $currentLocation,
            'regions'         => $regions,
            'activeRegion'    => $activeRegion,
        ]);
    }

    /**
     * Телепортация в выбранную точку карты
     * @param string $target
     * @return Response
     * @throws Exception
     * @throws NotFoundHttpException
     * @throws \Throwable
     */
    public function actionTeleport(string $target): Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null) {
            return $this->redirect(['/character/character/list']);
        }

        if ($character->isInBattle()) {
            Yii::$app->session->setFlash('error', 'Нельзя телепортироваться во время боя!');
            return $this->redirect(['/game/battle/index']);
        }

        if ($character->cur_hp <= 0) {
            Yii::$app->session->setFlash('error', 'Мертвый персонаж не может перемещаться.');
            return $this->redirect(['index']);
        }

        $targetLocation = LocationRepository::find($target);
        if (!$targetLocation) {
            throw new NotFoundHttpException('Локация назначения не найдена.');
        }

        $currentLocation = $character->getLocation();
        if ($currentLocation && $currentLocation->id === $targetLocation->id) {
            Yii::$app->session->setFlash('info', 'Вы уже находитесь в этой локации.');
            return $this->redirect(['index']);
        }

        $cost = LocationRepository::calculateTeleportCost($targetLocation, $currentLocation);
        if ($character->getAdena() < $cost) {
            Yii::$app->session->setFlash('error', 'Недостаточно Адены (требуется: ' . number_format($cost, 0, '', ' ') . ' а).');
            return $this->redirect(['map', 'region' => $targetLocation->regionId]);
        }

        // Списание адены, тики баффов и сохранение слепка в БД
        $character->takeAdena($cost);

        $now = time();
        $elapsed = max(0, $now - ($character->last_action_at ?: $now));
        CharacterEffectService::tickElapsed($character, $elapsed);
        CharacterEffectService::flushToDb($character);

        $character->location_id = $targetLocation->id;
        $character->last_action_at = $now;
        $character->save(false, ['location_id', 'last_action_at', 'updated_at']);

        if ($targetLocation->minLevel > $character->level) {
            Yii::$app->session->setFlash('warning', "Вы переместились в опасную зону ({$targetLocation->getName()})! Рекомендуемый уровень: {$targetLocation->minLevel}+");
        } else {
            Yii::$app->session->setFlash('success', "Телепортация в {$targetLocation->getName()} завершена.");
        }

        return $this->redirect(['index']);
    }

    /**
     * Воскрешение («В город / To Village»)
     * @throws Exception
     */
    public function actionRespawn(): Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null || $character->cur_hp > 0) {
            return $this->redirect(['index']);
        }

        $currentLocation = $character->getLocation();
        $respawnTownId = $currentLocation ? $currentLocation->getRespawnTownId() : 'ti_town';

        $character->cur_hp = (float)round($character->maxHp * 0.7);
        $character->location_id = $respawnTownId;
        $character->last_action_at = time();

        CharacterEffectService::flushToDb($character);
        $character->save(false, ['cur_hp', 'location_id', 'last_action_at', 'updated_at']);

        Yii::$app->session->setFlash('info', 'Вы очнулись в ближайшем поселении.');
        return $this->redirect(['index']);
    }
}
