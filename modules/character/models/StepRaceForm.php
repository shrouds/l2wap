<?php
declare(strict_types=1);

namespace app\modules\character\models;

use yii\base\Model;
use app\models\game\Race;

class StepRaceForm extends Model
{
    public ?int $race_id = null;

    public function rules(): array
    {
        return [
            ['race_id', 'required', 'message' => 'Выберите расу персонажа.'],
            ['race_id', 'integer'],
            ['race_id', 'in', 'range' => array_keys(Race::all()), 'message' => 'Некорректная раса.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'race_id' => 'Раса',
        ];
    }
}
