<?php

namespace app\modules\character;

use yii\base\Module;

/**
 * character module definition class
 */
class Character extends Module
{
    /**
     * {@inheritdoc}
     */
    public $controllerNamespace = 'app\modules\character\controllers';
    public $layout = 'game';

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        parent::init();

        $this->defaultRoute = 'character';

    }
}
