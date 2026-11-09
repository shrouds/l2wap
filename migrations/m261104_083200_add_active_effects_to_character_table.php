<?php

use yii\db\Migration;

class m261104_083200_add_active_effects_to_character_table extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%character}}', 'active_effects', $this->text()->null()->after('last_action_at'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%character}}', 'active_effects');
    }
}
