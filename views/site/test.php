<?php

/** @var yii\web\View $this */
/** @var Npc $npc */
/** @var Item $item */

use app\models\game\Item;
use app\models\game\Npc;
use yii\helpers\Html;

$this->title = 'TEST';
$this->params['breadcrumbs'][] = $this->title;
?>


NPC NAME^ <?= $npc->getName()?>

</br>
Item Name: <b><?= $item->getName('ru')?></b>
Item Description: <b><?= $item->getDescription('ru')?></b>

<img width="32" height="32" src="<?=$item->getIconUrl()?>" alt="icon">
