<?php
declare(strict_types=1);

namespace app\modules\character\models;

use yii\base\Model;
use app\models\Character;

class StepProfileForm extends Model
{
    public ?string $name = null;
    public int $gender = 0; // 0 - Male, 1 - Female

    public function rules(): array
    {
        return [
            [['name', 'gender'], 'required'],
            ['gender', 'in', 'range' => [0, 1]],
            ['name', 'trim'],
            ['name', 'string', 'min' => 3, 'max' => 16, 'tooShort' => 'Имя должно содержать от 3 до 16 символов.'],
            ['name', 'match', 'pattern' => '/^[a-zA-Z0-9_-]+$/u', 'message' => 'Имя может содержать только латиницу, цифры и дефис.'],
            ['name', 'unique', 'targetClass' => Character::class, 'targetAttribute' => 'name', 'message' => 'Персонаж с таким именем уже существует.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Имя персонажа',
            'gender' => 'Пол',
        ];
    }
}
