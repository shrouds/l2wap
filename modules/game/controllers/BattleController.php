<?php
declare(strict_types=1);

namespace app\modules\game\controllers;

use Yii;
use yii\web\Response;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\helpers\Url;
use app\controllers\BaseGameController;
use app\components\game\BattleService;
use app\models\game\NpcRepository;
use app\models\Character;
use app\models\game\battle\BattleResult;

class BattleController extends BaseGameController
{
    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'attack' => ['POST'],
                    'skill'  => ['POST'],
                    'target' => ['POST'],
                    'potion' => ['POST'],
                    'flee'   => ['POST'],
                    'idle'   => ['POST'],
                    'hunt'   => ['GET', 'POST'],
                    'start'  => ['GET', 'POST'],
                ],
            ],
        ]);
    }

    public function actionIndex(): string|Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null) {
            return $this->redirect(['/character/character/list']);
        }

        $session = BattleService::getSession($character->id);
        if ($session === null) {
            Yii::$app->session->setFlash('warning', 'Бой не найден');
            return $this->redirect(['/game/default/index']);
        }

        $idleResult = BattleService::processIdleRetaliation($character, $session);
        if ($idleResult !== null && $idleResult->isFinished) {
            if ($idleResult->message) {
                Yii::$app->session->setFlash($idleResult->flashType, $idleResult->message);
            }
            return $this->redirect(['/game/default/index']);
        }

        return $this->render('index', [
            'character' => $character,
            'session'   => $session,
        ]);
    }

    public function actionHunt(): Response
    {
        $character = $this->getActiveCharacter();
        if (BattleService::hasBattle($character->id)) {
            return $this->redirect(['index']);
        }

        $location = $character->getLocation();
        if (!$location || $location->isPeace) {
            Yii::$app->session->setFlash('warning', 'В мирной зоне нельзя охотиться.');
            return $this->redirect(['/game/default/index']);
        }

        $monsters = $location->getRegularMonsters();
        if (empty($monsters)) {
            Yii::$app->session->setFlash('info', 'В этой зоне сейчас нет монстров.');
            return $this->redirect(['/game/default/index']);
        }

        $spawnPack = [];
        $packSize = (mt_rand(1, 100) <= 20) ? mt_rand(2, 3) : 1;
        for ($i = 0; $i < $packSize; $i++) {
            $spawnPack[] = $monsters[array_rand($monsters)];
        }

        BattleService::startBattle($character, $spawnPack);
        return $this->redirect(['index']);
    }

    public function actionStart(int $npc_id): Response
    {
        $character = $this->getActiveCharacter();
        if (BattleService::hasBattle($character->id)) {
            return $this->redirect(['index']);
        }

        $npc = NpcRepository::find($npc_id);
        if (!$npc) {
            throw new NotFoundHttpException('Монстр не найден.');
        }

        BattleService::startBattle($character, [$npc]);
        return $this->redirect(['index']);
    }

    public function actionAttack(): Response
    {
        $character = $this->getActiveCharacter();
        $result = BattleService::executeAttack($character);
        return $this->handleBattleResponse($character, $result);
    }

    public function actionSkill(?int $id = null): Response
    {
        $id = $id ?? (int)Yii::$app->request->post('id');
        $character = $this->getActiveCharacter();
        $result = BattleService::executeSkill($character, $id);
        return $this->handleBattleResponse($character, $result);
    }

    public function actionPotion(?int $id = null): Response
    {
        $id = $id ?? (int)Yii::$app->request->post('id');
        $character = $this->getActiveCharacter();
        $result = BattleService::executePotion($character, $id);
        return $this->handleBattleResponse($character, $result);
    }

    public function actionTarget(?int $index = null): Response
    {
        $index = $index ?? (int)Yii::$app->request->post('index');
        $character = $this->getActiveCharacter();
        BattleService::changeTarget($character, $index);

        if (Yii::$app->request->isAjax) {
            $session = BattleService::getSession($character->id);
            return $this->asJson([
                'success'      => true,
                'target_index' => $session ? $session->targetIndex : 0,
            ]);
        }

        return $this->redirect(['index']);
    }

    public function actionFlee(): Response
    {
        $character = $this->getActiveCharacter();
        $result = BattleService::executeFlee($character);
        return $this->handleBattleResponse($character, $result);
    }

    /**
     * Единая точка формирования ответа (JSON для JS-арены, Redirect для обычных ссылок)
     */
    private function handleBattleResponse(Character $character, BattleResult $result): Response
    {
        if (Yii::$app->request->isAjax) {
            $session = BattleService::getSession($character->id);
            return $this->asJson([
                'success'    => true,
                'finished'   => $result->isFinished,
                'victory'    => $result->isVictory,
                'dead'       => $result->isDead,
                'message'    => $result->message,
                'redirect'   => $result->isFinished ? Url::to(['/game/default/index']) : null,
                'player'     => [
                    'cur_hp' => (int)$character->cur_hp,
                    'max_hp' => $character->maxHp,
                    'cur_mp' => (int)$character->cur_mp,
                    'max_mp' => $character->maxMp,
                    'cur_cp' => (int)$character->cur_cp,
                    'max_cp' => $character->maxCp,
                ],
                'session'    => $session?->toArray(),
                'logs'       => $session ? $session->logs : [],
            ]);
        }

        if ($result->isFinished) {
            if ($result->message) {
                Yii::$app->session->setFlash($result->flashType, $result->message);
            }
            return $this->redirect(['/game/default/index']);
        }

        return $this->redirect(['index']);
    }

    /**
     * Фоновый тик: расчет ударов моба, пока игрок бездействует
     */
    public function actionIdle(): Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null) {
            return $this->asJson(['success' => false]);
        }

        $session = BattleService::getSession($character->id);
        if ($session === null) {
            return $this->asJson([
                'success'  => false,
                'finished' => true,
                'redirect' => Url::to(['/game/default/index']),
            ]);
        }

        // Проверяем удары мобов за накопившееся время
        $idleResult = BattleService::processIdleRetaliation($character, $session);

        // Если игрок погиб от серии ударов
        if ($idleResult !== null && $idleResult->isFinished) {
            return $this->handleBattleResponse($character, $idleResult);
        }

        return $this->handleBattleResponse($character, BattleResult::inProgress());
    }
}
