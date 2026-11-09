<?php

namespace app\models;

use app\models\game\Npc;
use app\models\game\NpcRepository;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "character_battle".
 *
 * @property int $character_id
 * @property int $npc_id
 * @property float $npc_cur_hp
 * @property float $npc_cur_mp
 * @property int $round
 * @property string|null $log
 * @property int $updated_at
 *
 * @property Character $character
 */
class CharacterBattle extends ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'character_battle';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['log'], 'default', 'value' => null],
            [['round'], 'default', 'value' => 1],
            [['npc_id', 'npc_cur_hp', 'npc_cur_mp', 'updated_at'], 'required'],
            [['npc_id', 'round', 'updated_at'], 'integer'],
            [['npc_cur_hp', 'npc_cur_mp'], 'number'],
            [['log'], 'string'],
            [['character_id'], 'exist', 'skipOnError' => true, 'targetClass' => Character::class, 'targetAttribute' => ['character_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels(): array
    {
        return [
            'character_id' => 'Character ID',
            'npc_id' => 'Npc ID',
            'npc_cur_hp' => 'Npc Cur Hp',
            'npc_cur_mp' => 'Npc Cur Mp',
            'round' => 'Round',
            'log' => 'Log',
            'updated_at' => 'Updated At',
        ];
    }

    public function getCharacter(): ActiveQuery
    {
        return $this->hasOne(Character::class, ['id' => 'character_id']);
    }

    /**
     * Получить шаблон текущего монстра из репозитория
     */
    public function getNpc(): ?Npc
    {
        return NpcRepository::find($this->npc_id);
    }

    /**
     * Добавить запись в боевой лог
     */
    public function addLog(string $message): void
    {
        $logs = $this->getLogList();
        $logs[] = $message;

        // Храним только последние 8 записей раундов, чтобы не раздувать поле
        if (count($logs) > 8) {
            $logs = array_slice($logs, -8);
        }

        $this->log = json_encode($logs, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получить массив записей боевого лога
     * @return string[]
     */
    public function getLogList(): array
    {
        if (empty($this->log)) {
            return [];
        }

        $decoded = json_decode($this->log, true);
        return is_array($decoded) ? $decoded : [];
    }

}
