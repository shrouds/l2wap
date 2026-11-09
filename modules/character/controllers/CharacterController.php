<?php

namespace app\modules\character\controllers;

use app\components\game\BattleService;
use app\controllers\BaseGameController;
use app\models\Character;
use app\models\CharacterItem;
use app\models\game\ExpTableRepository;
use app\models\game\PcParamsRepository;
use Yii;
use yii\db\Exception;
use yii\db\StaleObjectException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CharacterController extends BaseGameController
{
    public function actionIndex(): Response|string
    {
        $character = $this->getActiveCharacter();

        $classInfo = PcParamsRepository::getClass($character->class_id) ?? [];
        $race = $character->characterRace;

        return $this->render('index', [
            'character' => $character,
            'classInfo' => $classInfo,
            'race' => $race,
            'expPercent' => $character->expPercent,
            'nextLevelExp' => $character->nextLevelExp,
        ]);
    }

    public function actionInventory(): string|Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null) {
            return $this->redirect(['list']);
        }

        // 1. Надетые предметы, распределенные по слотам куклы
        $equippedRaw = $character->equippedItems;
        $paperdoll = [
            'head' => null,
            'chest' => null,
            'legs' => null,
            'gloves' => null,
            'feet' => null,
            'rhand' => null, // Оружие
            'lhand' => null, // Щит или стрелы
            'neck' => null, // Ожерелье
            'rear_1' => null, // Серьга 1
            'rear_2' => null, // Серьга 2
            'rfinger_1' => null, // Кольцо 1
            'rfinger_2' => null, // Кольцо 2
        ];

        foreach ($equippedRaw as $item) {
            $slot = $item->equip_slot;
            if ($slot === 'onepiece') {
                $paperdoll['chest'] = $item;
                $paperdoll['legs'] = $item; // Комбинезон занимает оба слота
            } elseif ($slot === 'lrhand') {
                $paperdoll['rhand'] = $item;
                $paperdoll['lhand'] = $item; // Двуручник занимает обе руки
            } elseif (array_key_exists($slot, $paperdoll)) {
                $paperdoll[$slot] = $item;
            }
        }

        // 2. Предметы в рюкзаке (не надетые)
        $bagItems = $character->characterNoEquipItems;

        // 3. Расчет перегруза инвентаря
        $totalWeight = 0;
        $inventorySlotsCount = 0;
        foreach ($character->characterItems as $invItem) {
            $template = $invItem->getItem();
            if ($template) {
                $totalWeight += ($template->weight * $invItem->count);
            }
            if ($invItem->count > 0 && ($template === null || $template->type !== 'asset')) {
                $inventorySlotsCount++;
            }
        }

        $classInfo = PcParamsRepository::getClass($character->class_id) ?? [];
        $con = (int) ($classInfo['con'] ?? 40);
        $conMod = PcParamsRepository::getStatModifier('con', $con);
        $maxWeight = (int) round(69000 * $conMod);
        $weightPercent = min(100.0, round(($totalWeight / max(1, $maxWeight)) * 100, 1));

        return $this->render('inventory', [
            'character' => $character,
            'paperdoll' => $paperdoll,
            'bagItems' => $bagItems,
            'totalWeight' => $totalWeight,
            'maxWeight' => $maxWeight,
            'weightPercent' => $weightPercent,
            'inventorySlotsCount' => $inventorySlotsCount,
            'maxSlots' => ($character->race === 4 ? 100 : 80), // У гномов 100 слотов
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionEquip(int $id): Response
    {
        $character = $this->getActiveCharacter();
        $charItem = CharacterItem::findOne(['id' => $id, 'character_id' => $character->id]);

        if (!$charItem) {
            throw new NotFoundHttpException('Предмет не найден в вашем инвентаре.');
        }

        if ($charItem->equip()) {
            Yii::$app->session->setFlash('success', 'Вы экипировали: ' . $charItem->getItem()?->getName());
        } else {
            Yii::$app->session->setFlash('error', 'Этот предмет нельзя экипировать.');
        }

        return $this->redirect(['inventory']);
    }

    /**
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function actionUnequip(int $id): Response
    {
        $character = $this->getActiveCharacter();
        $charItem = CharacterItem::findOne(['id' => $id, 'character_id' => $character->id]);

        if (!$charItem || !$charItem->is_equipped) {
            throw new NotFoundHttpException('Предмет не надет.');
        }

        $charItem->unequip();
        Yii::$app->session->setFlash('info', 'Вы сняли: ' . $charItem->getItem()?->getName());

        return $this->redirect(['inventory']);
    }

    /**
     * @throws \Throwable
     * @throws Exception
     * @throws StaleObjectException
     * @throws NotFoundHttpException
     */
    public function actionUse(int $id): Response
    {
        $character = $this->getActiveCharacter();
        $charItem = CharacterItem::findOne(['id' => $id, 'character_id' => $character->id]);

        if (!$charItem || $charItem->count <= 0) {
            throw new NotFoundHttpException('Предмет не найден.');
        }

        $item = $charItem->getItem();
        if (!$item) {
            throw new NotFoundHttpException('Шаблон предмета не найден.');
        }

        // Логика использования расходников новичка
        $used = false;
        if ($item->id === 1060 || $item->id === 1061) { // Healing Potion
            $maxHp = $character->getMaxHp();
            $character->cur_hp = min((float) $maxHp, $character->cur_hp + 150);
            $character->save(false, ['cur_hp']);
            $used = true;
            Yii::$app->session->setFlash('success', 'Вы восстановили HP.');
        } elseif ($item->id === 736) { // SoE - Свиток Телепорта
            $character->location_id = $character->getLocation()?->getRespawnTownId() ?? 'ti_town';
            $character->save(false, ['location_id']);
            $used = true;
            Yii::$app->session->setFlash('info', 'Вы телепортировались в ближайший город.');
        }

        if ($used) {
            $charItem->count--;
            if ($charItem->count <= 0) {
                $charItem->delete();
            } else {
                $charItem->save(false, ['count']);
            }
        } else {
            Yii::$app->session->setFlash('warning', 'Этот предмет сейчас нельзя использовать.');
        }

        return $this->redirect(['inventory']);
    }

    public function actionToggleShot(string $type): Response
    {
        $character = $this->getActiveCharacter();
        if ($type === 'soulshot') {
            $character->auto_soulshot = !$character->auto_soulshot;
            $character->save(false, ['auto_soulshot']);
            Yii::$app->session->setFlash('info', 'Авто-соски (Soulshot): ' . ($character->auto_soulshot ? 'ВКЛ' : 'ВЫКЛ'));
        } elseif ($type === 'spiritshot') {
            $character->auto_spiritshot = !$character->auto_spiritshot;
            $character->save(false, ['auto_spiritshot']);
            Yii::$app->session->setFlash('info', 'Авто-духи (Spiritshot): ' . ($character->auto_spiritshot ? 'ВКЛ' : 'ВЫКЛ'));
        }

        return $this->redirect(['inventory']);
    }

    public function actionSkills(): string|Response
    {
        $character = $this->getActiveCharacter();
        if ($character === null) {
            return $this->redirect(['list']);
        }

        $charSkills = $character->characterSkills;
        $activeSkills = [];
        $passiveSkills = [];

        foreach ($charSkills as $charSkill) {
            $skill = $charSkill->getSkill();
            if ($skill === null) {
                continue;
            }

            if ($skill->isPassive()) {
                $passiveSkills[] = ['charSkill' => $charSkill, 'skill' => $skill];
            } else {
                $activeSkills[] = ['charSkill' => $charSkill, 'skill' => $skill];
            }
        }

        return $this->render('skills', [
            'character' => $character,
            'activeSkills' => $activeSkills,
            'passiveSkills' => $passiveSkills,
        ]);
    }

    public function actionList(): string|Response
    {
        $userId = (int) Yii::$app->user->id;
        $characters = Character::find()->where(['user_id' => $userId])->all();

        if (empty($characters)) {
            return $this->redirect(['/character/create/step1']);
        }

        return $this->render('list', [
            'characters' => $characters,
        ]);
    }

    public function actionSelect(int $id): Response
    {
        $userId = (int) Yii::$app->user->id;
        $character = Character::findOne(['id' => $id, 'user_id' => $userId]);

        if (!$character) {
            throw new ForbiddenHttpException('Персонаж не найден или не принадлежит вашему аккаунту.');
        }

        $character->makeCurrent();
        Yii::$app->session->setFlash('success', "Активный персонаж: {$character->name}");

        return $this->redirect(['/game/index']);
    }

    /**
     * @throws StaleObjectException
     * @throws \Throwable
     * @throws ForbiddenHttpException
     */
    public function actionDelete(int $id): Response
    {
        $userId = (int) Yii::$app->user->id;
        $character = Character::findOne(['id' => $id, 'user_id' => $userId]);

        if (!$character) {
            throw new ForbiddenHttpException('Персонаж не найден.');
        }
        $battle = BattleService::getSession($character->id);
        if ($battle !== null) {
            Yii::$app->session->setFlash('error', 'Нельзя удалить персонажа во время боя!');
            return $this->redirect(['list']);
        }

        $character->delete();
        Yii::$app->session->setFlash('info', 'Персонаж успешно удален.');

        return $this->redirect(['list']);
    }

}
