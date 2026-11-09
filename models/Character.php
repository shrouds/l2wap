<?php

namespace app\models;

use app\components\game\BattleService;
use app\components\game\CharacterStatService;
use app\models\game\ExpTableRepository;
use app\models\game\Location;
use app\models\game\LocationRepository;
use app\models\game\PcParamsRepository;
use app\models\game\Race;
use Throwable;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Exception;

/**
 * This is the model class for table "character".
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $gender
 * @property int $race
 * @property int $class_id
 * @property int $level
 * @property int $exp
 * @property int $sp
 * @property int $current
 * @property float $cur_hp
 * @property float $cur_mp
 * @property float $cur_cp
 * @property int $last_action_at
 * @property string $location_id
 * @property int $karma
 * @property int $pvp_kills
 * @property int $pk_kills
 * @property int $auto_soulshot
 * @property int $auto_spiritshot
 * @property int $created_at
 * @property int $updated_at
 *
 * @property CharacterBattle $characterBattle
 * @property CharacterEffect[] $characterEffects
 * @property CharacterItem[] $characterItems
 * @property CharacterSkill[] $characterSkills
 * @property User $user
 * @property CharacterItem[] $equippedItems
 * @property CharacterItem[] $characterNoEquipItems
 *
 * @property Race $characterRace
 *
 * @property string|null $active_effects
 *
 * @property int $maxHp
 * @property int $maxMp
 * @property int $maxCp
 *
 * @property int $pAtc
 * @property int $mAtk
 * @property int $mDef
 * @property int $pDef
 *
 * @property int $castSpeed
 * @property int $attackSpeed
 *
 * @property-read array $classInfo
 * @property-read string $className
 *
 * @property-read int $str
 * @property-read int $dex
 * @property-read int $con
 * @property-read int $int
 * @property-read int $wit
 * @property-read int $men
 *
 * @property-read int $totalWeight
 * @property-read int $maxWeight
 * @property-read float $weightPercent
 * @property-read int $usedInventorySlots
 * @property-read int $maxInventorySlots
 * @property-read float $expPercent
 * @property-read int $nextLevelExp
 */
class Character extends ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'character';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['auto_spiritshot'], 'default', 'value' => 0],
            [['level'], 'default', 'value' => 1],
            [['cur_cp'], 'default', 'value' => 100],
            //[['last_action_at'], 'default', 'value' => 1793165036],
            [['location_id'], 'default', 'value' => 'ti_town'],
            [['user_id', 'name'], 'required'],
            [['user_id', 'gender', 'class_id', 'race', 'level', 'exp', 'sp', 'current', 'last_action_at', 'karma', 'pvp_kills', 'pk_kills', 'auto_soulshot', 'auto_spiritshot', 'created_at', 'updated_at'], 'integer'],
            [['cur_hp', 'cur_mp', 'cur_cp'], 'number'],
            [['name'], 'string', 'max' => 35],
            [['active_effects'], 'string'],
            [['location_id'], 'string', 'max' => 64],
            [['name'], 'unique'],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'name' => 'Name',
            'gender' => 'Gender',
            'race' => 'Race',
            'class_id' => 'Class ID',
            'level' => 'Level',
            'exp' => 'Exp',
            'sp' => 'Sp',
            'current' => 'Current',
            'cur_hp' => 'Cur Hp',
            'cur_mp' => 'Cur Mp',
            'cur_cp' => 'Cur Cp',
            'last_action_at' => 'Last Action At',
            'location_id' => 'Location ID',
            'karma' => 'Karma',
            'pvp_kills' => 'Pvp Kills',
            'pk_kills' => 'Pk Kills',
            'auto_soulshot' => 'Auto Soulshot',
            'auto_spiritshot' => 'Auto Spiritshot',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Получить текущего (активного) персонажа игрока
     */
    public static function getCurrent(?int $userId = null): ?self
    {
        if ($userId === null) {
            $userId = Yii::$app->user->id;
        }
        return static::find()
            ->where(['user_id' => $userId, 'current' => 1])
            ->one();
    }

    /**
     * Gets query for [[CharacterBattle]].
     *
     * @return ActiveQuery
     */
    public function getCharacterBattle(): ActiveQuery
    {
        return $this->hasOne(CharacterBattle::class, ['character_id' => 'id']);
    }

    /**
     * Gets query for [[CharacterEffects]].
     *
     * @return ActiveQuery
     */
    public function getCharacterEffects(): ActiveQuery
    {
        return $this->hasMany(CharacterEffect::class, ['character_id' => 'id']);
    }

    /**
     * Gets query for [[CharacterItems]].
     *
     * @return ActiveQuery
     */
    public function getCharacterItems(): ActiveQuery
    {
        return $this->hasMany(CharacterItem::class, ['character_id' => 'id']);
    }

    public function getCharacterNoEquipItems(): ActiveQuery
    {
        return $this->getCharacterItems()->where(['is_equipped' => 0])->andWhere(['>', 'count', 0]);
    }

    /**
     * Gets query for [[CharacterSkills]].
     *
     * @return ActiveQuery
     */
    public function getCharacterSkills(): ActiveQuery
    {
        return $this->hasMany(CharacterSkill::class, ['character_id' => 'id']);
    }

    /**
     * Gets query for [[User]].
     *
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Персонаж в бою
     * @return bool
     */
    public function isInBattle(): bool
    {
        return BattleService::hasBattle($this->id);
    }

    /**
     * Сделать этого персонажа активным для аккаунта
     */
    public function makeCurrent(): bool
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Сбрасываем флаг у всех персонажей данного пользователя
            static::updateAll(['current' => 0], ['user_id' => $this->user_id]);

            // Активируем выбранного
            $this->current = 1;
            $this->save(false, ['current', 'updated_at']);

            $transaction->commit();
            return true;
        } catch (Throwable $e) {
            $transaction->rollBack();
            return false;
        }
    }

    /**
     * Информация о расе
     * @return Race
     */
    public function getCharacterRace(): Race
    {
        return Race::find((int)$this->race);
    }

    /**
     * Только надетые предметы
     */
    public function getEquippedItems(): ActiveQuery
    {
        return $this->getCharacterItems()->where(['is_equipped' => 1]);
    }

    /**
     * Получить объект текущей локации
     */
    public function getLocation(): ?Location
    {
        return LocationRepository::find($this->location_id);
    }

    /**
     * Количество Адены в инвентаре
     */
    public function getAdena(): int
    {
        $adena = $this->getCharacterItems()
            ->where(['item_id' => 57]) // 57 - классический ID адены в L2
            ->one();

        return $adena == null ? 0 : $adena->count;

    }

    /**
     * Списать адену с персонажа
     * @throws Exception|Throwable
     */
    public function takeAdena(int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        /** @var CharacterItem|null $adena */
        $adena = $this->getCharacterItems()->where(['item_id' => 57])->one();
        if (!$adena || $adena->count < $amount) {
            return false;
        }

        $adena->count -= $amount;
        if ($adena->count <= 0) {
            $adena->delete();
        } else {
            $adena->save(false, ['count']);
        }

        return true;
    }

    /**
     * Добавить адену
     * @throws Exception
     */
    public function addAdena(int $amount): void
    {
        if ($amount <= 0) return;

        CharacterItem::addItem($this->id, 57, $amount);
    }

    /**
     * Честная регенерация HP / MP / CP на основе формул PTS C4
     * @throws Exception
     */
    public function applyRegen(): void
    {
        $now = time();
        $elapsedSec = max(0, $now - $this->last_action_at);

        // Меньше 2 секунд — не дергаем БД
        if ($elapsedSec < 2) {
            return;
        }

        // Ограничиваем оффлайн-регенерацию максимум 10 минутами (600 сек)
        $elapsedSec = min($elapsedSec, 600);

        $maxHp = CharacterStatService::getMaxHp($this);
        $maxMp = CharacterStatService::getMaxMp($this);
        $maxCp = CharacterStatService::getMaxCp($this);

        $hpPerSec = CharacterStatService::getHpRegenPerSecond($this);
        $mpPerSec = CharacterStatService::getMpRegenPerSecond($this);
        $cpPerSec = CharacterStatService::getCpRegenPerSecond($this);

        $this->cur_hp = min((float)$maxHp, $this->cur_hp + ($hpPerSec * $elapsedSec));
        $this->cur_mp = min((float)$maxMp, $this->cur_mp + ($mpPerSec * $elapsedSec));
        $this->cur_cp = min((float)$maxCp, $this->cur_cp + ($cpPerSec * $elapsedSec));
        $this->last_action_at = $now;

        $this->save(false, ['cur_hp', 'cur_mp', 'cur_cp', 'last_action_at', 'updated_at']);
    }

    // Удобные виртуальные геттеры для представлений:
    public function getMaxHp(): int
    {
        return CharacterStatService::getMaxHp($this);
    }

    public function getMaxMp(): int
    {
        return CharacterStatService::getMaxMp($this);
    }

    public function getMaxCp(): int
    {
        return CharacterStatService::getMaxCp($this);
    }

    public function getPAtk(): int
    {
        return CharacterStatService::getPAtk($this);
    }

    public function getPDef(): int
    {
        return CharacterStatService::getPDef($this);
    }

    public function getMDef(): int
    {
        return CharacterStatService::getMDef($this);
    }

    public function getMAtk(): int
    {
        return CharacterStatService::getMAtk($this);
    }

    public function getCastSpeed(): int
    {
        return CharacterStatService::getCastSpeed($this);
    }

    public function getAttackSpeed(): int
    {
        return CharacterStatService::getAttackSpeed($this);
    }

    // =========================================================================
    // БАЗОВЫЕ ПАРАМЕТРЫ КЛАССА (STR, DEX, CON, INT, WIT, MEN)
    // =========================================================================

    public function getClassInfo(): array
    {
        return PcParamsRepository::getClass($this->class_id) ?? [];
    }

    public function getClassName(): string
    {
        return $this->getClassInfo()['name'] ?? 'Воин';
    }

    public function getStr(): int
    {
        // База класса (позже сюда добавятся: + краска + бонус сета брони)
        return (int)($this->getClassInfo()['str'] ?? 40);
    }

    public function getDex(): int
    {
        return (int)($this->getClassInfo()['dex'] ?? 30);
    }

    public function getCon(): int
    {
        return (int)($this->getClassInfo()['con'] ?? 43);
    }

    public function getInt(): int
    {
        return (int)($this->getClassInfo()['int'] ?? 21);
    }

    public function getWit(): int
    {
        return (int)($this->getClassInfo()['wit'] ?? 11);
    }

    public function getMen(): int
    {
        return (int)($this->getClassInfo()['men'] ?? 25);
    }

    // =========================================================================
    // ИНВЕНТАРЬ, ВЕС И СЛОТЫ
    // =========================================================================

    /**
     * Текущий суммарный вес всех предметов в рюкзаке
     */
    public function getTotalWeight(): int
    {
        $weight = 0;
        foreach ($this->characterItems as $invItem) {
            $template = $invItem->getItem();
            if ($template) {
                $weight += ($template->weight * $invItem->count);
            }
        }
        return $weight;
    }

    /**
     * Максимально переносимый вес (PTS C4: CON + пассивки Weight Limit + баффы)
     */
    public function getMaxWeight(): int
    {
        return CharacterStatService::getMaxWeight($this);
    }

    /**
     * Процент загрузки инвентаря весом
     */
    public function getWeightPercent(): float
    {
        $max = max(1, $this->getMaxWeight());
        return min(100.0, round(($this->getTotalWeight() / $max) * 100, 1));
    }

    /**
     * Количество занятых ячеек рюкзака (адена и ассеты слот не занимают)
     */
    public function getUsedInventorySlots(): int
    {
        $count = 0;
        foreach ($this->characterItems as $invItem) {
            $template = $invItem->getItem();
            if ($invItem->count > 0 && ($template === null || $template->type !== 'asset')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Лимит слотов инвентаря (База расы + пассивки Expand Inventory)
     */
    public function getMaxInventorySlots(): int
    {
        return CharacterStatService::getMaxInventorySlots($this);
    }

    // =========================================================================
    // ОПЫТ И ПРОГРЕСС ДО СЛЕДУЮЩЕГО УРОВНЯ
    // =========================================================================

    public function getCurrentLevelBaseExp(): int
    {
        return ExpTableRepository::getCurrentLvlExp($this->level);
    }

    public function getNextLevelExp(): int
    {
        return ExpTableRepository::getNextLvlExp($this->level);
    }

    public function getExpPercent(): float
    {
        if ($this->level >= 80) {
            return 100.0;
        }

        $baseExp = $this->getCurrentLevelBaseExp();
        $nextExp = $this->getNextLevelExp();
        $range = max(1, $nextExp - $baseExp);
        $gained = max(0, $this->exp - $baseExp);

        return min(100.0, round(($gained / $range) * 100, 2));
    }

    /**
     * Максимальное количество целей при атаке пикой (База + пассивки Wide Sweep)
     */
    public function getMaxAttackTargets(): int
    {
        return CharacterStatService::getMaxAttackTargets($this);
    }

    /**
     * Скорость бега
     */
    public function getRunSpeed(): int
    {
        return CharacterStatService::getRunSpeed($this);
    }

    public function getHpPercent(): int
    {
        $max = max(1,$this->maxHp);
        return (int)min(100, max(0, round(($this->cur_hp / $max) * 100)));
    }

    public function getMpPercent(): int
    {
        $max = max(1,$this->maxMp);
        return (int)min(100, max(0, round(($this->cur_mp / $max) * 100)));
    }

    public function getCpPercent(): int
    {
        $max = max(1,$this->maxCp);
        return (int)min(100, max(0, round(($this->cur_cp / $max) * 100)));
    }

    /**
     * Сборка данных для динамического превью на арене боя
     */
    public function getVisualAvatar(): array
    {
        $equipped = [];
        foreach ($this->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item) {
                $equipped[$invItem->equip_slot] = [
                    'id'          => $item->id,
                    'type'        => $item->type,
                    'weapon_type' => $item->weaponType,
                    'armor_type'  => $item->armorType,
                    'grade'       => strtolower($item->grade),
                    'enchant'     => $invItem->enchant,
                    'icon'        => $item->getIconUrl(),
                ];
            }
        }

        // Определение базового архетипа брони
        $chest = $equipped['chest'] ?? ($equipped['onepiece'] ?? null);
        $armorType = $chest ? $chest['armor_type'] : 'naked';
        $armorGrade = $chest ? $chest['grade'] : 'none';

        // Раса и пол (0 - male, 1 - female)
        $genderStr = $this->gender === 1 ? 'f' : 'm';
        $raceStr = $this->race; // human, elf, darkelf, orc, dwarf

        return [
            'base_body' => "/images/avatars/bodies/{$raceStr}_{$genderStr}.png",
            'armor_set' => "/images/avatars/armors/{$raceStr}_{$genderStr}_{$armorType}_{$armorGrade}.png",
            'rhand'     => isset($equipped['rhand']) ? "/images/avatars/weapons/{$equipped['rhand']['weapon_type']}_{$equipped['rhand']['grade']}.png" : null,
            'lhand'     => isset($equipped['lhand']) ? "/images/avatars/shields/{$equipped['lhand']['id']}.png" : null,
            'enchant'   => $equipped['rhand']['enchant'] ?? 0,
            'has_shots' => $this->auto_soulshot || $this->auto_spiritshot,
        ];
    }

    /**
     * Сборка визуальных данных экипировки и портрета для боевой арены
     */
    public function getBattleAvatar(): array
    {
        $equippedWeapon = null;
        $equippedArmor = null;
        $equippedShield = null;

        foreach ($this->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if (!$item) {
                continue;
            }

            if ($item->isWeapon()) {
                $equippedWeapon = [
                    'name'    => $item->getName(),
                    'icon'    => $item->getIconUrl(),
                    'enchant' => $invItem->enchant,
                    'grade'   => strtolower($item->grade),
                ];
            } elseif ($item->isArmor() && in_array($invItem->equip_slot, ['chest', 'onepiece'], true)) {
                $equippedArmor = [
                    'name'  => $item->getName(),
                    'icon'  => $item->getIconUrl(),
                    'grade' => strtolower($item->grade),
                ];
            } elseif ($item->isShield()) {
                $equippedShield = [
                    'name' => $item->getName(),
                    'icon' => $item->getIconUrl(),
                ];
            }
        }

        // Определение расы по классу (0-17 Human, 18-30 Elf, 31-43 Dark Elf, 44-52 Orc, 53-57 Dwarf)
        $race = 'human';
        if ($this->class_id >= 18 && $this->class_id <= 30) {
            $race = 'elf';
        } elseif ($this->class_id >= 31 && $this->class_id <= 43) {
            $race = 'darkelf';
        } elseif ($this->class_id >= 44 && $this->class_id <= 52) {
            $race = 'orc';
        } elseif ($this->class_id >= 53) {
            $race = 'dwarf';
        }

        $gender = ($this->gender === 1) ? 'f' : 'm';
        $avatarPath = "/images/avatars/{$race}_{$gender}.png";
        $fallbackAvatar = "/images/icons/no_icon.png";

        return [
            'portrait' => file_exists(Yii::getAlias("@webroot{$avatarPath}")) ? $avatarPath : $fallbackAvatar,
            'weapon'   => $equippedWeapon,
            'armor'    => $equippedArmor,
            'shield'   => $equippedShield,
            'has_ss'   => (bool)$this->auto_soulshot,
            'has_sps'  => (bool)$this->auto_spiritshot,
        ];
    }

}
