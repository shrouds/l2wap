<?php
declare(strict_types=1);

use yii\db\Migration;

/**
 * Создание таблиц персонажей, инвентаря, скиллов, эффектов и сессий боя
 */
class m261028_051356_create_game_tables extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        // =====================================================================
        // 1. ПЕРСОНАЖИ (character)
        // =====================================================================
        $this->createTable('{{%character}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'name' => $this->string(35)->notNull()->unique(),
            'gender' => $this->smallInteger()->notNull()->defaultValue(0), // 0 - male, 1 - female
            'race' => $this->integer()->notNull(),
            'class_id' => $this->integer()->notNull()->defaultValue(0),     // ID базового класса
            'level' => $this->smallInteger()->notNull()->defaultValue(1),
            'exp' => $this->bigInteger()->notNull()->defaultValue(0),       // BIGINT обязателен для C4
            'sp' => $this->integer()->notNull()->defaultValue(0),

            'current' => $this->smallInteger()->notNull()->defaultValue(0),

            // Текущее состояние виталов
            'cur_hp' => $this->float()->notNull()->defaultValue(100),
            'cur_mp' => $this->float()->notNull()->defaultValue(100),
            'cur_cp' => $this->float()->notNull()->defaultValue(100),
            'last_action_at' => $this->integer()->notNull()->defaultValue(time()), // Для WAP-регенерации

            // Текущая локация
            'location_id' => $this->string(64)->notNull()->defaultValue('ti_town'),

            // Карма и PvP
            'karma' => $this->integer()->notNull()->defaultValue(0),
            'pvp_kills' => $this->integer()->notNull()->defaultValue(0),
            'pk_kills' => $this->integer()->notNull()->defaultValue(0),

            // Тумблеры авто-сосок (WAP Quality of Life)
            'auto_soulshot' => $this->boolean()->notNull()->defaultValue(false),
            'auto_spiritshot' => $this->boolean()->notNull()->defaultValue(false),

            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_character_user_id', '{{%character}}', 'user_id');
        $this->addForeignKey(
            'fk_character_user',
            '{{%character}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // =====================================================================
        // 2. ИНВЕНТАРЬ И ЭКИПИРОВКА (character_item)
        // =====================================================================
        $this->createTable('{{%character_item}}', [
            'id' => $this->primaryKey(),
            'character_id' => $this->integer()->notNull(),
            'item_id' => $this->integer()->notNull(),                       // ID из items.php
            'count' => $this->bigInteger()->notNull()->defaultValue(1),     // Кол-во (адена, стрелы)
            'enchant' => $this->smallInteger()->notNull()->defaultValue(0), // Заточка (+0..+16)
            'is_equipped' => $this->boolean()->notNull()->defaultValue(false),
            'equip_slot' => $this->string(32)->null()->defaultValue(null),  // rhand, chest, legs, head...
        ], $tableOptions);

        $this->createIndex('idx_character_item_owner', '{{%character_item}}', ['character_id', 'is_equipped']);
        $this->addForeignKey(
            'fk_item_character',
            '{{%character_item}}',
            'character_id',
            '{{%character}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // =====================================================================
        // 3. ИЗУЧЕННЫЕ СКИЛЛЫ (character_skill)
        // =====================================================================
        $this->createTable('{{%character_skill}}', [
            'id' => $this->primaryKey(),
            'character_id' => $this->integer()->notNull(),
            'skill_id' => $this->integer()->notNull(),                      // ID из skills.php
            'level' => $this->smallInteger()->notNull()->defaultValue(1),
        ], $tableOptions);

        $this->createIndex('idx_character_skill_unique', '{{%character_skill}}', ['character_id', 'skill_id'], true);
        $this->addForeignKey(
            'fk_skill_character',
            '{{%character_skill}}',
            'character_id',
            '{{%character}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // =====================================================================
        // 4. АКТИВНЫЕ БАФФЫ И ЭФФЕКТЫ (character_effect)
        // =====================================================================
        $this->createTable('{{%character_effect}}', [
            'id' => $this->primaryKey(),
            'character_id' => $this->integer()->notNull(),
            'skill_id' => $this->integer()->notNull(),
            'level' => $this->smallInteger()->notNull()->defaultValue(1),
            'expire_at' => $this->integer()->notNull(),                     // UNIX-таймстемп окончания
        ], $tableOptions);

        $this->createIndex('idx_character_effect_expire', '{{%character_effect}}', ['character_id', 'expire_at']);
        $this->addForeignKey(
            'fk_effect_character',
            '{{%character_effect}}',
            'character_id',
            '{{%character}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // =====================================================================
        // 5. АКТИВНАЯ СЕССИЯ БОЯ (character_battle)
        // =====================================================================
        $this->createTable('{{%character_battle}}', [
            'character_id' => $this->primaryKey(),                          // 1 персонаж — максимум 1 бой
            'npc_id' => $this->integer()->notNull(),
            'npc_cur_hp' => $this->float()->notNull(),
            'npc_cur_mp' => $this->float()->notNull(),
            'round' => $this->integer()->notNull()->defaultValue(1),
            'log' => $this->text()->null(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->addForeignKey(
            'fk_battle_character',
            '{{%character_battle}}',
            'character_id',
            '{{%character}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%character_battle}}');
        $this->dropTable('{{%character_effect}}');
        $this->dropTable('{{%character_skill}}');
        $this->dropTable('{{%character_item}}');
        $this->dropTable('{{%character}}');
    }
}
