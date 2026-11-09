<?php

namespace app\models;

use app\models\game\Skill;
use app\models\game\SkillRepository;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "character_skill".
 *
 * @property int $id
 * @property int $character_id
 * @property int $skill_id
 * @property int $level
 *
 * @property Character $character
 */
class CharacterSkill extends ActiveRecord
{

    private ?Skill $_skillCache = null;

    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'character_skill';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['level'], 'default', 'value' => 1],
            [['character_id', 'skill_id'], 'required'],
            [['character_id', 'skill_id', 'level'], 'integer'],
            [['character_id', 'skill_id'], 'unique', 'targetAttribute' => ['character_id', 'skill_id']],
            [['character_id'], 'exist', 'skipOnError' => true, 'targetClass' => Character::class, 'targetAttribute' => ['character_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'character_id' => 'Character ID',
            'skill_id' => 'Skill ID',
            'level' => 'Level',
        ];
    }

    /**
     * Gets query for [[Character]].
     *
     * @return ActiveQuery
     */
    public function getCharacter(): ActiveQuery
    {
        return $this->hasOne(Character::class, ['id' => 'character_id']);
    }

    public function getSkill(): ?Skill
    {
        if ($this->_skillCache === null) {
            $this->_skillCache = SkillRepository::find((int)$this->skill_id, (int)$this->level);
        }
        return $this->_skillCache;
    }
}
