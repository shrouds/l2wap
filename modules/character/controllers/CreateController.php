<?php
declare(strict_types=1);

namespace app\modules\character\controllers;

use app\components\game\CharacterStatService;
use app\models\Character;
use app\models\CharacterItem;
use app\models\CharacterSkill;
use app\models\game\PcParamsRepository;
use app\models\game\Race;
use app\models\game\SettingsRepository;
use app\models\game\SkillTreeRepository;
use app\modules\character\models\StepClassForm;
use app\modules\character\models\StepProfileForm;
use app\modules\character\models\StepRaceForm;
use Yii;
use yii\db\Exception;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class CreateController extends Controller
{
    private const SESSION_KEY = 'character_create_wizard';

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'save' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * ШАГ 1: Выбор расы
     */
    public function actionStep1(): Response|string
    {
        $this->checkCharacterLimit();

        $wizardData = $this->getWizardData();
        $model = new StepRaceForm();
        $model->race_id = $wizardData['race_id'] ?? null;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            // Если игрок сменил расу — сбрасываем выбранный класс
            if (isset($wizardData['race_id']) && $wizardData['race_id'] !== $model->race_id) {
                unset($wizardData['class_id']);
            }

            $wizardData['race_id'] = $model->race_id;
            $this->setWizardData($wizardData);

            return $this->redirect(['step2']);
        }

        return $this->render('step1', [
            'model' => $model,
            'races' => Race::all(),
        ]);
    }

    /**
     * ШАГ 2: Выбор класса
     */
    public function actionStep2(): Response|string
    {
        $this->ensureStepAccess(2);

        $wizardData = $this->getWizardData();
        $race = Race::find((int)$wizardData['race_id']);

        $model = new StepClassForm();
        $model->race_id = $race->id;
        $model->class_id = $wizardData['class_id'] ?? null;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $wizardData['class_id'] = $model->class_id;
            $this->setWizardData($wizardData);

            return $this->redirect(['step3']);
        }

        return $this->render('step2', [
            'model' => $model,
            'race'  => $race,
        ]);
    }

    /**
     * ШАГ 3: Выбор пола и никнейма
     */
    public function actionStep3(): Response|string
    {
        $this->ensureStepAccess(3);

        $wizardData = $this->getWizardData();
        $model = new StepProfileForm();
        $model->name = $wizardData['name'] ?? null;
        $model->gender = $wizardData['gender'] ?? 0;

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $wizardData['name'] = $model->name;
            $wizardData['gender'] = $model->gender;
            $this->setWizardData($wizardData);

            return $this->redirect(['confirm']);
        }

        return $this->render('step3', [
            'model' => $model,
        ]);
    }

    /**
     * ШАГ 4: Сводка перед созданием
     */
    public function actionConfirm(): string
    {
        $this->ensureStepAccess(4);

        $data = $this->getWizardData();
        $race = Race::find((int)$data['race_id']);
        $classData = PcParamsRepository::getClass((int)$data['class_id']);
        $equipment = SettingsRepository::getEquipment((int)$data['class_id']);

        return $this->render('confirm', [
            'data'      => $data,
            'race'      => $race,
            'classData' => $classData,
            'equipment' => $equipment,
        ]);
    }

    /**
     * ФИНАЛ: Сохранение персонажа в БД
     */
    public function actionSave(): Response
    {
        $this->ensureStepAccess(4);

        $data = $this->getWizardData();
        $userId = (int)Yii::$app->user->id;

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // 1. Создание персонажа
            $character = new Character();
            $character->user_id = $userId;
            $character->name = $data['name'];
            $character->gender = (int)$data['gender'];
            $character->race = $data['race_id'];
            $character->class_id = (int)$data['class_id'];
            $character->level = 1;
            $character->exp = 0;
            $character->sp = 0;
            $character->location_id = SettingsRepository::getStartLocationId($character->class_id);
            $character->current = 1;
            $character->last_action_at = time();

            // Сбрасываем активность у остальных персонажей пользователя
            Character::updateAll(['current' => 0], ['user_id' => $userId]);

            // Стартовые виталы на 100%
            $character->cur_hp = CharacterStatService::getMaxHp($character);
            $character->cur_mp = CharacterStatService::getMaxMp($character);
            $character->cur_cp = CharacterStatService::getMaxCp($character);

            if (!$character->save(false)) {
                throw new \Exception('Ошибка сохранения персонажа.');
            }

            // 2. Выдача стартовых предметов
            $starterItems = SettingsRepository::getEquipment($character->class_id);
            foreach ($starterItems as $initialItem) {
                $charItem = new CharacterItem();
                $charItem->character_id = $character->id;
                $charItem->item_id = $initialItem->item->id;
                $charItem->count = $initialItem->count;
                $charItem->enchant = 0;
                $charItem->is_equipped = 0;
                $charItem->save(false);

                if ($initialItem->item->isWeapon() || $initialItem->item->isArmor()) {
                    $charItem->equip();
                }
            }

            // 3. Стартовые авто-скиллы 1 ур.
            $this->grantStarterSkills($character);

            $transaction->commit();

            // Очищаем сессию мастера
            $this->clearWizardData();

            Yii::$app->session->setFlash('success', "Персонаж {$character->name} успешно создан! Добро пожаловать в мир Lineage 2!");
            return $this->redirect(['/game/index']);
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Yii::error("Ошибка мастера создания: " . $e->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Произошла ошибка при создании персонажа.');
            return $this->redirect(['step1']);
        }
    }

    /**
     * Сброс мастера
     */
    public function actionCancel(): Response
    {
        $this->clearWizardData();
        return $this->redirect(['/character/character/index']);
    }

    // =========================================================================
    // СЛУЖЕБНЫЕ МЕТОДЫ И ЗАЩИТА ЭТАПОВ
    // =========================================================================

    /**
     * Защита от перескакивания этапов
     */
    private function ensureStepAccess(int $requiredStep): void
    {
        $data = $this->getWizardData();

        if ($requiredStep >= 2 && empty($data['race_id'])) {
            $this->redirect(['step1'])->send();
            exit;
        }

        if ($requiredStep >= 3 && empty($data['class_id'])) {
            $this->redirect(['step2'])->send();
            exit;
        }

        if ($requiredStep >= 4 && (empty($data['name']) || !isset($data['gender']))) {
            $this->redirect(['step3'])->send();
            exit;
        }
    }

    private function checkCharacterLimit(): void
    {
        $count = Character::find()->where(['user_id' => Yii::$app->user->id])->count();
        if ($count >= 7) {
            Yii::$app->session->setFlash('error', 'Достигнут лимит персонажей на аккаунте (максимум 7).');
            $this->redirect(['/character/default/index'])->send();
            exit;
        }
    }

    private function getWizardData(): array
    {
        return Yii::$app->session->get(self::SESSION_KEY, []);
    }

    private function setWizardData(array $data): void
    {
        Yii::$app->session->set(self::SESSION_KEY, $data);
    }

    private function clearWizardData(): void
    {
        Yii::$app->session->remove(self::SESSION_KEY);
    }

    /**
     * @throws Exception
     */
    private function grantStarterSkills(Character $character): void
    {
        $classTree = SkillTreeRepository::getSkillTreeFromClass($character->class_id);

        foreach ($classTree as $node) {
            if ($node['req_level'] === 1 && !empty($node['auto_get'])) {
                $skill = new CharacterSkill();
                $skill->character_id = $character->id;
                $skill->skill_id = (int)$node['skill_id'];
                $skill->level = (int)$node['level'];
                $skill->save(false);
            }
        }
    }
}
