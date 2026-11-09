<?php

namespace app\models\game;

class Race
{
    public const HUMAN     = 0;
    public const ELF       = 1;
    public const DARK_ELF  = 2;
    public const ORC       = 3;
    public const DWARF     = 4;

    public function __construct(
        public int    $id,
        public string $code,
        public string $name,
        public string $description,
        public string $startLocationId,
        /** @var int[] ID стартовых базовых классов этой расы */
        public array  $starterClassIds = []
    ) {}

    /**
     * Реестр всех 5 рас Lineage 2
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [
            self::HUMAN => new self(
                id: self::HUMAN,
                code: 'human',
                name: 'Люди (Human)',
                description: 'Сбалансированная раса, созданная богиней созидания Эйнхасад. Обладает средними характеристиками во всем.',
                startLocationId: 'ti_town',
                starterClassIds: [0, 10] // Human Fighter, Human Mystic
            ),
            self::ELF => new self(
                id: self::ELF,
                code: 'elf',
                name: 'Светлые Эльфы (Elf)',
                description: 'Дети богини воды Евы. Обладают высокой ловкостью, скоростью бега и быстротой произнесения заклинаний.',
                startLocationId: 'elven_village',
                starterClassIds: [18, 25] // Elven Fighter, Elven Mystic
            ),
            self::DARK_ELF => new self(
                id: self::DARK_ELF,
                code: 'dark_elf',
                name: 'Темные Эльфы (Dark Elf)',
                description: 'Последователи богини смерти Шилен. Обладают максимальной силой физической и магической атаки, но низкой выносливостью.',
                startLocationId: 'darkelf_village',
                starterClassIds: [31, 38] // Dark Fighter, Dark Mystic
            ),
            self::ORC => new self(
                id: self::ORC,
                code: 'orc',
                name: 'Орки (Orc)',
                description: 'Создания бога огня Паагрио. Обладают колоссальным запасом здоровья (HP), физической мощью и быстрой регенерацией.',
                startLocationId: 'orc_village',
                starterClassIds: [44, 49] // Orc Fighter, Orc Mystic
            ),
            self::DWARF => new self(
                id: self::DWARF,
                code: 'dwarf',
                name: 'Гномы (Dwarf)',
                description: 'Дети богини земли Мафр. Мастера добычи ресурсов (Спойл) и создания экипировки (Крафт). Имеют увеличенный инвентарь.',
                startLocationId: 'dwarf_village',
                starterClassIds: [53] // Dwarven Fighter
            ),
        ];
    }

    /**
     * Найти расу по ID (0..4)
     */
    public static function find(int $raceId): ?self
    {
        return self::all()[$raceId] ?? null;
    }

    /**
     * Определение расы по любому class_id (включая 1, 2 и 3 профессии)
     */
    public static function findByClassId(int $classId): ?self
    {
        // 1. Быстрая проверка стартовых классов
        foreach (self::all() as $race) {
            if (in_array($classId, $race->starterClassIds, true)) {
                return $race;
            }
        }

        // 2. Полное дерево профессий L2 C4
        if ($classId >= 0 && $classId <= 17) {
            return self::find(self::HUMAN);
        }
        if ($classId >= 18 && $classId <= 30) {
            return self::find(self::ELF);
        }
        if ($classId >= 31 && $classId <= 43) {
            return self::find(self::DARK_ELF);
        }
        if ($classId >= 44 && $classId <= 52) {
            return self::find(self::ORC);
        }
        if ($classId >= 53 && $classId <= 57) {
            return self::find(self::DWARF);
        }

        return null;
    }

    /**
     * Список стартовых классов для формы выбора
     * @return array<int, string> [class_id => 'Название класса']
     */
    public function getStarterClassesList(): array
    {
        $list = [];
        foreach ($this->starterClassIds as $classId) {
            $classData = PcParamsRepository::getClass($classId);
            $list[$classId] = $classData['name'] ?? "Class {$classId}";
        }
        return $list;
    }

    /**
     * Стартовое снаряжение новичка через SettingsRepository
     * @return InitialItem[]
     */
    public function getInitialEquipment(int $classId): array
    {
        if (!in_array($classId, $this->starterClassIds, true)) {
            return [];
        }

        return SettingsRepository::getEquipment($classId);
    }
}
