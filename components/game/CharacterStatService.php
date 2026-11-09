<?php
declare(strict_types=1);

namespace app\components\game;

use app\models\Character;
use app\models\game\PcParamsRepository;

class CharacterStatService
{
    /**
     * Итоговый Max HP: (База * CON + бонусы предметов) -> Пассивки -> Баффы
     */
    public static function getMaxHp(Character $character): int
    {
        $baseHp = PcParamsRepository::getBaseHp($character->class_id, $character->level);
        $classInfo = PcParamsRepository::getClass($character->class_id);
        $con = (int)($classInfo['con'] ?? 40);

        $conModifier = PcParamsRepository::getStatModifier('con', $con);
        $baseWithCon = $baseHp * $conModifier;

        $gearHpBonus = 0;
        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item) {
                $gearHpBonus += (int)($item->hpBonus ?? $item->hp_bonus ?? 0);
            }
        }

        $totalHp = $baseWithCon + $gearHpBonus;

        // 1. Пассивные умения (Boost HP и др.)
        $withPassives = self::applyPassives((float)$totalHp, 'p_max_hp', $character);

        // 2. Временные баффы (Bless the Body и др.)
        $finalHp = self::applyModifiers($withPassives, 'p_max_hp', $character);

        return max(1, (int)round($finalHp));
    }

    /**
     * Итоговый Max MP: (База * MEN + бонусы предметов) -> Пассивки -> Баффы
     */
    public static function getMaxMp(Character $character): int
    {
        $baseMp = PcParamsRepository::getBaseMp($character->class_id, $character->level);
        $classInfo = PcParamsRepository::getClass($character->class_id);
        $men = (int)($classInfo['men'] ?? 25);

        $menModifier = PcParamsRepository::getStatModifier('men', $men);
        $baseWithMen = $baseMp * $menModifier;

        $gearMpBonus = 0;
        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item) {
                $gearMpBonus += (int)($item->mpBonus ?? $item->mp_bonus ?? 0);
            }
        }

        $totalMp = $baseWithMen + $gearMpBonus;

        // 1. Пассивные умения (Boost Mana и др.)
        $withPassives = self::applyPassives((float)$totalMp, 'p_max_mp', $character);

        // 2. Временные баффы (Bless the Soul и др.)
        $finalMp = self::applyModifiers($withPassives, 'p_max_mp', $character);

        return max(1, (int)round($finalMp));
    }

    /**
     * Итоговый Max CP: Расчет от итогового Max HP по архетипам C4 -> Пассивки -> Баффы
     */
    public static function getMaxCp(Character $character): int
    {
        $classInfo = PcParamsRepository::getClass($character->class_id);
        $maxHp = self::getMaxHp($character);
        $con = (int)($classInfo['con'] ?? 40);
        $int = (int)($classInfo['int'] ?? 20);

        if ($int >= 35) {
            $baseCp = (int)round($maxHp * 0.40);
        } elseif ($con >= 43) {
            $baseCp = (int)round($maxHp * 0.65);
        } else {
            $baseCp = (int)round($maxHp * 0.50);
        }

        $withPassives = self::applyPassives((float)$baseCp, 'p_max_cp', $character);
        $finalCp = self::applyModifiers($withPassives, 'p_max_cp', $character);

        return max(1, (int)round($finalCp));
    }

    /**
     * Итоговая физ. атака (P.Atk): (База + Оружие) * STR * Level -> Пассивки -> Баффы
     */
    public static function getPAtk(Character $character): int
    {
        $template = PcParamsRepository::getTemplateForClass($character->class_id, $character->gender);
        $basePAtk = (float)($template['base_physical_attack'] ?? 4.0);

        $weaponAtk = 0;
        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item && $item->isWeapon()) {
                $weaponAtk = $item->pAtk;
                if ($invItem->enchant > 0) {
                    $weaponAtk += ($invItem->enchant * 2);
                }
                break;
            }
        }

        $classInfo = PcParamsRepository::getClass($character->class_id);
        $str = (int)($classInfo['str'] ?? 40);

        $strModifier = PcParamsRepository::getStatModifier('str', $str);
        $lvlModifier = PcParamsRepository::getLevelModifier($character->level);

        $rawPAtk = ($basePAtk + $weaponAtk) * $strModifier * $lvlModifier;

        // 1. Пассивные умения (Weapon Mastery: sword, blunt, dagger, bow...)
        $withPassives = self::applyPassives((float)$rawPAtk, 'p_physical_attack', $character);

        // 2. Временные баффы (Might, Berserker Spirit...)
        $finalPAtk = self::applyModifiers($withPassives, 'p_physical_attack', $character);

        return max(1, (int)round($finalPAtk));
    }

    /**
     * Итоговая физ. защита (P.Def): (Слоты брони) * Level -> Пассивки -> Баффы
     */
    public static function getPDef(Character $character): int
    {
        $template = PcParamsRepository::getTemplateForClass($character->class_id, $character->gender);
        $nakedDef = $template['base_defend'] ?? [31, 18, 12, 7, 8, 3, 1];

        $slotPDef = [
            'chest'     => (int)($nakedDef[0] ?? 31),
            'legs'      => (int)($nakedDef[1] ?? 18),
            'head'      => (int)($nakedDef[2] ?? 12),
            'feet'      => (int)($nakedDef[3] ?? 7),
            'gloves'    => (int)($nakedDef[4] ?? 8),
            'underwear' => (int)($nakedDef[5] ?? 3),
            'cloak'     => (int)($nakedDef[6] ?? 1),
        ];

        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if (!$item || !$item->isArmor()) {
                continue;
            }

            $enchantBonus = max(0, (int)$invItem->enchant);

            if ($invItem->equip_slot === 'onepiece') {
                $slotPDef['chest'] = $item->pDef + $enchantBonus;
                $slotPDef['legs'] = 0;
            } elseif (isset($slotPDef[$invItem->equip_slot])) {
                $slotPDef[$invItem->equip_slot] = $item->pDef + $enchantBonus;
            }
        }

        $lvlModifier = PcParamsRepository::getLevelModifier($character->level);
        $rawPDef = array_sum($slotPDef) * $lvlModifier;

        // 1. Пассивные умения (Heavy/Light/Robe Armor Mastery)
        $withPassives = self::applyPassives((float)$rawPDef, 'p_physical_defence', $character);

        // 2. Временные баффы (Shield, Ultimate Defence...)
        $finalPDef = self::applyModifiers($withPassives, 'p_physical_defence', $character);

        return max(1, (int)round($finalPDef));
    }

    /**
     * Итоговая маг. атака (M.Atk): (База + Оружие) * INT^2 * Level^2 -> Пассивки -> Баффы
     */
    public static function getMAtk(Character $character): int
    {
        $template = PcParamsRepository::getTemplateForClass($character->class_id, $character->gender);
        $baseMAtk = (float)($template['base_magic_attack'] ?? 6.0);

        $weaponMAtk = 0;
        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item && $item->isWeapon()) {
                $weaponMAtk = $item->mAtk;
                if ($invItem->enchant > 0) {
                    $enchantStep = $item->isTwoHanded() ? 4 : 3;
                    $weaponMAtk += ($invItem->enchant * $enchantStep);
                }
                break;
            }
        }

        $classInfo = PcParamsRepository::getClass($character->class_id);
        $int = (int)($classInfo['int'] ?? 20);

        $intModifier = PcParamsRepository::getStatModifier('int', $int);
        $lvlModifier = PcParamsRepository::getLevelModifier($character->level);

        $rawMAtk = ($baseMAtk + $weaponMAtk) * ($lvlModifier ** 2) * ($intModifier ** 2);

        $withPassives = self::applyPassives((float)$rawMAtk, 'p_magic_attack', $character);
        $finalMAtk = self::applyModifiers($withPassives, 'p_magic_attack', $character);

        return max(1, (int)round($finalMAtk));
    }

    /**
     * Итоговая маг. защита (M.Def): Бижутерия * MEN * Level -> Пассивки -> Баффы
     */
    public static function getMDef(Character $character): int
    {
        $template = PcParamsRepository::getTemplateForClass($character->class_id, $character->gender);
        $nakedMDef = $template['base_magic_defend'] ?? [9, 9, 5, 5, 13];

        $slotMDef = [
            'rear_1'    => (int)($nakedMDef[0] ?? 9),
            'rear_2'    => (int)($nakedMDef[1] ?? 9),
            'rfinger_1' => (int)($nakedMDef[2] ?? 5),
            'rfinger_2' => (int)($nakedMDef[3] ?? 5),
            'neck'      => (int)($nakedMDef[4] ?? 13),
        ];

        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if (!$item || $item->type !== 'accessary') {
                continue;
            }

            $enchantBonus = max(0, (int)$invItem->enchant);
            if (isset($slotMDef[$invItem->equip_slot])) {
                $slotMDef[$invItem->equip_slot] = $item->mDef + $enchantBonus;
            }
        }

        $classInfo = PcParamsRepository::getClass($character->class_id);
        $men = (int)($classInfo['men'] ?? 25);

        $menModifier = PcParamsRepository::getStatModifier('men', $men);
        $lvlModifier = PcParamsRepository::getLevelModifier($character->level);

        $rawMDef = array_sum($slotMDef) * $menModifier * $lvlModifier;

        // 1. Пассивные умения (Magic Resistance и др.)
        $withPassives = self::applyPassives((float)$rawMDef, 'p_magic_defence', $character);

        // 2. Временные баффы (Magic Barrier и др.)
        $finalMDef = self::applyModifiers($withPassives, 'p_magic_defence', $character);

        return max(1, (int)round($finalMDef));
    }

    /**
     * Скорость физ. атаки (Attack Speed): Оружие * DEX -> Пассивки -> Баффы
     */
    public static function getAttackSpeed(Character $character): int
    {
        $baseSpd = 300;

        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item && $item->isWeapon()) {
                $baseSpd = $item->attackSpeed;
                break;
            }
        }

        $classInfo = PcParamsRepository::getClass($character->class_id);
        $dex = (int)($classInfo['dex'] ?? 30);
        $dexModifier = PcParamsRepository::getStatModifier('dex', $dex);

        $rawSpd = $baseSpd * $dexModifier;

        // Пассивки (Quick Step / Dual Dagger Mastery)
        $withPassives = self::applyPassives((float)$rawSpd, 'p_attack_speed', $character);

        // Баффы (Haste)
        $finalSpd = self::applyModifiers($withPassives, 'p_attack_speed', $character);

        return max(10, (int)round($finalSpd));
    }

    /**
     * Скорость каста магии (Cast Speed): 333 * WIT -> Пассивки -> Баффы
     */
    public static function getCastSpeed(Character $character): int
    {
        $classInfo = PcParamsRepository::getClass($character->class_id);
        $wit = (int)($classInfo['wit'] ?? 20);

        $witModifier = PcParamsRepository::getStatModifier('wit', $wit);
        $rawCastSpeed = 333 * $witModifier;

        // Пассивки (Fast Spell Casting)
        $withPassives = self::applyPassives((float)$rawCastSpeed, 'p_magic_speed', $character);

        // Баффы (Acumen)
        $finalCast = self::applyModifiers($withPassives, 'p_magic_speed', $character);

        return max(10, (int)round($finalCast));
    }

    /**
     * Регенерация HP в секунду
     */
    public static function getHpRegenPerSecond(Character $character, string $mode = 'stand'): float
    {
        $baseRegen = PcParamsRepository::getBaseHpRegen($character->class_id, $character->level, $character->gender);
        $classInfo = PcParamsRepository::getClass($character->class_id);
        $con = (int)($classInfo['con'] ?? 40);

        $conMod = PcParamsRepository::getStatModifier('con', $con);
        $modeMod = PcParamsRepository::getRegenMoveModeBonus($mode);

        $rawRegen = ($baseRegen * $conMod * $modeMod) / 3.0;

        $withPassives = self::applyPassives($rawRegen, 'p_hp_regen', $character);
        return (float)self::applyModifiers($withPassives, 'p_hp_regen', $character);
    }

    /**
     * Регенерация MP в секунду
     */
    public static function getMpRegenPerSecond(Character $character, string $mode = 'stand'): float
    {
        $baseRegen = PcParamsRepository::getBaseMpRegen($character->class_id, $character->level, $character->gender);
        $classInfo = PcParamsRepository::getClass($character->class_id);
        $men = (int)($classInfo['men'] ?? 25);

        $menMod = PcParamsRepository::getStatModifier('men', $men);
        $modeMod = PcParamsRepository::getRegenMoveModeBonus($mode);

        $rawRegen = ($baseRegen * $menMod * $modeMod) / 3.0;

        $withPassives = self::applyPassives($rawRegen, 'p_mp_regen', $character);
        return (float)self::applyModifiers($withPassives, 'p_mp_regen', $character);
    }

    public static function getCpRegenPerSecond(Character $character, string $mode = 'stand'): float
    {
        return self::getHpRegenPerSecond($character, $mode) * 0.5;
    }

    // =========================================================================
    // СПЕЦИФИЧЕСКИЕ ЭФФЕКТЫ (ВЕС, СЛОТЫ, ЦЕЛИ ПИКИ, СКОРОСТЬ БЕГА, ЩИТЫ)
    // =========================================================================

    /**
     * 1. Максимальный переносимый вес (Weight Limit, ID 150)
     */
    public static function getMaxWeight(Character $character): int
    {
        $conMod = PcParamsRepository::getStatModifier('con', $character->getCon());
        $baseWeight = 69000.0 * $conMod;

        // Пассивки (Weight Limit)
        $withPassives = self::applyPassives($baseWeight, 'p_weight_limit', $character);

        // Баффы
        $finalWeight = self::applyModifiers($withPassives, 'p_weight_limit', $character);

        return max(1000, (int)round($finalWeight));
    }

    /**
     * 2. Лимит слотов инвентаря (Expand Inventory)
     */
    public static function getMaxInventorySlots(Character $character): int
    {
        // Базовый лимит: гномы 100, остальные 80
        $baseSlots = ((int)$character->race === 4) ? 100.0 : 80.0;

        // Пассивки (Expand Inventory)
        $finalSlots = self::applyPassives($baseSlots, 'p_expand_inventory', $character);

        return max(20, (int)round($finalSlots));
    }

    /**
     * 3. Максимальное число целей автоатаки (Wide Sweep / Polearm Mastery)
     */
    public static function getMaxAttackTargets(Character $character): int
    {
        $weapon = null;
        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item && $item->isWeapon()) {
                $weapon = $item;
                break;
            }
        }

        if (!$weapon || $weapon->weaponType !== 'pole') {
            return 1;
        }

        // Базовый сплеш пики в C4: 3 цели
        $maxTargets = 3.0;

        // Пассивки (Wide Sweep, ID 305 и др.)
        $finalTargets = self::applyPassives($maxTargets, 'p_attack_targets', $character);

        return max(1, (int)round($finalTargets));
    }

    /**
     * 4. Скорость перемещения (Run Speed)
     */
    public static function getRunSpeed(Character $character): int
    {
        $template = PcParamsRepository::getTemplateForClass($character->class_id, $character->gender);
        $baseSpeed = (float)($template['base_speed'] ?? 126.0);

        $withPassives = self::applyPassives($baseSpeed, 'p_speed', $character);
        $finalSpeed = self::applyModifiers($withPassives, 'p_speed', $character);

        return max(20, (int)round($finalSpeed));
    }

    // =========================================================================
    // СИСТЕМНЫЕ МЕТОДЫ МОДИФИКАТОРОВ И ПРОВЕРОК
    // =========================================================================

    /**
     * Единое применение пассивных умений к характеристике
     */
    private static function applyPassives(float $baseValue, string $statName, Character $character): float
    {
        $val = $baseValue;
        $characterSkills = $character->characterSkills;

        foreach ($characterSkills as $charSkill) {
            $skill = $charSkill->getSkill();
            if (!$skill || !$skill->isPassive() || empty($skill->effects['passives'])) {
                continue;
            }

            foreach ($skill->effects['passives'] as $passive) {
                if (!self::isStatMatching((string)$passive['stat'], $statName)) {
                    continue;
                }

                // Проверка экипировки под условие пассивки
                if (!self::checkPassiveCondition($passive['condition'] ?? null, $character)) {
                    continue;
                }

                if ($passive['mode'] === 'per') {
                    $val *= (1.0 + ((float)$passive['val'] / 100.0));
                } elseif ($passive['mode'] === 'diff') {
                    $val += (float)$passive['val'];
                }
            }
        }

        return $val;
    }

    /**
     * Единое применение временных эффектов из кэша к характеристике
     */
    private static function applyModifiers(float $baseValue, string $statName, Character $character): float
    {
        $effects = CharacterEffectService::getEffects($character);
        $val = $baseValue;

        foreach ($effects as $eff) {
            if ($eff->isStat() && self::isStatMatching((string)$eff->stat, $statName)) {
                if ($eff->mode === 'per') {
                    $val *= (1.0 + ($eff->value / 100.0));
                } elseif ($eff->mode === 'diff') {
                    $val += $eff->value;
                }
            }
        }

        return $val;
    }

    /**
     * Нормализация и сопоставление алиасов статов PTS
     */
    private static function isStatMatching(string $effectStat, string $targetStat): bool
    {
        if ($effectStat === $targetStat) {
            return true;
        }

        $cleanA = str_starts_with($effectStat, 'p_') ? substr($effectStat, 2) : $effectStat;
        $cleanB = str_starts_with($targetStat, 'p_') ? substr($targetStat, 2) : $targetStat;

        if ($cleanA === $cleanB) {
            return true;
        }

        $aliases = [
            'hp'              => ['max_hp', 'p_max_hp'],
            'mp'              => ['max_mp', 'p_max_mp'],
            'cp'              => ['max_cp', 'p_max_cp'],
            'p_atk'           => ['p_physical_attack', 'physical_attack', 'p_atk'],
            'p_def'           => ['p_physical_defence', 'physical_defence', 'p_def'],
            'm_atk'           => ['p_magic_attack', 'magic_attack', 'm_atk'],
            'm_def'           => ['p_magic_defence', 'magic_defence', 'm_def'],
            'atk_spd'         => ['p_attack_speed', 'attack_speed'],
            'cast_spd'        => ['p_magic_speed', 'magic_speed', 'cast_speed'],
            'hp_regen'        => ['p_hp_regen', 'hp_regen'],
            'mp_regen'        => ['p_mp_regen', 'mp_regen'],
            'weight'          => ['p_weight_limit', 'weight_limit'],
            'slots'           => ['p_expand_inventory', 'expand_inventory', 'p_inventory_slots'],
            'targets'         => ['p_attack_targets', 'attack_targets', 'wide_sweep'],
            'speed'           => ['p_speed', 'speed', 'run_speed'],
        ];

        foreach ($aliases as $group) {
            if (in_array($effectStat, $group, true) && in_array($targetStat, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Валидация условий применения пассивного эффекта
     */
    private static function checkPassiveCondition(?string $condition, Character $character): bool
    {
        if ($condition === null || $condition === '' || $condition === 'all') {
            return true;
        }

        // 1. Оружие
        $weaponTypes = ['sword', 'blunt', 'dagger', 'bow', 'pole', 'fist', 'dualfist', 'dual'];
        if (in_array($condition, $weaponTypes, true)) {
            $weapon = null;
            foreach ($character->equippedItems as $invItem) {
                $item = $invItem->getItem();
                if ($item && $item->isWeapon()) {
                    $weapon = $item;
                    break;
                }
            }

            if (!$weapon) {
                return false;
            }

            if ($condition === 'sword' && in_array($weapon->weaponType, ['sword', 'blunt'], true)) {
                return true;
            }

            return $weapon->weaponType === $condition;
        }

        // 2. Броня
        $armorTypes = ['heavy', 'light', 'magic', 'robe'];
        if (in_array($condition, $armorTypes, true)) {
            $chest = null;
            foreach ($character->equippedItems as $invItem) {
                if ($invItem->equip_slot === 'chest' || $invItem->equip_slot === 'onepiece') {
                    $chest = $invItem->getItem();
                    break;
                }
            }

            if (!$chest || !$chest->isArmor()) {
                return false;
            }

            $type = $chest->armorType;
            if ($condition === 'robe' && $type === 'magic') {
                return true;
            }

            return $type === $condition;
        }

        // 3. Щит
        if (in_array($condition, ['shield', 'equip_shield'], true)) {
            foreach ($character->equippedItems as $invItem) {
                if ($invItem->equip_slot === 'lhand') {
                    $item = $invItem->getItem();
                    return $item && ($item->isShield() || $item->armorType === 'shield');
                }
            }
            return false;
        }

        return true;
    }
}
