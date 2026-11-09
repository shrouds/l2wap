<?php

namespace app\models;

use app\models\game\Item;
use app\models\game\ItemRepository;
use Yii;
use yii\db\ActiveQuery;
use yii\db\Exception;

/**
 * This is the model class for table "character_item".
 *
 * @property int $id
 * @property int $character_id
 * @property int $item_id
 * @property int $count
 * @property int $enchant
 * @property int $is_equipped
 * @property string|null $equip_slot
 *
 * @property Character $character
 */
class CharacterItem extends \yii\db\ActiveRecord
{

    const SLOTS = [
        'head'      => 'Шлем',
        'chest'     => 'Доспех (верх)',
        'legs'      => 'Штаны (низ)',
        'gloves'    => 'Перчатки',
        'feet'      => 'Ботинки',
        'rhand'     => 'Оружие',
        'lhand'     => 'Щит / Стрелы',
        'neck'      => 'Ожерелье',
        'rear_1'    => 'Серьга (R)',
        'rear_2'    => 'Серьга (L)',
        'rfinger_1' => 'Кольцо (R)',
        'rfinger_2' => 'Кольцо (L)',
    ];

    private ?Item $_itemCache = null;

    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'character_item';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['equip_slot'], 'default', 'value' => null],
            [['count'], 'default', 'value' => 1],
            [['is_equipped'], 'default', 'value' => 0],
            [['character_id', 'item_id'], 'required'],
            [['character_id', 'item_id', 'count', 'enchant', 'is_equipped'], 'integer'],
            [['equip_slot'], 'string', 'max' => 32],
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
            'item_id' => 'Item ID',
            'count' => 'Count',
            'enchant' => 'Enchant',
            'is_equipped' => 'Is Equipped',
            'equip_slot' => 'Equip Slot',
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

    /**
     * Связка с неизменяемым PTS-предметом из ItemRepository
     */
    public function getItem(): ?Item
    {
        if ($this->_itemCache === null) {
            $this->_itemCache = ItemRepository::find((int)$this->item_id);
        }
        return $this->_itemCache;
    }

    /**
     * Определение слота, куда надеть вещь
     */
    private function resolveEquipSlot(Item $item): ?string
    {
        $slotType = $item->slot;

        // Обычные одиночные слоты
        if (in_array($slotType, ['chest', 'legs', 'head', 'gloves', 'feet', 'neck', 'lhand'], true)) {
            return $slotType;
        }

        // Оружие
        if ($slotType === 'rhand' || $slotType === 'lrhand') {
            return $slotType;
        }

        // Цельный доспех
        if ($slotType === 'onepiece') {
            return 'onepiece';
        }

        // Парные кольца: надеваем в rfinger_1, если занят — в rfinger_2
        if ($slotType === 'rfinger' || $slotType === 'finger') {
            $first = CharacterItem::find()->where(['character_id' => $this->character_id, 'equip_slot' => 'rfinger_1'])->exists();
            return $first ? 'rfinger_2' : 'rfinger_1';
        }

        // Парные серьги: надеваем в rear_1, если занят — в rear_2
        if ($slotType === 'rear' || $slotType === 'ear') {
            $first = CharacterItem::find()->where(['character_id' => $this->character_id, 'equip_slot' => 'rear_1'])->exists();
            return $first ? 'rear_2' : 'rear_1';
        }

        return null;
    }

    /**
     * Автоматически снимает старые вещи, которые мешают надеть новую
     */
    private function freeConflictingSlots(string $slot, Item $item): void
    {
        $slotsToFree = [$slot];

        // Двуручное оружие занимает rhand, lrhand и выбивает щит lhand
        if ($slot === 'lrhand') {
            $slotsToFree = ['rhand', 'lrhand', 'lhand'];
        }

        // Если надеваем щит (lhand), а в руках двуручник — снимаем двуручник
        if ($slot === 'lhand') {
            $slotsToFree[] = 'lrhand';
        }

        // Цельный доспех onepiece выбивает и верх, и низ
        if ($slot === 'onepiece') {
            $slotsToFree = ['chest', 'legs', 'onepiece'];
        }

        // Если надеваем куртку или штаны — снимаем цельный onepiece
        if ($slot === 'chest' || $slot === 'legs') {
            $slotsToFree[] = 'onepiece';
        }

        // Снимаем всё, что занимает эти слоты
        CharacterItem::updateAll(
            ['is_equipped' => 0, 'equip_slot' => null],
            ['character_id' => $this->character_id, 'equip_slot' => $slotsToFree]
        );
    }

    /**
     * Надеть предмет
     */
    public function equip(): bool
    {
        $item = $this->getItem();
        if (!$item || $this->is_equipped) {
            return false;
        }

        $targetSlot = $this->resolveEquipSlot($item);
        if (!$targetSlot) {
            return false;
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // 1. Освобождаем конфликтующие слоты
            $this->freeConflictingSlots($targetSlot, $item);

            // 2. Одеваем этот предмет
            $this->is_equipped = 1;
            $this->equip_slot = $targetSlot;
            $this->save(false);

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return false;
        }
    }

    /**
     * Снять предмет
     * @throws Exception
     */
    public function unequip(): bool
    {
        if (!$this->is_equipped) {
            return false;
        }

        $this->is_equipped = 0;
        $this->equip_slot = null;
        return $this->save(false);
    }

    /**
     * Получить существующий предмет в инвентаре или создать новый.
     *
     * - Для стакуемых предметов (адена, стрелы, зелья, ресурсы):
     *   находит незанятый в экипировке слот и увеличивает count.
     * - Для нестакуемых предметов (оружие, броня, бижутерия):
     *   создает индивидуальные записи с count = 1.
     *
     * @param int $characterId ID владельца
     * @param int $itemId ID шаблона предмета
     * @param int $count Количество для начисления
     * @return self
     * @throws Exception
     */
    public static function addItem(int $characterId, int $itemId, int $count = 1): self
    {
        if ($count <= 0) {
            $count = 1;
        }

        $template = ItemRepository::find($itemId);
        $isStackable = $template?->isStackable ?? false;

        // 1. Стакуемый предмет: ищем существующую стопку в рюкзаке (не надетую)
        if ($isStackable) {
            /** @var self|null $existing */
            $existing = self::find()
                ->where([
                    'character_id' => $characterId,
                    'item_id'      => $itemId,
                    'is_equipped'  => 0,
                ])
                ->one();

            if ($existing !== null) {
                $existing->count += $count;
                $existing->save(false, ['count']);
                return $existing;
            }

            // Стопки нет — создаем новую с полным количеством
            $charItem = new self();
            $charItem->character_id = $characterId;
            $charItem->item_id = $itemId;
            $charItem->count = $count;
            $charItem->is_equipped = 0;
            $charItem->save(false);

            return $charItem;
        }

        // 2. Нестакуемый предмет: создаем отдельные записи под каждую штуку
        $lastItem = null;
        for ($i = 0; $i < $count; $i++) {
            $charItem = new self();
            $charItem->character_id = $characterId;
            $charItem->item_id = $itemId;
            $charItem->count = 1;
            $charItem->is_equipped = 0;
            $charItem->save(false);
            $lastItem = $charItem;
        }

        return $lastItem;
    }

}
