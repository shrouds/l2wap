<?php
declare(strict_types=1);

namespace app\modules\character\models;

use yii\base\Model;
use app\models\game\Race;

class StepClassForm extends Model
{
    public ?int $class_id = null;
    public int $race_id;

    public function rules(): array
    {
        return [
            ['class_id', 'required', 'message' => 'Выберите стартовый класс.'],
            ['class_id', 'integer'],
            ['class_id', 'validateClassBelongsToRace'],
        ];
    }

    public function validateClassBelongsToRace(string $attribute): void
    {
        $race = Race::find($this->race_id);
        if ($race === null || !in_array($this->class_id, $race->starterClassIds, true)) {
            $this->addError($attribute, 'Этот класс недоступен для выбранной расы.');
        }
    }

    public function attributeLabels(): array
    {
        return [
            'class_id' => 'Стартовый класс',
        ];
    }
}
