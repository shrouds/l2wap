<?php

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "character_effect".
 *
 * @property int $id
 * @property int $character_id
 * @property int $skill_id
 * @property int $level
 * @property int $expire_at
 *
 * @property Character $character
 */
class CharacterEffect extends ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'character_effect';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['level'], 'default', 'value' => 1],
            [['character_id', 'skill_id', 'expire_at'], 'required'],
            [['character_id', 'skill_id', 'level', 'expire_at'], 'integer'],
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
            'expire_at' => 'Expire At',
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

}
