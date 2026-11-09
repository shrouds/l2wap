<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\VarDumper;

class PtsController extends Controller
{
    /**
     * Парсинг умений PTS и сопоставление с иконками:
     * php yii pts/skills
     */
    public function actionSkills(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/skilldata.txt');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/skills.php';
        $iconsDir = Yii::getAlias('@app/web/images/icons');

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // 1. Индексация доступных иконок на диске
        $availableIcons = [];
        if (is_dir($iconsDir)) {
            $this->stdout("Сканирование папки иконок web/images/icons...\n");
            $files = scandir($iconsDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $baseName = strtolower(pathinfo($file, PATHINFO_FILENAME));
                $availableIcons[$baseName] = $file;
                $availableIcons[strtolower($file)] = $file;
            }
            $this->stdout("Найдено файлов иконок на диске: " . count($availableIcons) . "\n");
        }

        // 2. Сбор названий иконок из клиентского реестра skillgrp.txt
        $clientGrpPath = Yii::getAlias('@app/data/pts/skillgrp.txt');
        $clientIcons = [];

        if (file_exists($clientGrpPath)) {
            $this->stdout("Чтение клиентского реестра: skillgrp.txt...\n");
            $grpContent = file_get_contents($clientGrpPath);

            if (str_starts_with($grpContent, "\xFF\xFE")) {
                $grpContent = mb_convert_encoding($grpContent, 'UTF-8', 'UTF-16LE');
            } elseif (!mb_check_encoding($grpContent, 'UTF-8')) {
                $grpContent = mb_convert_encoding($grpContent, 'UTF-8', 'Windows-1251');
            }

            $grpContent = preg_replace('/^\xEF\xBB\xBF/', '', $grpContent);
            $grpLines = preg_split('/\r\n|\r|\n/', $grpContent);
            unset($grpContent);

            foreach ($grpLines as $gLine) {
                $gLine = trim($gLine);
                if ($gLine === '' || str_starts_with($gLine, '//')) {
                    continue;
                }

                $sId = null;
                if (preg_match('/skill_id\s*=\s*(\d+)/', $gLine, $mId)) {
                    $sId = (int)$mId[1];
                } elseif (preg_match('/^(\d+)\s+/', $gLine, $mId)) {
                    $sId = (int)$mId[1];
                }

                if ($sId !== null && preg_match('/(?:icon\.)?(skill[a-zA-Z0-9_]+)/i', $gLine, $mIcon)) {
                    $clientIcons[$sId] = $mIcon[1];
                }
            }
        }

        // 3. Загрузка и декодирование всего skilldata.txt
        $this->stdout("Чтение серверного skilldata.txt...\n");
        $content = file_get_contents($inputPath);

        if (str_starts_with($content, "\xFF\xFE")) {
            $this->stdout("Обнаружена кодировка UTF-16 LE. Конвертируем в UTF-8...\n");
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $skills = [];
        $totalParsed = 0;
        $matchedIconsCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_contains($line, 'skill_begin')) {
                continue;
            }

            // Извлечение ID и уровня
            if (!preg_match('/skill_id\s*=\s*(\d+)/', $line, $mId) ||
                !preg_match('/level\s*=\s*(\d+)/', $line, $mLvl)) {
                continue;
            }

            $id = (int)$mId[1];
            $level = (int)$mLvl[1];

            // Отсекаем заточки скиллов (> 100)
            if ($level > 100) {
                continue;
            }

            // Системное имя
            preg_match('/skill_name\s*=\s*\[([^\]]+)\]/', $line, $mName);
            $sysName = $mName[1] ?? "skill_{$id}_{$level}";

            // MP / HP
            preg_match('/mp_consume1\s*=\s*(\d+)/', $line, $mMp1);
            preg_match('/mp_consume2\s*=\s*(\d+)/', $line, $mMp2);
            preg_match('/hp_consume\s*=\s*(\d+)/', $line, $mHp);
            $mpConsume = (int)($mMp1[1] ?? 0) + (int)($mMp2[1] ?? 0);
            $hpConsume = (int)($mHp[1] ?? 0);

            // Тайминги
            preg_match('/skill_hit_time\s*=\s*([\d\.]+)/', $line, $mCast);
            preg_match('/reuse_delay\s*=\s*([\d\.]+)/', $line, $mReuse);

            // Параметры применения
            preg_match('/target_type\s*=\s*([a-zA-Z0-9_]+)/', $line, $mTarget);
            preg_match('/cast_range\s*=\s*(-?\d+)/', $line, $mRange);
            preg_match('/is_magic\s*=\s*(\d+)/', $line, $mMagic);
            $isMagicFlag = (int)($mMagic[1] ?? 0);

            // Разбор эффектов
            [$type, $power, $effects] = $this->parseEffects($line);

            // Разрешение иконки
            $rawClientIcon = $clientIcons[$id] ?? '';
            $cleanClientIcon = preg_replace('/^icon\./i', '', $rawClientIcon);
            $defaultL2Icon = sprintf('skill%04d', $id);

            $resolvedIcon = null;
            if ($cleanClientIcon !== '' && isset($availableIcons[strtolower($cleanClientIcon)])) {
                $resolvedIcon = $availableIcons[strtolower($cleanClientIcon)];
            } elseif (isset($availableIcons[$defaultL2Icon])) {
                $resolvedIcon = $availableIcons[$defaultL2Icon];
            } elseif (isset($availableIcons[strtolower($sysName)])) {
                $resolvedIcon = $availableIcons[strtolower($sysName)];
            } elseif (isset($availableIcons[(string)$id])) {
                $resolvedIcon = $availableIcons[(string)$id];
            } else {
                $resolvedIcon = $cleanClientIcon !== '' ? $cleanClientIcon . '.png' : $defaultL2Icon . '.png';
            }

            if (isset($availableIcons[strtolower(pathinfo($resolvedIcon, PATHINFO_FILENAME))])) {
                $matchedIconsCount++;
            }

            $skills[$id][$level] = [
                'name'    => $sysName,
                'type'    => $type,
                'target'  => $mTarget[1] ?? 'enemy',
                'range'   => (int)($mRange[1] ?? 40),
                'magic'   => ($isMagicFlag === 1),
                'mp'      => $mpConsume,
                'hp'      => $hpConsume,
                'power'   => $power,
                'cast'    => (float)($mCast[1] ?? 0.0),
                'reuse'   => (int)($mReuse[1] ?? 0),
                'effects' => $effects,
                'icon'    => $resolvedIcon,
            ];

            $totalParsed++;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Генерация файла: {$outputFile}...\n");
        $exportedArray = VarDumper::export($skills);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\nreturn {$exportedArray};\n";
        file_put_contents($outputFile, $fileContent);

        $this->stdout("Успешно! Обработано уровней скиллов: {$totalParsed} (найдено иконок: {$matchedIconsCount})\n");
        return ExitCode::OK;
    }

    private function parseEffects(string $line): array
    {
        // 1. Извлечение полного блока effect = { ... } с любой вложенностью скобок ({all}, {sword})
        $raw = '';
        if (preg_match('/effect\s*=\s*(\{(?:[^{}]+|(?1))*\})/', $line, $mEffect)) {
            $raw = $mEffect[1];
        }

        // 2. Служебные параметры механики PTS
        preg_match('/operate_type\s*=\s*([a-zA-Z0-9_]+)/', $line, $mOp);
        $operateType = strtoupper($mOp[1] ?? 'A1');

        preg_match('/abnormal_type\s*=\s*([a-zA-Z0-9_]+)/', $line, $mAbType);
        $abnormalType = strtolower($mAbType[1] ?? '');

        preg_match('/abnormal_time\s*=\s*(\d+)/', $line, $mAbTime);
        $abnormalTime = (int)($mAbTime[1] ?? 0);

        preg_match('/activate_rate\s*=\s*(-?\d+)/', $line, $mRate);
        $activateRate = isset($mRate[1]) ? (int)$mRate[1] : 80;
        if ($activateRate === -1) {
            $activateRate = 80; // Базовый шанс PTS при -1
        }

        $type = 'EFFECT';
        $power = 0.0;
        $effects = [];

        // 3. Пассивные умения (P) — модификаторы статов и привязка к оружию/броне
        if ($operateType === 'P') {
            $type = 'PASSIVE';
            if (preg_match_all('/(p_[a-zA-Z0-9_]+);\s*\{([^}]*)\};\s*([0-9\.\-]+);\s*(per|diff)/', $raw, $mPassives, PREG_SET_ORDER)) {
                foreach ($mPassives as $p) {
                    $effects['passives'][] = [
                        'stat'      => $p[1],
                        'condition' => ($p[2] !== '' && $p[2] !== 'all') ? $p[2] : null,
                        'val'       => (float)$p[3],
                        'mode'      => $p[4],
                    ];
                }
            }
            return [$type, 0.0, $effects];
        }

        // 4. Определение урона и исцеления
        // Физические умения (включая блоу, зарядки и бэкстаб)
        if (preg_match('/(?:i_p_attack(?:_over_hit)?|i_fatal_blow|i_energy_attack|i_backstab|i_death_blow);\s*(\d+(?:\.\d+)?)/', $raw, $res)) {
            $type = 'PHYS_DMG';
            $power = (float)$res[1];
        }
        // Лечение
        elseif (preg_match('/i_heal(?:_link)?;\s*(\d+(?:\.\d+)?)/', $raw, $res)) {
            $type = 'HEAL';
            $power = (float)$res[1];
        }
        // Вампиризм (Drain HP)
        elseif (preg_match('/i_hp_drain;\s*(\d+(?:\.\d+)?);(\d+)/', $raw, $res)) {
            $type = 'MAGIC_DMG';
            $power = (float)$res[1];
            $effects['drain_percent'] = (int)$res[2];
        }
        // Магический урон
        elseif (preg_match('/(?:i_m_attack|i_holy_attack|i_soul_attack);\s*(\d+(?:\.\d+)?)/', $raw, $res)) {
            $type = 'MAGIC_DMG';
            $power = (float)$res[1];
        }

        // 5. Контроль и дебаффы (стан, сон, паралич, рут, сайленс)
        if ($abnormalType === 'stun' || str_contains($raw, 'p_block_act')) {
            $debuffName = 'stun';
            if ($abnormalType === 'sleep') {
                $debuffName = 'sleep';
            } elseif ($abnormalType === 'paralyze') {
                $debuffName = 'paralyze';
            }
            $effects['debuff'] = $debuffName;
            $effects['chance'] = $activateRate;
            $effects['duration'] = $abnormalTime ?: 9;
        } elseif ($abnormalType === 'root' || $abnormalType === 'hold' || str_contains($raw, 'p_block_move')) {
            $effects['debuff'] = 'root';
            $effects['chance'] = $activateRate;
            $effects['duration'] = $abnormalTime ?: 15;
        } elseif ($abnormalType === 'silence' || str_contains($raw, 'p_block_spell')) {
            $effects['debuff'] = 'silence';
            $effects['chance'] = $activateRate;
            $effects['duration'] = $abnormalTime ?: 15;
        }

        // 6. DoT (Периодический урон: Яд и Кровотечение)
        if ($abnormalType === 'poison' || (str_contains($raw, 't_hp') && str_contains($line, 'poison'))) {
            $effects['dot'] = 'poison';
            $effects['chance'] = $activateRate;
            $effects['duration'] = $abnormalTime ?: 30;
            if (preg_match('/t_hp;\s*(-?\d+(?:\.\d+)?)/', $raw, $mTick)) {
                $effects['tick_dmg'] = abs((float)$mTick[1]);
            }
        } elseif ($abnormalType === 'bleeding' || (str_contains($raw, 't_hp') && str_contains($line, 'bleed'))) {
            $effects['dot'] = 'bleed';
            $effects['chance'] = $activateRate;
            $effects['duration'] = $abnormalTime ?: 20;
            if (preg_match('/t_hp;\s*(-?\d+(?:\.\d+)?)/', $raw, $mTick)) {
                $effects['tick_dmg'] = abs((float)$mTick[1]);
            }
        }

        // 7. Баффы и дебаффы параметров (Might, Shield, Haste, Focus, Hex, Slow...)
        if (preg_match_all('/(p_[a-zA-Z0-9_]+);\s*\{([^}]*)\};\s*(-?[0-9\.\-]+);\s*(per|diff)/', $raw, $mStats, PREG_SET_ORDER)) {
            foreach ($mStats as $s) {
                if (in_array($s[1], ['p_block_act', 'p_block_move', 'p_block_spell'], true)) {
                    continue;
                }
                $effects['stats'][] = [
                    'stat'     => $s[1],
                    'val'      => (float)$s[3],
                    'mode'     => $s[4],
                    'duration' => $abnormalTime ?: 1200,
                ];
            }
        }

        // 8. Вампирик от баффов (Vampiric Rage)
        if (preg_match('/p_vampiric_attack;\s*(\d+)/', $raw, $mVamp)) {
            $effects['stats'][] = [
                'stat'     => 'vampiric',
                'val'      => (int)$mVamp[1],
                'mode'     => 'per',
                'duration' => $abnormalTime ?: 1200,
            ];
        }

        return [$type, $power, $effects];
    }

    /**
     * Парсинг предметов:
     * php yii pts/items
     */
    /*public function actionItems(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/itemdata.txt');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/items.php';

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Чтение файла itemdata.txt...\n");

        $content = file_get_contents($inputPath);

        // Обработка UTF-16LE / UTF-8
        if (str_starts_with($content, "\xFF\xFE")) {
            $this->stdout("Обнаружена кодировка UTF-16 LE. Конвертируем в UTF-8...\n");
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $items = [];
        $totalParsed = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_contains($line, 'item_begin')) {
                continue;
            }

            // Извлекаем тип, ID и системное имя
            // Пример: item_begin weapon 1 [small_sword] ...
            if (!preg_match('/item_begin\s+([a-zA-Z0-9_]+)\s+(\d+)\s+\[([^\]]+)\]/', $line, $head)) {
                continue;
            }

            $type = $head[1];
            $id = (int)$head[2];
            $sysName = $head[3];

            // Слот экипировки slot_bit_type={rhand}
            preg_match('/slot_bit_type=\{([^\}]*)\}/', $line, $mSlot);
            $rawSlot = $mSlot[1] ?? 'none';
            // Если указано несколько слотов (например, {rear;lear} или {rfinger;lfinger}), берем первый
            $slot = explode(';', $rawSlot)[0];

            // Базовые параметры
            preg_match('/weapon_type=([a-zA-Z0-9_]+)/', $line, $mWpnType);
            preg_match('/armor_type=([a-zA-Z0-9_]+)/', $line, $mArmType);
            preg_match('/crystal_type=([a-zA-Z0-9_]+)/', $line, $mGrade);
            preg_match('/weight=(\d+)/', $line, $mWeight);
            preg_match('/default_price=(\d+)/', $line, $mPrice);
            preg_match('/consume_type=([a-zA-Z0-9_]+)/', $line, $mConsume);

            // Боевые статы
            preg_match('/physical_damage=(\d+)/', $line, $mPAtk);
            preg_match('/magical_damage=(\d+)/', $line, $mMAtk);
            preg_match('/physical_defense=(\d+)/', $line, $mPDef);
            preg_match('/magical_defense=(\d+)/', $line, $mMDef);
            preg_match('/shield_defense=(\d+)/', $line, $mShDef);
            preg_match('/shield_defense_rate=(\d+)/', $line, $mShRate);
            preg_match('/critical=(\d+)/', $line, $mCrit);
            preg_match('/attack_speed=(\d+)/', $line, $mSpd);
            preg_match('/soulshot_count=(\d+)/', $line, $mSs);
            preg_match('/spiritshot_count=(\d+)/', $line, $mSps);

            $consumeType = $mConsume[1] ?? 'consume_type_normal';
            $isStackable = in_array($consumeType, ['consume_type_stackable', 'consume_type_asset'], true);

            $items[$id] = [
                'name'        => $sysName,
                'type'        => $type,
                'slot'        => $slot ?: 'none',
                'weapon_type' => $mWpnType[1] ?? 'none',
                'armor_type'  => $mArmType[1] ?? 'none',
                'grade'       => $mGrade[1] ?? 'none',
                'p_atk'       => (int)($mPAtk[1] ?? 0),
                'm_atk'       => (int)($mMAtk[1] ?? 0),
                'p_def'       => (int)($mPDef[1] ?? 0),
                'm_def'       => (int)($mMDef[1] ?? 0),
                'shield_def'  => (int)($mShDef[1] ?? 0),
                'shield_rate' => (int)($mShRate[1] ?? 0),
                'critical'    => (int)($mCrit[1] ?? 0),
                'spd'         => (int)($mSpd[1] ?? 0),
                'weight'      => (int)($mWeight[1] ?? 0),
                'price'       => (int)($mPrice[1] ?? 0),
                'stackable'   => $isStackable,
                'ss_count'    => (int)($mSs[1] ?? 0),
                'sps_count'   => (int)($mSps[1] ?? 0),
            ];

            $totalParsed++;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Генерация файла {$outputFile}...\n");
        $exportedArray = VarDumper::export($items);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\nreturn {$exportedArray};\n";
        file_put_contents($outputFile, $fileContent);

        $this->stdout("Готово! Успешно обработано предметов: {$totalParsed}\n");
        return ExitCode::OK;
    }*/

    /**
     * Парсинг предметов и сопоставление с иконками:
     * php yii pts/items
     */
    public function actionItems(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/itemdata.txt');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/items.php';
        $iconsDir = Yii::getAlias('@app/web/images/icons');

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // 1. Индексация физических файлов в web/images/icons
        $availableIcons = [];
        if (is_dir($iconsDir)) {
            $this->stdout("Сканирование папки иконок web/images/icons...\n");
            $files = scandir($iconsDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $baseName = strtolower(pathinfo($file, PATHINFO_FILENAME));
                $availableIcons[$baseName] = $file;
                $availableIcons[strtolower($file)] = $file;
            }
            $this->stdout("Найдено файлов иконок на диске: " . count($availableIcons) . "\n");
        } else {
            $this->stdout("Предупреждение: папка {$iconsDir} не существует.\n");
        }

        // 2. Сбор названий иконок из клиентских реестров (etcitemgrp, weapongrp, armorgrp)
        $clientGrpFiles = [
            Yii::getAlias('@app/data/pts/etcitemgrp.txt'),
            Yii::getAlias('@app/data/pts/weapongrp.txt'),
            Yii::getAlias('@app/data/pts/armorgrp.txt'),
        ];

        $clientIcons = [];
        foreach ($clientGrpFiles as $grpPath) {
            if (!file_exists($grpPath)) {
                continue;
            }

            $this->stdout("Чтение клиентского реестра: " . basename($grpPath) . "...\n");
            $grpContent = file_get_contents($grpPath);

            if (str_starts_with($grpContent, "\xFF\xFE")) {
                $grpContent = mb_convert_encoding($grpContent, 'UTF-8', 'UTF-16LE');
            } elseif (!mb_check_encoding($grpContent, 'UTF-8')) {
                $grpContent = mb_convert_encoding($grpContent, 'UTF-8', 'Windows-1251');
            }

            $grpContent = preg_replace('/^\xEF\xBB\xBF/', '', $grpContent);
            $lines = preg_split('/\r\n|\r|\n/', $grpContent);
            unset($grpContent);

            $idIdx = 1;
            $iconIdx = 13;

            foreach ($lines as $i => $line) {
                $line = trim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                $cols = explode("\t", $line);

                // Определение колонок по заголовку
                if ($i === 0 && str_contains($line, 'id')) {
                    $foundId = array_search('id', $cols, true);
                    $foundIcon = array_search('icon[0]', $cols, true);
                    if ($foundId !== false) $idIdx = $foundId;
                    if ($foundIcon !== false) $iconIdx = $foundIcon;
                    continue;
                }

                if (isset($cols[$idIdx], $cols[$iconIdx]) && is_numeric($cols[$idIdx])) {
                    $itemId = (int)$cols[$idIdx];
                    $iconStr = trim($cols[$iconIdx]);
                    if ($itemId > 0 && $iconStr !== '') {
                        $clientIcons[$itemId] = $iconStr;
                    }
                }
            }
        }

        // 3. Чтение серверного itemdata.txt
        $this->stdout("Чтение серверного itemdata.txt...\n");
        $content = file_get_contents($inputPath);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $items = [];
        $totalParsed = 0;
        $matchedIconsCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_contains($line, 'item_begin')) {
                continue;
            }

            if (!preg_match('/item_begin\s+([a-zA-Z0-9_]+)\s+(\d+)\s+\[([^\]]+)\]/', $line, $head)) {
                continue;
            }

            $type = $head[1];
            $id = (int)$head[2];
            $sysName = strtolower(trim($head[3]));

            // Слот экипировки
            preg_match('/slot_bit_type=\{([^\}]*)\}/', $line, $mSlot);
            $rawSlot = $mSlot[1] ?? 'none';
            $slot = explode(';', $rawSlot)[0];

            // Базовые параметры
            preg_match('/weapon_type=([a-zA-Z0-9_]+)/', $line, $mWpnType);
            preg_match('/armor_type=([a-zA-Z0-9_]+)/', $line, $mArmType);
            preg_match('/crystal_type=([a-zA-Z0-9_]+)/', $line, $mGrade);
            preg_match('/crystal_count=(\d+)/', $line, $mCrystCount);
            preg_match('/material_type=([a-zA-Z0-9_]+)/', $line, $mMaterial);
            preg_match('/weight=(\d+)/', $line, $mWeight);
            preg_match('/default_price=(\d+)/', $line, $mPrice);
            preg_match('/consume_type=([a-zA-Z0-9_]+)/', $line, $mConsume);

            // Боевые параметры
            preg_match('/physical_damage=(\d+)/', $line, $mPAtk);
            preg_match('/magical_damage=(\d+)/', $line, $mMAtk);
            preg_match('/random_damage=(\d+)/', $line, $mRndDam);
            preg_match('/physical_defense=(\d+)/', $line, $mPDef);
            preg_match('/magical_defense=(\d+)/', $line, $mMDef);
            preg_match('/mp_bonus=(\d+)/', $line, $mMpBonus);
            preg_match('/shield_defense=(\d+)/', $line, $mShDef);
            preg_match('/shield_defense_rate=(\d+)/', $line, $mShRate);
            preg_match('/critical=(\d+)/', $line, $mCrit);
            preg_match('/attack_speed=(\d+)/', $line, $mSpd);
            preg_match('/attack_range=(\d+)/', $line, $mAtkRange);
            preg_match('/mp_consume=(\d+)/', $line, $mMpConsume);
            preg_match('/soulshot_count=(\d+)/', $line, $mSs);
            preg_match('/spiritshot_count=(\d+)/', $line, $mSps);
            preg_match('/magic_weapon=(\d+)/', $line, $mMagicWpn);

            // Флаги передачи/дропа
            preg_match('/is_trade=(\d+)/', $line, $mTrade);
            preg_match('/is_drop=(\d+)/', $line, $mDrop);
            preg_match('/is_destruct=(\d+)/', $line, $mDestruct);

            $grade = $mGrade[1] ?? 'none';
            $crystalCount = (int)($mCrystCount[1] ?? 0);
            $isCrystallizable = ($grade !== 'none' && $crystalCount > 0);

            $consumeType = $mConsume[1] ?? 'consume_type_normal';
            $isStackable = in_array($consumeType, ['consume_type_stackable', 'consume_type_asset'], true);

            // 4. Поиск соответствующего файла иконки
            $rawClientIcon = $clientIcons[$id] ?? '';
            $cleanClientIcon = preg_replace('/^icon\./i', '', $rawClientIcon);

            $resolvedIcon = null;
            if ($cleanClientIcon !== '' && isset($availableIcons[strtolower($cleanClientIcon)])) {
                $resolvedIcon = $availableIcons[strtolower($cleanClientIcon)];
            } elseif ($rawClientIcon !== '' && isset($availableIcons[strtolower($rawClientIcon)])) {
                $resolvedIcon = $availableIcons[strtolower($rawClientIcon)];
            } elseif (isset($availableIcons[$sysName])) {
                $resolvedIcon = $availableIcons[$sysName];
            } elseif (isset($availableIcons[(string)$id])) {
                $resolvedIcon = $availableIcons[(string)$id];
            } else {
                // Если файла еще нет на диске — сохраняем стандартное имя для будущей подгрузки
                $resolvedIcon = $cleanClientIcon !== '' ? $cleanClientIcon . '.png' : $sysName . '.png';
            }

            if (isset($availableIcons[strtolower(pathinfo($resolvedIcon, PATHINFO_FILENAME))])) {
                $matchedIconsCount++;
            }

            $items[$id] = [
                'name'              => $sysName,
                'type'              => $type,
                'slot'              => $slot ?: 'none',
                'weapon_type'       => $mWpnType[1] ?? 'none',
                'armor_type'        => $mArmType[1] ?? 'none',
                'material'          => $mMaterial[1] ?? 'steel',
                'grade'             => $grade,
                'is_crystallizable' => $isCrystallizable,
                'crystal_count'     => $crystalCount,
                'p_atk'             => (int)($mPAtk[1] ?? 0),
                'm_atk'             => (int)($mMAtk[1] ?? 0),
                'random_damage'     => (int)($mRndDam[1] ?? 10),
                'p_def'             => (int)($mPDef[1] ?? 0),
                'm_def'             => (int)($mMDef[1] ?? 0),
                'mp_bonus'          => (int)($mMpBonus[1] ?? 0),
                'shield_def'        => (int)($mShDef[1] ?? 0),
                'shield_rate'       => (int)($mShRate[1] ?? 0),
                'critical'          => (int)($mCrit[1] ?? 0),
                'spd'               => (int)($mSpd[1] ?? 0),
                'attack_range'      => (int)($mAtkRange[1] ?? 40),
                'mp_consume'        => (int)($mMpConsume[1] ?? 0),
                'weight'            => (int)($mWeight[1] ?? 0),
                'price'             => (int)($mPrice[1] ?? 0),
                'stackable'         => $isStackable,
                'ss_count'          => (int)($mSs[1] ?? 0),
                'sps_count'         => (int)($mSps[1] ?? 0),
                'is_magic_weapon'   => (bool)((int)($mMagicWpn[1] ?? 0)),
                'is_tradeable'      => (bool)((int)($mTrade[1] ?? 1)),
                'is_droppable'      => (bool)((int)($mDrop[1] ?? 1)),
                'is_destroyable'    => (bool)((int)($mDestruct[1] ?? 1)),
                'icon'              => $resolvedIcon,
            ];

            $totalParsed++;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Сохранение в {$outputFile}...\n");
        $exportedArray = VarDumper::export($items);
        file_put_contents($outputFile, "<?php\ndeclare(strict_types=1);\n\nreturn {$exportedArray};\n");

        $this->stdout("Готово! Обработано предметов: {$totalParsed} (найдено иконок на диске: {$matchedIconsCount})\n");
        return ExitCode::OK;
    }


    /**
     * Парсинг монстров и дропа:
     * php yii pts/npcs
     */
    /*public function actionNpcs(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/npcdata.txt');
        $itemsPath = Yii::getAlias('@app/data/game/items.php');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/npcs.php';

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!file_exists($itemsPath)) {
            $this->stderr("Ошибка: сначала выполните php yii pts/items!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Создание карты предметов system_name -> id...\n");
        $itemsData = require $itemsPath;
        $itemNameToId = [];
        foreach ($itemsData as $itemId => $itemRow) {
            $itemNameToId[$itemRow['name']] = $itemId;
        }

        $this->stdout("Чтение файла npcdata.txt...\n");
        $content = file_get_contents($inputPath);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $npcs = [];
        $totalParsed = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_contains($line, 'npc_begin')) {
                continue;
            }

            // Извлекаем тип, ID и системное имя
            // Пример: npc_begin warrior 1 [gremlin] ...
            if (!preg_match('/npc_begin\s+([a-zA-Z0-9_]+)\s+(\d+)\s+\[([^\]]+)\]/', $line, $head)) {
                continue;
            }

            $type = $head[1];
            $id = (int)$head[2];
            $sysName = $head[3];

            // Базовые статы
            preg_match('/level=(\d+)/', $line, $mLvl);
            preg_match('/exp=(\d+)/', $line, $mExp);
            preg_match('/acquire_sp=([\d\.]+)/', $line, $mSp);
            preg_match('/org_hp=(\d+)/', $line, $mHp);
            preg_match('/org_mp=(\d+)/', $line, $mMp);
            preg_match('/base_physical_attack=(\d+)/', $line, $mPAtk);
            preg_match('/base_magic_attack=(\d+)/', $line, $mMAtk);
            preg_match('/base_defend=(\d+)/', $line, $mPDef);
            preg_match('/base_magic_defend=(\d+)/', $line, $mMDef);
            preg_match('/base_attack_speed=(\d+)/', $line, $mSpd);
            preg_match('/base_critical=(\d+)/', $line, $mCrit);
            preg_match('/org_hp_regen=([\d\.]+)/', $line, $mHpReg);
            preg_match('/org_mp_regen=([\d\.]+)/', $line, $mMpReg);

            // 1. Парсинг спойла (corpse_make_list)
            $spoils = [];
            if (preg_match('/corpse_make_list\s*=\s*\{([^\}]*)\}/', $line, $mCorpse)) {
                preg_match_all('/\{\[([^\]]+)\];(\d+);(\d+);([\d\.]+)\}/', $mCorpse[1], $spoilMatches, PREG_SET_ORDER);
                foreach ($spoilMatches as $sm) {
                    $itemSysName = $sm[1];
                    $itemId = $itemNameToId[$itemSysName] ?? null;
                    if ($itemId !== null) {
                        $spoils[] = [
                            'item_id' => $itemId,
                            'name'    => $itemSysName,
                            'min'     => (int)$sm[2],
                            'max'     => (int)$sm[3],
                            'chance'  => (float)$sm[4],
                        ];
                    }
                }
            }

            // 2. Парсинг дропа (additional_make_multi_list)
            $drops = [];
            if (preg_match('/additional_make_multi_list\s*=\s*\{(.*)\}\s*slot_chest/s', $line, $mDropList)) {
                // Извлекаем группы вида: {{{...}};group_chance}
                preg_match_all('/\{\{\{(.*?)\}\};([\d\.]+)\}/', $mDropList[1], $groups, PREG_SET_ORDER);
                foreach ($groups as $group) {
                    $itemsBlock = $group[1];
                    $groupChance = (float)$group[2];

                    // Внутри группы ищем отдельные предметы {[item];min;max;chance}
                    preg_match_all('/\{\[([^\]]+)\];(\d+);(\d+);([\d\.]+)\}/', $itemsBlock, $itemMatches, PREG_SET_ORDER);
                    foreach ($itemMatches as $im) {
                        $itemSysName = $im[1];
                        $itemId = $itemNameToId[$itemSysName] ?? null;
                        if ($itemId !== null) {
                            $itemWeightChance = (float)$im[4];
                            // Итоговый шанс предмета = (шанс_группы * вес_предмета) / 100
                            $realChance = round(($groupChance * $itemWeightChance) / 100.0, 4);

                            $drops[] = [
                                'item_id' => $itemId,
                                'name'    => $itemSysName,
                                'min'     => (int)$im[2],
                                'max'     => (int)$im[3],
                                'chance'  => $realChance,
                            ];
                        }
                    }
                }
            }

            $npcs[$id] = [
                'name'     => $sysName,
                'type'     => $type,
                'level'    => (int)($mLvl[1] ?? 1),
                'hp'       => (int)($mHp[1] ?? 10),
                'mp'       => (int)($mMp[1] ?? 10),
                'p_atk'    => (int)($mPAtk[1] ?? 1),
                'm_atk'    => (int)($mMAtk[1] ?? 1),
                'p_def'    => (int)($mPDef[1] ?? 1),
                'm_def'    => (int)($mMDef[1] ?? 1),
                'exp'      => (int)($mExp[1] ?? 0),
                'sp'       => (int)($mSp[1] ?? 0),
                'spd'      => (int)($mSpd[1] ?? 253),
                'critical' => (int)($mCrit[1] ?? 4),
                'hp_regen' => (float)($mHpReg[1] ?? 1.0),
                'mp_regen' => (float)($mMpReg[1] ?? 1.0),
                'drops'    => $drops,
                'spoils'   => $spoils,
            ];

            $totalParsed++;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Генерация файла {$outputFile}...\n");
        $exportedArray = VarDumper::export($npcs);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\nreturn {$exportedArray};\n";
        file_put_contents($outputFile, $fileContent);

        $this->stdout("Готово! Успешно обработано монстров: {$totalParsed}\n");
        return ExitCode::OK;
    }*/

    /**
     * Парсинг монстров, наград и дропа:
     * php yii pts/npcs
     */
    /**
     * Парсинг монстров, наград и дропа:
     * php yii pts/npcs
     */
    public function actionNpcs(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/npcdata.txt');
        $itemsPath = Yii::getAlias('@app/data/game/items.php');
        $expTablePath = Yii::getAlias('@app/data/game/exp_table.php');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/npcs.php';

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!file_exists($itemsPath)) {
            $this->stderr("Ошибка: сначала выполните php yii pts/items!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $expTable = file_exists($expTablePath) ? require $expTablePath : [];

        $this->stdout("Создание карты предметов system_name -> id...\n");
        $itemsData = require $itemsPath;
        $itemNameToId = [];
        foreach ($itemsData as $itemId => $itemRow) {
            $itemNameToId[strtolower(trim($itemRow['name']))] = (int)$itemId;
        }

        // КРИТИЧНО: принудительное связывание адены
        $itemNameToId['adena'] = 57;
        $itemNameToId['money'] = 57;

        // 1. Загрузка карты скиллов: system_name -> ['id' => X, 'level' => Y]
        $skillsPath = Yii::getAlias('@app/data/game/skills.php');
        $skillsData = file_exists($skillsPath) ? require $skillsPath : [];
        $skillNameToRef = [];
        foreach ($skillsData as $sId => $lvls) {
            foreach ($lvls as $lvl => $sRow) {
                $sName = strtolower(trim($sRow['name']));
                $skillNameToRef[$sName] = [
                    'skill_id' => (int)$sId,
                    'level'    => (int)$lvl,
                ];
            }
        }

        $this->stdout("Чтение файла npcdata.txt...\n");
        $content = file_get_contents($inputPath);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $npcs = [];
        $totalParsed = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_contains($line, 'npc_begin')) {
                continue;
            }

            if (!preg_match('/npc_begin\s+([a-zA-Z0-9_]+)\s+(\d+)\s+\[([^\]]+)\]/', $line, $head)) {
                continue;
            }

            $type = $head[1];
            $id = (int)$head[2];
            $sysName = $head[3];

            // Базовые числовые параметры
            preg_match('/level=(\d+)/', $line, $mLvl);
            preg_match('/acquire_exp_rate=([\d\.]+)/', $line, $mExpRate);
            preg_match('/acquire_sp=([\d\.]+)/', $line, $mSp);
            preg_match('/org_hp=([\d\.]+)/', $line, $mHp);
            preg_match('/org_mp=([\d\.]+)/', $line, $mMp);
            preg_match('/base_physical_attack=([\d\.]+)/', $line, $mPAtk);
            preg_match('/base_magic_attack=([\d\.]+)/', $line, $mMAtk);
            preg_match('/base_defend=([\d\.]+)/', $line, $mPDef);
            preg_match('/base_magic_defend=([\d\.]+)/', $line, $mMDef);
            preg_match('/base_attack_speed=(\d+)/', $line, $mSpd);
            preg_match('/base_critical=(\d+)/', $line, $mCrit);
            preg_match('/org_hp_regen=([\d\.]+)/', $line, $mHpReg);
            preg_match('/org_mp_regen=([\d\.]+)/', $line, $mMpReg);

            $lvl = (int)($mLvl[1] ?? 1);
            $expRate = (float)($mExpRate[1] ?? 0.0);
            $sp = (int)round((float)($mSp[1] ?? 0.0));

            // Расчет опыта PTS
            $lvlRange = ($expTable[$lvl + 1] ?? ($expTable[$lvl] ?? 0)) - ($expTable[$lvl] ?? 0);
            $rewardExp = $lvlRange > 0 ? (int)round($lvlRange * ($expRate / 100.0)) : 0;

            // 1. СПОЙЛ (corpse_make_list)
            $spoils = [];
            if (preg_match('/corpse_make_list\s*=\s*\{(.*)\}\s*additional_make_list/s', $line, $mCorpse)) {
                preg_match_all('/\{\[([^\]]+)\];(\d+);(\d+);([\d\.]+)\}/', $mCorpse[1], $spoilMatches, PREG_SET_ORDER);
                foreach ($spoilMatches as $sm) {
                    $itemSysName = strtolower(trim($sm[1]));
                    $itemId = $itemNameToId[$itemSysName] ?? null;
                    if ($itemId !== null) {
                        $spoils[] = [
                            'item_id' => $itemId,
                            'name'    => $itemSysName,
                            'min'     => (int)$sm[2],
                            'max'     => (int)$sm[3],
                            'chance'  => (float)$sm[4],
                        ];
                    }
                }
            }

            // 2. МУЛЬТИДРОП (additional_make_multi_list, граница строго до slot_chest)
            $drops = [];
            if (preg_match('/additional_make_multi_list\s*=\s*\{(.*)\}\s*slot_chest/s', $line, $mMulti)) {
                // Извлекаем группы: {{ {items...} }; group_chance}
                preg_match_all('/\{\s*\{\s*(.*?)\s*\}\s*;\s*([\d\.]+)\s*\}/s', $mMulti[1], $groups, PREG_SET_ORDER);
                foreach ($groups as $group) {
                    $itemsBlock = $group[1];
                    $groupChance = (float)$group[2];

                    // Извлекаем каждый предмет внутри группы: {[name];min;max;weight}
                    preg_match_all('/\{\[([^\]]+)\];(\d+);(\d+);([\d\.]+)\}/', $itemsBlock, $itemMatches, PREG_SET_ORDER);
                    foreach ($itemMatches as $im) {
                        $itemSysName = strtolower(trim($im[1]));
                        $itemId = $itemNameToId[$itemSysName] ?? null;
                        if ($itemId !== null) {
                            $itemWeight = (float)$im[4];
                            $realChance = round(($groupChance * $itemWeight) / 100.0, 4);

                            $drops[] = [
                                'item_id' => $itemId,
                                'name'    => $itemSysName,
                                'min'     => (int)$im[2],
                                'max'     => (int)$im[3],
                                'chance'  => $realChance,
                            ];
                        }
                    }
                }
            }

            // 3. Одиночный дроп (item_make_list и additional_make_list)
            if (preg_match('/item_make_list\s*=\s*\{(.*?)\}/s', $line, $mItemMake)) {
                preg_match_all('/\{\[([^\]]+)\];(\d+);(\d+);([\d\.]+)\}/', $mItemMake[1], $singleMatches, PREG_SET_ORDER);
                foreach ($singleMatches as $sm) {
                    $itemSysName = strtolower(trim($sm[1]));
                    $itemId = $itemNameToId[$itemSysName] ?? null;
                    if ($itemId !== null) {
                        $drops[] = [
                            'item_id' => $itemId,
                            'name'    => $itemSysName,
                            'min'     => (int)$sm[2],
                            'max'     => (int)$sm[3],
                            'chance'  => (float)$sm[4],
                        ];
                    }
                }
            }

            if (preg_match('/additional_make_list\s*=\s*\{(.*?)\}\s*additional_make_multi_list/s', $line, $mAddMake)) {
                preg_match_all('/\{\[([^\]]+)\];(\d+);(\d+);([\d\.]+)\}/', $mAddMake[1], $singleMatches, PREG_SET_ORDER);
                foreach ($singleMatches as $sm) {
                    $itemSysName = strtolower(trim($sm[1]));
                    $itemId = $itemNameToId[$itemSysName] ?? null;
                    if ($itemId !== null) {
                        $drops[] = [
                            'item_id' => $itemId,
                            'name'    => $itemSysName,
                            'min'     => (int)$sm[2],
                            'max'     => (int)$sm[3],
                            'chance'  => (float)$sm[4],
                        ];
                    }
                }
            }

            // 4. ПАРСИНГ УМЕНИЙ AI (npc_ai)
            $npcSkills = [];
            if (preg_match('/npc_ai\s*=\s*\{([^}]*(?:\{[^}]*\}[^}]*)*)\}/', $line, $mAi)) {
                // Ищем любые связки вида [Trigger]=@skill_name
                preg_match_all('/\[([a-zA-Z0-9_]+)\]\s*=\s*@([a-zA-Z0-9_]+)/', $mAi[1], $aiMatches, PREG_SET_ORDER);
                foreach ($aiMatches as $aim) {
                    $trigger = $aim[1];
                    $skillSysName = strtolower(trim($aim[2]));

                    $ref = $skillNameToRef[$skillSysName] ?? null;
                    if ($ref !== null) {
                        $npcSkills[] = [
                            'trigger'  => $trigger,
                            'skill_id' => $ref['skill_id'],
                            'level'    => $ref['level'],
                            'name'     => $skillSysName,
                        ];
                    }
                }
            }

            $npcs[$id] = [
                'name'     => $sysName,
                'type'     => $type,
                'level'    => $lvl,
                'hp'       => (int)round((float)($mHp[1] ?? 10.0)),
                'mp'       => (int)round((float)($mMp[1] ?? 10.0)),
                'p_atk'    => (int)round((float)($mPAtk[1] ?? 1.0)),
                'm_atk'    => (int)round((float)($mMAtk[1] ?? 1.0)),
                'p_def'    => (int)round((float)($mPDef[1] ?? 1.0)),
                'm_def'    => (int)round((float)($mMDef[1] ?? 1.0)),
                'exp'      => $rewardExp,
                'sp'       => $sp,
                'spd'      => (int)($mSpd[1] ?? 253),
                'critical' => (int)($mCrit[1] ?? 4),
                'hp_regen' => (float)($mHpReg[1] ?? 1.0),
                'mp_regen' => (float)($mMpReg[1] ?? 1.0),
                'drops'    => $drops,
                'spoils'   => $spoils,
                'skills'   => $npcSkills
            ];

            $totalParsed++;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Генерация файла {$outputFile}...\n");
        $exportedArray = VarDumper::export($npcs);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\nreturn {$exportedArray};\n";
        file_put_contents($outputFile, $fileContent);

        $this->stdout("Готово! Успешно обработано мобов: {$totalParsed}\n");
        return ExitCode::OK;
    }

    /**
     * Скачивание названий, описаний и иконок предметов:
     * php yii pts/fetch-items
     */
    public function actionFetchItems(): int
    {
        $itemsPath = Yii::getAlias('@app/data/game/items.php');
        if (!file_exists($itemsPath)) {
            $this->stderr("Сначала выполните php yii pts/items!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $items = require $itemsPath;
        $imgDir = Yii::getAlias('@app/web/images/icons/items');
        if (!is_dir($imgDir)) {
            mkdir($imgDir, 0777, true);
        }

        $enMessages = [];
        $ruMessages = [];
        $total = count($items);
        $current = 0;

        $this->stdout("Старт загрузки данных по {$total} предметам...\n");

        foreach ($items as $id => $item) {
            $current++;

            // Запрашиваем данные по предмету через открытый API Interlude
            $apiUrl = "https://l2api.dev/api/interlude/items/{$id}";
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'user_agent' => 'L2WapEngine/1.0',
                    'ignore_errors' => true,
                ]
            ]);

            $json = @file_get_contents($apiUrl, false, $ctx);
            if ($json) {
                $response = json_decode($json, true);
                $data = $response['data'] ?? null;

                if ($data) {
                    $nameEn = $data['name'] ?? $item['name'];
                    $descEn = $data['description'] ?? '';

                    $enMessages[$id] = [
                        'name' => $nameEn,
                        'desc' => $descEn,
                    ];

                    // Базовый перевод или заглушка для RU
                    $ruMessages[$id] = [
                        'name' => $nameEn, // сюда можно подмешать RU-дамп
                        'desc' => $descEn,
                    ];

                    // Скачивание иконки PNG, если её еще нет локально
                    $iconFile = "{$imgDir}/{$id}.png";
                    if (!file_exists($iconFile) && !empty($data['icon'])) {
                        // l2api / cdn URL иконки
                        $iconUrl = $data['icon'];
                        if (!str_starts_with($iconUrl, 'http')) {
                            $iconUrl = "https://l2api.dev" . $iconUrl;
                        }
                        $imgData = @file_get_contents($iconUrl, false, $ctx);
                        if ($imgData) {
                            file_put_contents($iconFile, $imgData);
                        }
                    }
                }
            }

            if ($current % 50 === 0 || $current === $total) {
                $this->stdout("Обработано [{$current}/{$total}] предметов...\n");
            }

            // Микро-пауза (0.03 сек), чтобы не перегружать API
            usleep(30000);
        }

        // Сохраняем языковые словари
        $this->saveLocalization('items', 'en', $enMessages);
        $this->saveLocalization('items', 'ru', $ruMessages);

        $this->stdout("Готово! Предметы и иконки успешно загружены.\n");
        return ExitCode::OK;
    }

    private function saveLocalization(string $category, string $lang, array $data): void
    {
        $dir = Yii::getAlias("@app/messages/{$lang}");
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = "{$dir}/{$category}.php";
        // Если файл уже был и в нем есть ручные переводы, объединяем их
        $existing = file_exists($file) ? require $file : [];
        $merged = array_replace($data, $existing);

        $exported = VarDumper::export($merged);
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\n\nreturn {$exported};\n");
    }

    /**
     * Импорт локализации из XML-формата Excel (itemname.xml, skillname.xml и т.д.)
     * Запуск: php yii pts/import-xml @app/data/pts/itemname.xml items en
     */
    public function actionImportXml(string $filePath, string $category, string $lang = 'en'): int
    {
        $realPath = Yii::getAlias($filePath);
        if (!file_exists($realPath)) {
            $this->stderr("Файл не найден: {$realPath}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Чтение XML файла локализации ({$category} / {$lang})...\n");

        $xmlContent = file_get_contents($realPath);

        if (!mb_check_encoding($xmlContent, 'UTF-8')) {
            $xmlContent = mb_convert_encoding($xmlContent, 'UTF-8', 'Windows-1251');
        }

        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            $this->stderr("Ошибка парсинга XML структуры!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $namespaces = $xml->getNamespaces(true);
        $ss = $namespaces['ss'] ?? 'urn:schemas-microsoft-com:office:spreadsheet';
        $xml->registerXPathNamespace('ss', $ss);

        $rows = $xml->xpath('//ss:Row');
        if (empty($rows)) {
            $rows = $xml->Worksheet->Table->Row ?? [];
        }

        $result = [];
        $isHeader = true;
        $count = 0;

        foreach ($rows as $row) {
            if ($isHeader) {
                $isHeader = false;
                continue;
            }

            $cells = $row->xpath('ss:Cell');
            if (empty($cells)) {
                $cells = $row->Cell ?? [];
            }

            $rowData = [];
            $colIndex = 1;
            foreach ($cells as $cell) {
                $attributes = $cell->attributes($namespaces['ss'] ?? 'urn:schemas-microsoft-com:office:spreadsheet');
                if (isset($attributes['Index'])) {
                    $colIndex = (int)$attributes['Index'];
                }

                $dataNode = $cell->children($namespaces['ss'] ?? 'urn:schemas-microsoft-com:office:spreadsheet');
                $text = '';

                if (isset($dataNode->Data)) {
                    $text = trim((string)$dataNode->Data);
                } else {
                    $text = trim((string)$cell->Data);
                }

                $rowData[$colIndex] = $text;
                $colIndex++;
            }

            $id = isset($rowData[1]) ? (int)$rowData[1] : 0;
            if ($id <= 0) {
                continue;
            }

            $name = $rowData[2] ?? '';
            $desc = $rowData[4] ?? '';

            if ($desc === '-1') {
                $desc = '';
            }

            // Очищаем имя и описание от клиентских префиксов ("a,", "u," и т.д.)
            $name = $this->cleanText($name);
            $desc = $this->cleanText($desc);

            if ($category === 'skills') {
                $level = isset($rowData[2]) && is_numeric($rowData[2]) ? (int)$rowData[2] : 1;
                $skillName = $rowData[3] ?? $name;
                $skillDesc = $rowData[5] ?? $desc;

                $result[$id][$level] = [
                    'name' => $skillName,
                    'desc' => $skillDesc,
                ];
            } else {
                $result[$id] = [
                    'name' => $name,
                    'desc' => $desc,
                ];
            }

            $count++;
        }

        $outDir = Yii::getAlias("@app/messages/{$lang}");
        if (!is_dir($outDir)) {
            mkdir($outDir, 0777, true);
        }

        $outFile = "{$outDir}/{$category}.php";
        $existing = file_exists($outFile) ? require $outFile : [];
        $merged = array_replace($result, $existing);

        $exported = VarDumper::export($merged);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\nreturn {$exported};\n";

        file_put_contents($outFile, $fileContent);

        $this->stdout("Успешно! Импортировано записей: {$count} в файл {$outFile}\n");
        return ExitCode::OK;
    }

    /**
     * Очистка текста от клиентских префиксов (a, / u,) и спецсимволов
     */
    private function cleanText(string $text): string
    {
        // Удаляем нулевые байты и переносы строк каретки
        $text = str_replace(["\0", "\r"], '', $text);

        // Удаляем префиксы вроде "a,", "u," в самом начале строки (если они там есть)
        if (preg_match('/^[au],(.*)$/s', $text, $matches)) {
            $text = $matches[1];
        }

        // Заменяем escaped переводы строк на пробелы
        $text = str_replace('\n', ' ', $text);
        $text = str_replace('\\0', '', $text);

        return trim($text);
    }

    /**
     * Синхронизация иконок на основе готового PHP-массива и GRP-файла.
     *
     * Примеры:
     * php yii pts/sync-icons @app/data/game/skills.php @app/data/pts/skillgrp.txt
     * php yii pts/sync-icons @app/data/game/items.php @app/data/pts/Weapongrp.txt
     * php yii pts/sync-icons @app/data/game/items.php @app/data/pts/armorgrp.txt
     * php yii pts/sync-icons @app/data/game/items.php @app/data/pts/etcitemgrp.txt
     * php yii pts/sync-icons @app/data/game/npcs.php @app/data/pts/npcgrp.txt
     */
    public function actionSyncIcons(string $arrayPath, string $grpPath, ?string $type = null): int
    {
        $realArrayPath = Yii::getAlias($arrayPath);
        $realGrpPath = Yii::getAlias($grpPath);

        if (!file_exists($realArrayPath)) {
            $this->stderr("Ошибка: файл массива {$realArrayPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!file_exists($realGrpPath)) {
            $this->stderr("Ошибка: файл GRP {$realGrpPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Автодетект типа (папки назначения)
        if ($type === null) {
            $lower = strtolower($arrayPath);
            if (str_contains($lower, 'skill')) {
                $type = 'skills';
            } elseif (str_contains($lower, 'npc')) {
                $type = 'npc';
            } else {
                $type = 'items';
            }
        }

        $sourceDir = Yii::getAlias('@app/web/images/icons');
        $targetDir = Yii::getAlias("@app/web/images/icons/{$type}");

        if (!is_dir($sourceDir)) {
            $this->stderr("Ошибка: папка с иконками {$sourceDir} не существует!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // 1. Загружаем целевые ID
        // Для skills.php ключи верхнего уровня (1, 2, 3...) — это как раз skill_id!
        $this->stdout("Загрузка целевого массива из {$realArrayPath}...\n");
        $data = require $realArrayPath;
        if (!is_array($data)) {
            $this->stderr("Ошибка: файл {$realArrayPath} не вернул массив!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $neededIds = [];
        foreach ($data as $k => $v) {
            if (is_int($k) || ctype_digit((string)$k)) {
                $neededIds[(int)$k] = true;
            } elseif (is_array($v) && isset($v['id'])) {
                $neededIds[(int)$v['id']] = true;
            }
        }
        unset($data);

        $totalNeeded = count($neededIds);
        $this->stdout("В массиве найдено уникальных ID: {$totalNeeded}. Папка назначения: [{$type}]\n");

        // 2. Индексируем файлы в web/images/icons
        $this->stdout("Сканирование файлов в {$sourceDir}...\n");
        $availableIcons = [];
        foreach (scandir($sourceDir) as $f) {
            if ($f === '.' || $f === '..' || is_dir("{$sourceDir}/{$f}")) {
                continue;
            }
            $cleanName = strtolower(pathinfo($f, PATHINFO_FILENAME));
            $availableIcons[$cleanName] = $f;
        }

        // 3. Читаем GRP файл
        $this->stdout("Чтение GRP файла {$realGrpPath}...\n");
        $content = file_get_contents($realGrpPath);
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        // 4. Парсинг соответствий
        $copiedCount = 0;
        $alreadyExists = 0;
        $handledSkillIds = []; // Запоминаем ID скиллов, чтобы не обрабатывать повторные уровни
        $missingIcons = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '#')) {
                continue;
            }

            $id = null;

            // Вариант А: ключ-значение
            if (preg_match('/(?:skill_id|item_id|object_id|npc_id)\s*=\s*(\d+)/i', $line, $mId)) {
                $id = (int)$mId[1];
            }
            // Вариант Б: колоночный формат TSV L2FileEdit
            else {
                $cols = preg_split('/\s+/', $line);
                if ($type === 'skills') {
                    // В skillgrp колонка 0 — это skill_id
                    if (isset($cols[0]) && is_numeric($cols[0])) {
                        $id = (int)$cols[0];
                    }
                } else {
                    // В Weapongrp/armorgrp колонка 1 — item_id (или 0)
                    if (isset($cols[1]) && is_numeric($cols[1])) {
                        $id = (int)$cols[1];
                    } elseif (isset($cols[0]) && is_numeric($cols[0])) {
                        $id = (int)$cols[0];
                    }
                }
            }

            // Фильтр: обрабатываем только те ID, которые есть в нашем массиве
            if ($id === null || !isset($neededIds[$id])) {
                continue;
            }

            // Для скиллов: если иконка для этого skill_id уже найдена (на уровне 1), пропускаем уровни 2..37
            if ($type === 'skills' && isset($handledSkillIds[$id])) {
                continue;
            }

            // Поиск имени текстуры
            $iconTexture = null;

            // Ищем шаблон icon.имя_текстуры
            if (preg_match('/icon\.([a-zA-Z0-9_]+)/i', $line, $mIcon)) {
                if (strtolower($mIcon[1]) !== 'none') {
                    $iconTexture = $mIcon[1];
                }
            }

            // Для скиллов в клиенте текстура часто указана как skill0001 (без префикса icon.)
            if (!$iconTexture && $type === 'skills') {
                if (preg_match('/(?:icon\.)?(skill\d+[a-zA-Z0-9_]*)/i', $line, $mIcon)) {
                    if (strtolower($mIcon[1]) !== 'none') {
                        $iconTexture = $mIcon[1];
                    }
                }
            }

            if ($iconTexture === null) {
                continue;
            }

            // Ищем картинку в папке icons/
            $searchKeys = [
                strtolower($iconTexture),
                strtolower("icon.{$iconTexture}"),
            ];

            $sourceFileName = null;
            foreach ($searchKeys as $k) {
                if (isset($availableIcons[$k])) {
                    $sourceFileName = $availableIcons[$k];
                    break;
                }
            }

            if ($sourceFileName !== null) {
                $ext = pathinfo($sourceFileName, PATHINFO_EXTENSION);
                $destFile = "{$targetDir}/{$id}.{$ext}";

                if (!file_exists($destFile)) {
                    copy("{$sourceDir}/{$sourceFileName}", $destFile);
                    $copiedCount++;
                } else {
                    $alreadyExists++;
                }

                if ($type === 'skills') {
                    $handledSkillIds[$id] = true;
                }
            } else {
                $missingIcons[$id] = $iconTexture;
            }
        }

        $this->stdout("--------------------------------------------------\n");
        $this->stdout("Всего уникальных ID в массиве: {$totalNeeded}\n");
        $this->stdout("Скопировано новых иконок: {$copiedCount}\n");
        if ($alreadyExists > 0) {
            $this->stdout("Уже существовали на диске: {$alreadyExists}\n");
        }
        if (!empty($missingIcons)) {
            $missCount = count($missingIcons);
            $this->stdout("Не найдено картинок в папке icons/: {$missCount}\n");
            $sample = array_slice($missingIcons, 0, 5, true);
            foreach ($sample as $mId => $mTex) {
                $this->stdout("  - ID {$mId} -> текстура {$mTex}\n");
            }
        }
        $this->stdout("--------------------------------------------------\n");

        return ExitCode::OK;
    }

    /**
     * Парсинг параметров персонажей и формул:
     * php yii pts/pc-params
     */
    public function actionPcParams(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/PC_parameter.txt');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/pc_params.php';

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Чтение PC_parameter.txt...\n");

        $content = file_get_contents($inputPath);
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $data = [
            'level_bonus' => [],
            'stat_bonus'  => [
                'str' => [], 'int' => [], 'con' => [],
                'men' => [], 'dex' => [], 'wit' => [],
            ],
            'templates'   => [],
            'hp_tables'   => [],
            'mp_tables'   => [],
            'regen_bonus' => [],
        ];

        $currentBlock = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Пропуск комментариев и пустых строк
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            // Отрезаем инлайн-комментарии (//...)
            if (($pos = strpos($line, '//')) !== false) {
                $line = trim(substr($line, 0, $pos));
            }

            // Проверка начала блока: xxx_begin
            if (preg_match('/^([a-zA-Z0-9_]+)_begin$/', $line, $m)) {
                $currentBlock = $m[1];
                continue;
            }

            // Проверка конца блока: xxx_end
            if (preg_match('/^([a-zA-Z0-9_]+)_end$/', $line, $m)) {
                $currentBlock = null;
                continue;
            }

            if ($currentBlock === null) {
                continue;
            }

            // Разбор пары "ключ = значение"
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);

            // 1. level_bonus
            if ($currentBlock === 'level_bonus') {
                if (preg_match('/lvl_(\d+)/', $key, $mKey)) {
                    $data['level_bonus'][(int)$mKey[1]] = (float)$val;
                }
            }
            // 2. Бонусы характеристик: str_bonus, con_bonus и т.д.
            elseif (str_ends_with($currentBlock, '_bonus') && in_array(substr($currentBlock, 0, 3), ['str', 'int', 'con', 'men', 'dex', 'wit'], true)) {
                $stat = substr($currentBlock, 0, 3);
                if (preg_match('/attribute_(\d+)/', $key, $mKey)) {
                    $data['stat_bonus'][$stat][(int)$mKey[1]] = (int)$val;
                }
            }
            // 3. Таблицы HP классов: xxx_hp_table
            elseif (str_ends_with($currentBlock, '_hp_table')) {
                $className = str_replace('_hp_table', '', $currentBlock);
                if (preg_match('/lvl_(\d+)/', $key, $mKey)) {
                    $data['hp_tables'][$className][(int)$mKey[1]] = (float)$val;
                }
            }
            // 4. Таблицы MP классов: xxx_mp_table
            elseif (str_ends_with($currentBlock, '_mp_table')) {
                $className = str_replace('_mp_table', '', $currentBlock);
                if (preg_match('/lvl_(\d+)/', $key, $mKey)) {
                    $data['mp_tables'][$className][(int)$mKey[1]] = (float)$val;
                }
            }
            // 5. Бонусы регенерации (сидя/стоя/бег)
            elseif ($currentBlock === 'regen_move_mode_bonus') {
                $data['regen_bonus'][$key] = (float)rtrim($val, '%') / 100.0;
            }
            // 6. Базовые параметры тел (FFighter, MMagic и т.д.)
            else {
                // Если значение вида {val1;val2;...}
                if (str_starts_with($val, '{') && str_ends_with($val, '}')) {
                    $rawItems = explode(';', trim($val, '{}'));
                    $parsedItems = array_map(fn($v) => is_numeric($v) ? (float)$v : trim($v), $rawItems);
                    $data['templates'][$key][$currentBlock] = $parsedItems;
                } else {
                    $data['templates'][$key][$currentBlock] = is_numeric($val) ? (float)$val : $val;
                }
            }
        }

        // Подмешиваем карту классов C4 (ID -> название расы, шаблона, статы STR/DEX/...)
        $data['classes'] = $this->getClassDefinitions();

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Сохранение в {$outputFile}...\n");
        $exportedArray = VarDumper::export($data);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\nreturn {$exportedArray};\n";
        file_put_contents($outputFile, $fileContent);

        $this->stdout("Готово! Параметры персонажей и таблицы классов успешно сгенерированы.\n");
        return ExitCode::OK;
    }

    /**
     * Справочник классов C4 со связкой с таблицами HP/MP и базовыми статами
     */
    private function getClassDefinitions(): array
    {
        return [
            // Люди-воины
            0  => ['name' => 'Human Fighter',    'template' => 'MFighter', 'hp_table' => 'fighter',         'mp_table' => 'fighter',         'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            1  => ['name' => 'Warrior',          'template' => 'MFighter', 'hp_table' => 'warrior',         'mp_table' => 'warrior',         'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            2  => ['name' => 'Gladiator',        'template' => 'MFighter', 'hp_table' => 'gladiator',       'mp_table' => 'gladiator',       'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            3  => ['name' => 'Warlord',          'template' => 'MFighter', 'hp_table' => 'warlord',         'mp_table' => 'warlord',         'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            4  => ['name' => 'Knight',           'template' => 'MFighter', 'hp_table' => 'knight',          'mp_table' => 'knight',          'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            5  => ['name' => 'Paladin',          'template' => 'MFighter', 'hp_table' => 'paladin',         'mp_table' => 'paladin',         'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            6  => ['name' => 'Dark Avenger',     'template' => 'MFighter', 'hp_table' => 'dark_avenger',    'mp_table' => 'dark_avenger',    'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            7  => ['name' => 'Rogue',            'template' => 'MFighter', 'hp_table' => 'rogue',           'mp_table' => 'rogue',           'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            8  => ['name' => 'Treasure Hunter',  'template' => 'MFighter', 'hp_table' => 'treasure_hunter', 'mp_table' => 'treasure_hunter', 'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],
            9  => ['name' => 'Hawkeye',          'template' => 'MFighter', 'hp_table' => 'hawkeye',         'mp_table' => 'hawkeye',         'str' => 40, 'dex' => 30, 'con' => 43, 'int' => 21, 'wit' => 11, 'men' => 25],

            // Люди-маги
            10 => ['name' => 'Human Mystic',     'template' => 'MMagic',   'hp_table' => 'mage',            'mp_table' => 'mage',            'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            11 => ['name' => 'Human Wizard',     'template' => 'MMagic',   'hp_table' => 'wizard',          'mp_table' => 'wizard',          'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            12 => ['name' => 'Sorcerer',         'template' => 'MMagic',   'hp_table' => 'sorcerer',        'mp_table' => 'sorcerer',        'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            13 => ['name' => 'Necromancer',      'template' => 'MMagic',   'hp_table' => 'necromancer',     'mp_table' => 'necromancer',     'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            14 => ['name' => 'Warlock',          'template' => 'MMagic',   'hp_table' => 'warlock',         'mp_table' => 'warlock',         'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            15 => ['name' => 'Cleric',           'template' => 'MMagic',   'hp_table' => 'cleric',          'mp_table' => 'cleric',          'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            16 => ['name' => 'Bishop',           'template' => 'MMagic',   'hp_table' => 'bishop',          'mp_table' => 'bishop',          'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],
            17 => ['name' => 'Prophet',          'template' => 'MMagic',   'hp_table' => 'prophet',         'mp_table' => 'prophet',         'str' => 22, 'dex' => 18, 'con' => 27, 'int' => 41, 'wit' => 20, 'men' => 39],

            // Эльфы
            18 => ['name' => 'Elven Fighter',    'template' => 'MElfFighter', 'hp_table' => 'elven_fighter',      'mp_table' => 'elven_fighter',      'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            19 => ['name' => 'Elven Knight',     'template' => 'MElfFighter', 'hp_table' => 'elven_knight',       'mp_table' => 'elven_knight',       'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            20 => ['name' => 'Temple Knight',    'template' => 'MElfFighter', 'hp_table' => 'temple_knight',      'mp_table' => 'temple_knight',      'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            21 => ['name' => 'Swordsinger',      'template' => 'MElfFighter', 'hp_table' => 'swordsinger',        'mp_table' => 'swordsinger',        'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            22 => ['name' => 'Elven Scout',      'template' => 'MElfFighter', 'hp_table' => 'elven_scout',        'mp_table' => 'elven_scout',        'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            23 => ['name' => 'Plain Walker',     'template' => 'MElfFighter', 'hp_table' => 'plain_walker',       'mp_table' => 'plain_walker',       'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            24 => ['name' => 'Silver Ranger',    'template' => 'MElfFighter', 'hp_table' => 'silver_ranger',      'mp_table' => 'silver_ranger',      'str' => 36, 'dex' => 35, 'con' => 36, 'int' => 23, 'wit' => 14, 'men' => 26],
            25 => ['name' => 'Elven Mystic',     'template' => 'MElfMagic',   'hp_table' => 'elven_mage',         'mp_table' => 'elven_mage',         'str' => 21, 'dex' => 24, 'con' => 25, 'int' => 37, 'wit' => 23, 'men' => 40],
            26 => ['name' => 'Elven Wizard',     'template' => 'MElfMagic',   'hp_table' => 'elven_wizard',       'mp_table' => 'elven_wizard',       'str' => 21, 'dex' => 24, 'con' => 25, 'int' => 37, 'wit' => 23, 'men' => 40],
            27 => ['name' => 'Spellsinger',      'template' => 'MElfMagic',   'hp_table' => 'spellsinger',        'mp_table' => 'spellsinger',        'str' => 21, 'dex' => 24, 'con' => 25, 'int' => 37, 'wit' => 23, 'men' => 40],
            28 => ['name' => 'Elemental Summoner','template' => 'MElfMagic',  'hp_table' => 'elemental_summoner', 'mp_table' => 'elemental_summoner', 'str' => 21, 'dex' => 24, 'con' => 25, 'int' => 37, 'wit' => 23, 'men' => 40],
            29 => ['name' => 'Elven Oracle',     'template' => 'MElfMagic',   'hp_table' => 'oracle',             'mp_table' => 'oracle',             'str' => 21, 'dex' => 24, 'con' => 25, 'int' => 37, 'wit' => 23, 'men' => 40],
            30 => ['name' => 'Elven Elder',      'template' => 'MElfMagic',   'hp_table' => 'elder',              'mp_table' => 'elder',              'str' => 21, 'dex' => 24, 'con' => 25, 'int' => 37, 'wit' => 23, 'men' => 40],

            // Темные Эльфы
            31 => ['name' => 'Dark Fighter',     'template' => 'MDarkelfFighter', 'hp_table' => 'dark_fighter',    'mp_table' => 'dark_fighter',    'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            32 => ['name' => 'Palus Knight',     'template' => 'MDarkelfFighter', 'hp_table' => 'palus_knight',    'mp_table' => 'palus_knight',    'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            33 => ['name' => 'Shillien Knight',  'template' => 'MDarkelfFighter', 'hp_table' => 'shillien_knight', 'mp_table' => 'shillien_knight', 'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            34 => ['name' => 'Blade Dancer',     'template' => 'MDarkelfFighter', 'hp_table' => 'bladedancer',     'mp_table' => 'bladedancer',     'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            35 => ['name' => 'Assassin',         'template' => 'MDarkelfFighter', 'hp_table' => 'assasin',         'mp_table' => 'assasin',         'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            36 => ['name' => 'Abyss Walker',     'template' => 'MDarkelfFighter', 'hp_table' => 'abyss_walker',    'mp_table' => 'abyss_walker',    'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            37 => ['name' => 'Phantom Ranger',   'template' => 'MDarkelfFighter', 'hp_table' => 'phantom_ranger',  'mp_table' => 'phantom_ranger',  'str' => 41, 'dex' => 34, 'con' => 32, 'int' => 25, 'wit' => 12, 'men' => 26],
            38 => ['name' => 'Dark Mystic',      'template' => 'MDarkelfMagic',   'hp_table' => 'dark_mage',       'mp_table' => 'dark_mage',       'str' => 23, 'dex' => 23, 'con' => 24, 'int' => 44, 'wit' => 19, 'men' => 37],
            39 => ['name' => 'Dark Wizard',      'template' => 'MDarkelfMagic',   'hp_table' => 'dark_wizard',     'mp_table' => 'dark_wizard',     'str' => 23, 'dex' => 23, 'con' => 24, 'int' => 44, 'wit' => 19, 'men' => 37],
            40 => ['name' => 'Spellhowler',      'template' => 'MDarkelfMagic',   'hp_table' => 'spellhowler',     'mp_table' => 'spellhowler',     'str' => 23, 'dex' => 23, 'con' => 24, 'int' => 44, 'wit' => 19, 'men' => 37],
            41 => ['name' => 'Phantom Summoner', 'template' => 'MDarkelfMagic',   'hp_table' => 'phantom_summoner','mp_table' => 'phantom_summoner','str' => 23, 'dex' => 23, 'con' => 24, 'int' => 44, 'wit' => 19, 'men' => 37],
            42 => ['name' => 'Shillien Oracle',  'template' => 'MDarkelfMagic',   'hp_table' => 'shillien_oracle', 'mp_table' => 'shillien_oracle', 'str' => 23, 'dex' => 23, 'con' => 24, 'int' => 44, 'wit' => 19, 'men' => 37],
            43 => ['name' => 'Shillien Elder',   'template' => 'MDarkelfMagic',   'hp_table' => 'shillien_elder',  'mp_table' => 'shillien_elder',  'str' => 23, 'dex' => 23, 'con' => 24, 'int' => 44, 'wit' => 19, 'men' => 37],

            // Орки
            44 => ['name' => 'Orc Fighter',      'template' => 'MOrcFighter', 'hp_table' => 'orc_fighter', 'mp_table' => 'orc_fighter', 'str' => 40, 'dex' => 26, 'con' => 47, 'int' => 18, 'wit' => 12, 'men' => 27],
            45 => ['name' => 'Orc Raider',       'template' => 'MOrcFighter', 'hp_table' => 'orc_raider',  'mp_table' => 'orc_raider',  'str' => 40, 'dex' => 26, 'con' => 47, 'int' => 18, 'wit' => 12, 'men' => 27],
            46 => ['name' => 'Destroyer',        'template' => 'MOrcFighter', 'hp_table' => 'destroyer',   'mp_table' => 'destroyer',   'str' => 40, 'dex' => 26, 'con' => 47, 'int' => 18, 'wit' => 12, 'men' => 27],
            47 => ['name' => 'Orc Monk',         'template' => 'MOrcFighter', 'hp_table' => 'orc_monk',     'mp_table' => 'orc_monk',     'str' => 40, 'dex' => 26, 'con' => 47, 'int' => 18, 'wit' => 12, 'men' => 27],
            48 => ['name' => 'Tyrant',           'template' => 'MOrcFighter', 'hp_table' => 'tyrant',       'mp_table' => 'tyrant',       'str' => 40, 'dex' => 26, 'con' => 47, 'int' => 18, 'wit' => 12, 'men' => 27],
            49 => ['name' => 'Orc Mystic',       'template' => 'MShaman',     'hp_table' => 'orc_mage',     'mp_table' => 'orc_mage',     'str' => 27, 'dex' => 24, 'con' => 31, 'int' => 31, 'wit' => 15, 'men' => 42],
            50 => ['name' => 'Orc Shaman',       'template' => 'MShaman',     'hp_table' => 'orc_shaman',   'mp_table' => 'orc_shaman',   'str' => 27, 'dex' => 24, 'con' => 31, 'int' => 31, 'wit' => 15, 'men' => 42],
            51 => ['name' => 'Overlord',         'template' => 'MShaman',     'hp_table' => 'overlord',     'mp_table' => 'overlord',     'str' => 27, 'dex' => 24, 'con' => 31, 'int' => 31, 'wit' => 15, 'men' => 42],
            52 => ['name' => 'Warcryer',         'template' => 'MShaman',     'hp_table' => 'warcryer',     'mp_table' => 'warcryer',     'str' => 27, 'dex' => 24, 'con' => 31, 'int' => 31, 'wit' => 15, 'men' => 42],

            // Гномы
            53 => ['name' => 'Dwarven Fighter',  'template' => 'MDwarfFighter', 'hp_table' => 'dwarven_fighter', 'mp_table' => 'dwarven_fighter', 'str' => 39, 'dex' => 29, 'con' => 45, 'int' => 20, 'wit' => 10, 'men' => 27],
            54 => ['name' => 'Scavenger',        'template' => 'MDwarfFighter', 'hp_table' => 'scavenger',       'mp_table' => 'scavenger',       'str' => 39, 'dex' => 29, 'con' => 45, 'int' => 20, 'wit' => 10, 'men' => 27],
            55 => ['name' => 'Bounty Hunter',    'template' => 'MDwarfFighter', 'hp_table' => 'bounty_hunter',   'mp_table' => 'bounty_hunter',   'str' => 39, 'dex' => 29, 'con' => 45, 'int' => 20, 'wit' => 10, 'men' => 27],
            56 => ['name' => 'Artisan',          'template' => 'MDwarfFighter', 'hp_table' => 'artisan',         'mp_table' => 'artisan',         'str' => 39, 'dex' => 29, 'con' => 45, 'int' => 20, 'wit' => 10, 'men' => 27],
            57 => ['name' => 'Warsmith',         'template' => 'MDwarfFighter', 'hp_table' => 'warsmith',        'mp_table' => 'warsmith',        'str' => 39, 'dex' => 29, 'con' => 45, 'int' => 20, 'wit' => 10, 'men' => 27],
        ];
    }

    /**
     * Парсинг дерева изучения умений (Skill Acquire):
     * php yii pts/skill-acquire
     */
    public function actionSkillAcquire(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/skillacquire.txt');
        $skillsPath = Yii::getAlias('@app/data/game/skills.php');
        $itemsPath = Yii::getAlias('@app/data/game/items.php');
        $outputDir = Yii::getAlias('@app/data/game');
        $outputFile = $outputDir . '/skill_trees.php';

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!file_exists($skillsPath)) {
            $this->stderr("Ошибка: сначала выполните php yii pts/skills!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // 1. Создаем обратную карту скиллов: system_name -> ['id' => X, 'level' => Y]
        $this->stdout("Индексация базы скиллов...\n");
        $skillsData = require $skillsPath;
        $nameToSkill = [];
        foreach ($skillsData as $skillId => $levels) {
            foreach ($levels as $lvl => $info) {
                $nameToSkill[$info['name']] = [
                    'id'    => $skillId,
                    'level' => $lvl,
                ];
            }
        }

        // 2. Создаем карту книг/предметов: system_name -> item_id
        $itemNameToId = [];
        if (file_exists($itemsPath)) {
            $itemsData = require $itemsPath;
            foreach ($itemsData as $itemId => $itemRow) {
                $itemNameToId[$itemRow['name']] = $itemId;
            }
        }

        $this->stdout("Чтение файла skillacquire.txt...\n");
        $content = file_get_contents($inputPath);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        // Словарь названий блоков в ID классов C4
        $classNameToId = $this->getClassBlockMap();

        $rawBlocks = [];
        $currentBlock = null;

        // 3. Первичный сбор блоков классов и их умений
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            // Начало секции класса: xxx_begin
            if (preg_match('/^([a-zA-Z0-9_]+)_begin$/', $line, $m)) {
                $currentBlock = strtolower($m[1]);
                $rawBlocks[$currentBlock] = [
                    'includes' => [],
                    'skills'   => [],
                ];
                continue;
            }

            // Конец секции: xxx_end
            if (preg_match('/^([a-zA-Z0-9_]+)_end$/', $line, $m)) {
                $currentBlock = null;
                continue;
            }

            if ($currentBlock === null) {
                continue;
            }

            // Директива наследования: include_xxx
            if (preg_match('/^include_([a-zA-Z0-9_]+)$/', $line, $mInc)) {
                $rawBlocks[$currentBlock]['includes'][] = strtolower($mInc[1]);
                continue;
            }

            // Строка скилла: skill_begin ... skill_end
            if (str_contains($line, 'skill_begin')) {
                preg_match('/skill_name\s*=\s*\[([^\]]+)\]/', $line, $mName);
                preg_match('/get_lv\s*=\s*(\d+)/', $line, $mLv);
                preg_match('/lv_up_sp\s*=\s*(\d+)/', $line, $mSp);
                preg_match('/auto_get\s*=\s*(true|false)/i', $line, $mAuto);

                if (empty($mName[1])) {
                    continue;
                }

                $skillSysName = $mName[1];
                $skillRef = $nameToSkill[$skillSysName] ?? null;

                // Парсинг требуемой книги (например, item_needed = {{[sb_drain_energy1];1}})
                $itemNeeded = null;
                if (preg_match('/\[([^\]]+)\];(\d+)/', $line, $mItem)) {
                    $itemSysName = $mItem[1];
                    $itemNeeded = [
                        'item_id' => $itemNameToId[$itemSysName] ?? null,
                        'name'    => $itemSysName,
                        'count'   => (int)$mItem[2],
                    ];
                }

                $rawBlocks[$currentBlock]['skills'][$skillSysName] = [
                    'skill_id'    => $skillRef['id'] ?? null,
                    'level'       => $skillRef['level'] ?? 1,
                    'skill_name'  => $skillSysName,
                    'req_level'   => (int)($mLv[1] ?? 1),
                    'sp'          => (int)($mSp[1] ?? 0),
                    'auto_get'    => strtolower($mAuto[1] ?? 'false') === 'true',
                    'item_needed' => $itemNeeded,
                ];
            }
        }

        // 4. Рекурсивное разрешение наследования (include_xxx) для каждого класса
        $this->stdout("Разрешение наследования классов (include_*)...\n");
        $finalTrees = [];
        $totalEntries = 0;

        foreach ($rawBlocks as $blockName => $blockData) {
            $classId = $classNameToId[$blockName] ?? null;
            if ($classId === null) {
                continue;
            }

            $resolvedSkills = $this->resolveClassSkills($blockName, $rawBlocks);

            // Сортируем скиллы по требуемому уровню (req_level)
            usort($resolvedSkills, fn($a, $b) => $a['req_level'] <=> $b['req_level']);

            $finalTrees[$classId] = $resolvedSkills;
            $totalEntries += count($resolvedSkills);
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->stdout("Сохранение в {$outputFile}...\n");
        $exportedArray = VarDumper::export($finalTrees);
        $fileContent = "<?php\ndeclare(strict_types=1);\n\n// Автоматически сгенерировано из skillacquire.txt (L2OFF PTS)\nreturn {$exportedArray};\n";
        file_put_contents($outputFile, $fileContent);

        $this->stdout("Успешно! Деревья скиллов сформированы для " . count($finalTrees) . " классов (всего доступных умений: {$totalEntries}).\n");
        return ExitCode::OK;
    }

    /**
     * Рекурсивное раскрытие цепочки наследования классов
     */
    private function resolveClassSkills(string $className, array &$rawBlocks, array $visited = []): array
    {
        if (!isset($rawBlocks[$className]) || isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $skillsMap = [];

        // Сначала собираем скиллы базовых родителей
        foreach ($rawBlocks[$className]['includes'] as $parentClass) {
            $parentSkills = $this->resolveClassSkills($parentClass, $rawBlocks, $visited);
            foreach ($parentSkills as $s) {
                $skillsMap[$s['skill_name']] = $s;
            }
        }

        // Затем накладываем скиллы текущего класса
        foreach ($rawBlocks[$className]['skills'] as $s) {
            $skillsMap[$s['skill_name']] = $s;
        }

        return array_values($skillsMap);
    }

    /**
     * Маппинг имен блоков PTS на числовые ID классов C4
     */
    private function getClassBlockMap(): array
    {
        return [
            // Люди-воины
            'fighter'            => 0,
            'warrior'            => 1,
            'gladiator'          => 2,
            'warlord'            => 3,
            'knight'             => 4,
            'paladin'            => 5,
            'dark_avenger'       => 6,
            'rogue'              => 7,
            'treasure_hunter'    => 8,
            'hawkeye'            => 9,
            // Люди-маги
            'mage'               => 10,
            'wizard'             => 11,
            'sorcerer'           => 12,
            'necromancer'        => 13,
            'warlock'            => 14,
            'cleric'             => 15,
            'bishop'             => 16,
            'prophet'            => 17,
            // Эльфы
            'elven_fighter'      => 18,
            'elven_knight'       => 19,
            'temple_knight'      => 20,
            'swordsinger'        => 21,
            'elven_scout'        => 22,
            'plain_walker'       => 23,
            'silver_ranger'      => 24,
            'elven_mage'         => 25,
            'elven_wizard'       => 26,
            'spellsinger'        => 27,
            'elemental_summoner' => 28,
            'oracle'             => 29,
            'elder'              => 30,
            // Темные Эльфы
            'dark_fighter'       => 31,
            'palus_knight'       => 32,
            'shillien_knight'    => 33,
            'bladedancer'        => 34,
            'assasin'            => 35,
            'assassin'           => 35,
            'abyss_walker'       => 36,
            'phantom_ranger'     => 37,
            'dark_mage'          => 38,
            'dark_wizard'        => 39,
            'spellhowler'        => 40,
            'phantom_summoner'   => 41,
            'shillien_oracle'    => 42,
            'shillien_elder'     => 43,
            // Орки
            'orc_fighter'        => 44,
            'orc_raider'         => 45,
            'destroyer'          => 46,
            'orc_monk'           => 47,
            'tyrant'             => 48,
            'orc_mage'           => 49,
            'orc_shaman'         => 50,
            'overlord'           => 51,
            'warcryer'           => 52,
            // Гномы
            'dwarven_fighter'    => 53,
            'scavenger'          => 54,
            'bounty_hunter'      => 55,
            'artisan'            => 56,
            'warsmith'           => 57,
        ];
    }

    /**
     * Генерация локаций с популяцией мобов и файла локализации:
     * php yii pts/locations
     */
    public function actionLocations(): int
    {
        $npcposPath = Yii::getAlias('@app/data/pts/npcpos.txt');
        $npcsPath = Yii::getAlias('@app/data/game/npcs.php');

        $outputDataFile = Yii::getAlias('@app/data/game/locations.php');
        $outputMessagesDir = Yii::getAlias('@app/messages/ru');
        $outputMessagesFile = $outputMessagesDir . '/locations.php';

        if (!file_exists($npcposPath)) {
            $this->stderr("Ошибка: файл {$npcposPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!file_exists($npcsPath)) {
            $this->stderr("Ошибка: сначала выполните php yii pts/npcs!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Загрузка базы NPC и монстров...\n");
        $npcsData = require $npcsPath;
        $nameToNpc = [];
        foreach ($npcsData as $npcId => $npcRow) {
            $cleanName = strtolower(trim((string)$npcRow['name']));
            $hasLoot = !empty($npcRow['drops']) || !empty($npcRow['spoils']);

            $nameToNpc[$cleanName] = [
                'id'       => $npcId,
                'name'     => $cleanName,
                'type'     => $npcRow['type'] ?? 'citizen',
                'level'    => $npcRow['level'] ?? 1,
                'has_loot' => $hasLoot,
            ];
        }

        $definitions = $this->getWorldZoneDefinitions();

        $this->stdout("Чтение npcpos.txt...\n");
        $content = file_get_contents($npcposPath);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $territories = [];
        $currentMakerTerritory = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            if (str_contains($line, 'territory_begin')) {
                if (preg_match('/territory_begin\s+\[([^\]]+)\]/', $line, $mTerr)) {
                    $tName = strtolower(trim($mTerr[1]));
                    if (preg_match_all('/\{(-?\d+);(-?\d+);-?\d+;-?\d+\}/', $line, $pts, PREG_SET_ORDER)) {
                        $sumX = 0;
                        $sumY = 0;
                        foreach ($pts as $pt) {
                            $sumX += (float)$pt[1];
                            $sumY += (float)$pt[2];
                        }
                        $territories[$tName] = [$sumX / count($pts), $sumY / count($pts)];
                    }
                }
                continue;
            }

            if (str_contains($line, 'npcmaker_begin')) {
                if (preg_match('/npcmaker_begin\s+\[([^\]]+)\]/', $line, $mMaker)) {
                    $currentMakerTerritory = strtolower(trim($mMaker[1]));
                }
                continue;
            }

            if (str_contains($line, 'npcmaker_end')) {
                $currentMakerTerritory = null;
                continue;
            }

            if (str_contains($line, 'npc_begin')) {
                preg_match('/npc_begin\s+\[([^\]]+)\]/', $line, $mName);
                if (empty($mName[1])) {
                    continue;
                }

                $npcSysName = strtolower(trim($mName[1]));
                $npcInfo = $nameToNpc[$npcSysName] ?? null;
                if ($npcInfo === null) {
                    continue;
                }

                // Извлекаем количество (total)
                $totalCount = 1;
                if (preg_match('/total\s*=\s*(\d+)/', $line, $mTot)) {
                    $totalCount = (int)$mTot[1];
                }

                $x = null;
                $y = null;
                if (preg_match('/pos\s*=\s*\{+([-\d]+);([-\d]+);/', $line, $mPos)) {
                    $x = (float)$mPos[1];
                    $y = (float)$mPos[2];
                } elseif ($currentMakerTerritory !== null && isset($territories[$currentMakerTerritory])) {
                    [$x, $y] = $territories[$currentMakerTerritory];
                }

                if ($x === null || $y === null) {
                    continue;
                }

                $zoneId = $this->resolveZoneForPoint($x, $y, $definitions);
                if ($zoneId === null) {
                    continue;
                }

                $isMonster = $this->isMonsterNpc($npcInfo, $definitions[$zoneId]['is_peace']);

                if ($isMonster) {
                    // Суммируем популяцию мобов данного вида в локации
                    $mId = $npcInfo['id'];
                    $definitions[$zoneId]['monsters'][$mId] = ($definitions[$zoneId]['monsters'][$mId] ?? 0) + $totalCount;
                } else {
                    if (!in_array($npcInfo['id'], $definitions[$zoneId]['npcs'], true)) {
                        $definitions[$zoneId]['npcs'][] = $npcInfo['id'];
                    }
                }
            }
        }

        // Подготовка чистых данных и файла переводов
        $gameLocations = [];
        $messagesRu = [
            'regions' => [],
            'locations' => [],
        ];

        foreach ($definitions as $id => $zone) {
            $levels = [];
            foreach (array_keys($zone['monsters']) as $mId) {
                if (isset($npcsData[$mId])) {
                    $levels[] = $npcsData[$mId]['level'];
                }
            }

            $minLvl = $zone['is_peace'] ? 1 : (!empty($levels) ? min($levels) : 1);
            $maxLvl = $zone['is_peace'] ? 20 : (!empty($levels) ? max($levels) : $minLvl);

            // Сортируем монстров по уровню от слабых к сильным
            uksort($zone['monsters'], function ($a, $b) use ($npcsData) {
                return ($npcsData[$a]['level'] ?? 0) <=> ($npcsData[$b]['level'] ?? 0);
            });
            sort($zone['npcs']);

            // 1. В data/game/locations.php (ТОЛЬКО ДАННЫЕ И ЦИФРЫ)
            $gameLocations[$id] = [
                'id'         => $id,
                'region_id'  => $zone['region_id'],
                'respawn_id' => $zone['respawn_id'],
                'type'       => $zone['type'],
                'is_peace'   => $zone['is_peace'],
                'min_level'  => $minLvl,
                'max_level'  => $maxLvl,
                'connected'  => array_keys($zone['connected']),
                'teleports'  => $zone['teleports'],
                'npcs'       => $zone['npcs'],
                'monsters'   => $zone['monsters'], // [20003 => 30, 20006 => 12...]
            ];

            // 2. В messages/ru/locations.php (ВСЕ РУССКИЕ СТРОКИ)
            $messagesRu['regions'][$zone['region_id']] = $zone['region_name'];
            $messagesRu['locations'][$id] = [
                'name' => $zone['name'],
                'desc' => $zone['description'],
            ];
        }

        // Сохранение data/game/locations.php
        $this->stdout("Сохранение в {$outputDataFile}...\n");
        $exportData = VarDumper::export($gameLocations);
        file_put_contents($outputDataFile, "<?php\ndeclare(strict_types=1);\n\n// Карта мира Lineage 2 C4\nreturn {$exportData};\n");

        // Сохранение messages/ru/locations.php
        if (!is_dir($outputMessagesDir)) {
            mkdir($outputMessagesDir, 0777, true);
        }
        $this->stdout("Сохранение локализации в {$outputMessagesFile}...\n");
        $exportMessages = VarDumper::export($messagesRu);
        file_put_contents($outputMessagesFile, "<?php\ndeclare(strict_types=1);\n\n// Локализация RU\nreturn {$exportMessages};\n");

        $this->stdout("Готово! Карта мира с весами мобов сгенерирована!\n");
        return ExitCode::OK;
    }

    private function getWorldZoneDefinitions(): array
    {
        return [
            // =========================================================================
            // 1. ОСТРОВ ГОВОРЯЩЕГО (TALKING ISLAND)
            // =========================================================================
            'ti_town' => [
                'name' => 'Деревня Говорящего Острова',
                'region_id' => 'talking_island', 'region_name' => 'Говорящий Остров',
                'respawn_id' => 'ti_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Уютное поселение людей, окруженное каменными стенами.',
                'bounds' => ['x' => [-88000, -80000], 'y' => [239000, 247000]],
                'connected' => ['ti_fields_north' => true, 'ti_fields_south' => true, 'ti_harbor' => true],
                'teleports' => ['gludin_town' => 1500],
                'npcs' => [], 'monsters' => [],
            ],
            'ti_harbor' => [
                'name' => 'Гавань Говорящего Острова',
                'region_id' => 'talking_island', 'region_name' => 'Говорящий Остров',
                'respawn_id' => 'ti_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Портовый причал, откуда отплывают корабли на континент.',
                'bounds' => ['x' => [-100000, -90000], 'y' => [250000, 263000]],
                'connected' => ['ti_town' => true],
                'teleports' => ['gludin_harbor' => 1000],
                'npcs' => [], 'monsters' => [],
            ],
            'ti_fields_north' => [
                'name' => 'Северные окрестности Острова',
                'region_id' => 'talking_island', 'region_name' => 'Говорящий Остров',
                'respawn_id' => 'ti_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Зеленые поля, где пасутся молодые кельтиры, волки и гремлины.',
                'bounds' => ['x' => [-131072, -65536], 'y' => [196608, 240000]],
                'connected' => ['ti_town' => true, 'ti_elven_ruins' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ti_fields_south' => [
                'name' => 'Южные холмы и Обелиск Победы',
                'region_id' => 'talking_island', 'region_name' => 'Говорящий Остров',
                'respawn_id' => 'ti_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Холмистая местность, где обитают пауки, орки и оборотни.',
                'bounds' => ['x' => [-131072, -65536], 'y' => [240000, 265000]],
                'connected' => ['ti_town' => true, 'ti_waterfall' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ti_waterfall' => [
                'name' => 'Поющий Водопад',
                'region_id' => 'talking_island', 'region_name' => 'Говорящий Остров',
                'respawn_id' => 'ti_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Живописный водопад на юге острова. Место обитания гигантских пауков.',
                'bounds' => ['x' => [-76000, -65000], 'y' => [250000, 264000]],
                'connected' => ['ti_fields_south' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ti_elven_ruins' => [
                'name' => 'Эльфийские Руины (Подземелье)',
                'region_id' => 'talking_island', 'region_name' => 'Говорящий Остров',
                'respawn_id' => 'ti_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Подземные залы древнего эльфийского святилища, заполоненные скелетами и зомби.',
                'bounds' => ['x' => [-125000, -110000], 'y' => [230000, 255000]],
                'connected' => ['ti_fields_north' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 2. ГЛУДИН И ОКРЕСТНОСТИ (GLUDIN)
            // =========================================================================
            'gludin_town' => [
                'name' => 'Деревня Глудин',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Крупный портовый город на западном берегу материка.',
                'bounds' => ['x' => [-86000, -77000], 'y' => [148000, 157500]],
                'connected' => ['gludin_harbor' => true, 'gludin_arena' => true, 'windmill_hill' => true],
                'teleports' => ['gludio_town' => 3000, 'ti_town' => 1500],
                'npcs' => [], 'monsters' => [],
            ],
            'gludin_harbor' => [
                'name' => 'Гавань Глудина',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Морские ворота континента. Отсюда корабли ходят на Говорящий Остров.',
                'bounds' => ['x' => [-98000, -86000], 'y' => [146000, 156000]],
                'connected' => ['gludin_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'gludin_arena' => [
                'name' => 'Арена Глудина (PvP)',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'arena', 'is_peace' => false,
                'description' => 'Боевая гладиаторская арена Глудина.',
                'bounds' => ['x' => [-89500, -86500], 'y' => [141000, 143800]],
                'connected' => ['gludin_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'windmill_hill' => [
                'name' => 'Холм Ветряных Мельниц',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Холмы за Глудином, где расположены фермы и бродят пауки и ящеры.',
                'bounds' => ['x' => [-90000, -68000], 'y' => [130000, 148000]],
                'connected' => ['gludin_town' => true, 'abandoned_camp' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'langk_lizardmen' => [
                'name' => 'Поселение Ящеров Лангк',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Песчаное побережье к югу от Глудина, занятое племенем ящеров Лангк.',
                'bounds' => ['x' => [-98000, -68000], 'y' => [157500, 185000]],
                'connected' => ['gludin_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'abandoned_camp' => [
                'name' => 'Заброшенный Лагерь',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Военный форпост, захваченный свирепыми орками Турек.',
                'bounds' => ['x' => [-68000, -40000], 'y' => [140000, 160000]],
                'connected' => ['windmill_hill' => true, 'gludio_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'forgotten_temple' => [
                'name' => 'Забытый Храм (Подземелье)',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Древний подземный лабиринт, разделенный на огненную и водную половины.',
                'bounds' => ['x' => [-58000, -42000], 'y' => [165000, 182000]],
                'connected' => ['windmill_hill' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'wasteland' => [
                'name' => 'Пустошь',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Выжженная магией красная пустыня, полная гигантских скорпионов и муравьев.',
                'bounds' => ['x' => [-42000, -8000], 'y' => [155000, 205000]],
                'connected' => ['gludin_town' => true, 'ant_nest' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ant_nest' => [
                'name' => 'Муравейник (Пещера)',
                'region_id' => 'gludin', 'region_name' => 'Глудин',
                'respawn_id' => 'gludin_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Глубокие подземные туннели, ведущие в тронный зал Королевы Муравьев (Queen Ant).',
                'bounds' => ['x' => [-28000, -12000], 'y' => [170000, 188000]],
                'connected' => ['wasteland' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 3. ГЛУДИО И ОКРЕСТНОСТИ (GLUDIO)
            // =========================================================================
            'gludio_town' => [
                'name' => 'Город Глудио',
                'region_id' => 'gludio', 'region_name' => 'Глудио',
                'respawn_id' => 'gludio_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Укрепленный замок-город в центре западных равнин.',
                'bounds' => ['x' => [-18000, -10000], 'y' => [120000, 128500]],
                'connected' => ['ruins_of_despair' => true, 'ruins_of_agony' => true, 'gludio_castle' => true],
                'teleports' => ['gludin_town' => 3000, 'dion_town' => 4500],
                'npcs' => [], 'monsters' => [],
            ],
            'gludio_castle' => [
                'name' => 'Замок Глудио',
                'region_id' => 'gludio', 'region_name' => 'Глудио',
                'respawn_id' => 'gludio_town', 'type' => 'siege_zone', 'is_peace' => false,
                'description' => 'Величественная цитадель правителей Глудио.',
                'bounds' => ['x' => [-28000, -14000], 'y' => [104000, 118500]],
                'connected' => ['gludio_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ruins_of_despair' => [
                'name' => 'Руины Отчаяния',
                'region_id' => 'gludio', 'region_name' => 'Глудио',
                'respawn_id' => 'gludio_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Разрушенная деревня, проклятая темными некромантами. Полна нежити.',
                'bounds' => ['x' => [-28000, -8000], 'y' => [132000, 155000]],
                'connected' => ['gludio_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ruins_of_agony' => [
                'name' => 'Руины Страданий',
                'region_id' => 'gludio', 'region_name' => 'Глудио',
                'respawn_id' => 'gludio_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Обугленные руины древней крепости, населенные скелетами и летучими мышами.',
                'bounds' => ['x' => [-12000, 12000], 'y' => [104000, 122000]],
                'connected' => ['gludio_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 4. ДИОН И ОКРЕСТНОСТИ (DION)
            // =========================================================================
            'dion_town' => [
                'name' => 'Город Дион',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Столица аграрных угодий с замком и знаменитыми холмами.',
                'bounds' => ['x' => [13000, 23000], 'y' => [140000, 149000]],
                'connected' => ['execution_grounds' => true, 'cruma_marshlands' => true, 'floran_village' => true, 'dion_castle' => true],
                'teleports' => ['gludio_town' => 4500, 'giran_town' => 6000],
                'npcs' => [], 'monsters' => [],
            ],
            'dion_castle' => [
                'name' => 'Замок Дион',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'siege_zone', 'is_peace' => false,
                'description' => 'Оборонительная цитадель графа Диона.',
                'bounds' => ['x' => [18000, 31000], 'y' => [151000, 166000]],
                'connected' => ['dion_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'floran_village' => [
                'name' => 'Деревня Флоран',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'floran_village', 'type' => 'town', 'is_peace' => true,
                'description' => 'Деревня без стражников, где находят убежище персонажи с кармой (ПК).',
                'bounds' => ['x' => [15000, 20000], 'y' => [168000, 173000]],
                'connected' => ['dion_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'execution_grounds' => [
                'name' => 'Земля Казненных',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Мрачное ущелье с виселицами и гильотинами. Родина призраков и каторжников.',
                'bounds' => ['x' => [25000, 44000], 'y' => [136000, 158000]],
                'connected' => ['dion_town' => true, 'giran_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'partisan_hideaway' => [
                'name' => 'Убежище Партизан',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Лагерь наемников Ол Махум и лорда Нурки.',
                'bounds' => ['x' => [41000, 54000], 'y' => [105000, 118000]],
                'connected' => ['dion_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'cruma_marshlands' => [
                'name' => 'Топи Крумы',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Ядовитые болота вокруг циклопической древней башни Крумы.',
                'bounds' => ['x' => [6000, 26000], 'y' => [105000, 134000]],
                'connected' => ['dion_town' => true, 'cruma_tower_1f' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'cruma_tower_1f' => [
                'name' => 'Башня Крумы (1-й этаж)',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Подземные залы древних Титанов. Механические големы и монстры 40-45 уровней.',
                'bounds' => ['x' => [14000, 22000], 'y' => [110000, 118000]],
                'connected' => ['cruma_marshlands' => true, 'cruma_tower_2f' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'cruma_tower_2f' => [
                'name' => 'Башня Крумы (2-й этаж)',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Лаборатории башни. Обитель опасных големов и парящих магов (45-50 ур.).',
                'bounds' => ['x' => [14500, 21500], 'y' => [110500, 117500]],
                'connected' => ['cruma_tower_1f' => true, 'cruma_tower_3f' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'cruma_tower_3f' => [
                'name' => 'Башня Крумы (Логово Ядра / Core)',
                'region_id' => 'dion', 'region_name' => 'Дион',
                'respawn_id' => 'dion_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Сердце древнего суперкомпьютера титанов. Здесь обитает рейд-босс Ядро (Core 50 ур.).',
                'bounds' => ['x' => [15500, 20000], 'y' => [111500, 115000]],
                'connected' => ['cruma_tower_2f' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 5. ГИРАН И ОКРЕСТНОСТИ (GIRAN)
            // =========================================================================
            'giran_town' => [
                'name' => 'Город Гиран',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Главный торговый мегаполис всего континента Аден с огромной рыночной площадью.',
                'bounds' => ['x' => [76000, 90000], 'y' => [142000, 155500]],
                'connected' => ['giran_arena' => true, 'brekas_stronghold' => true, 'death_pass' => true, 'giran_castle' => true],
                'teleports' => ['dion_town' => 6000, 'oren_town' => 7500, 'aden_town' => 10000, 'heine_town' => 6500],
                'npcs' => [], 'monsters' => [],
            ],
            'giran_castle' => [
                'name' => 'Замок Гиран',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'siege_zone', 'is_peace' => false,
                'description' => 'Самый богатый замок в королевстве.',
                'bounds' => ['x' => [107000, 122000], 'y' => [135000, 150000]],
                'connected' => ['giran_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'giran_arena' => [
                'name' => 'Арена Гирана (PvP)',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'arena', 'is_peace' => false,
                'description' => 'Боевая гладиаторская арена города Гиран.',
                'bounds' => ['x' => [71000, 75000], 'y' => [141000, 144500]],
                'connected' => ['giran_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'brekas_stronghold' => [
                'name' => 'Укрепление Орков Брека',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Лесистая равнина к северу от Гирана, занятая лагерями орков Брека (32-38 ур.).',
                'bounds' => ['x' => [70000, 90000], 'y' => [118000, 140000]],
                'connected' => ['giran_town' => true, 'death_pass' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'death_pass' => [
                'name' => 'Долина Смерти (Death Pass)',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Глубокий каньон, кишащий вивернами, гаргульями и нежитью (35-45 ур.).',
                'bounds' => ['x' => [62000, 88000], 'y' => [106000, 126000]],
                'connected' => ['giran_town' => true, 'dragon_valley' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'dragon_valley' => [
                'name' => 'Долина Драконов (Dragon Valley)',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Легендарное ущелье драконов. Пещерные стражи, драконы и виверны (45-55 ур.).',
                'bounds' => ['x' => [92000, 126000], 'y' => [102000, 128000]],
                'connected' => ['death_pass' => true, 'antharas_lair' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'antharas_lair' => [
                'name' => 'Логово Антараса (Antharas Lair)',
                'region_id' => 'giran', 'region_name' => 'Гиран',
                'respawn_id' => 'giran_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Глубочайшее подземелье Земляного Дракона Антараса (75+ ур.). Опаснейшее место в C4.',
                'bounds' => ['x' => [124000, 146000], 'y' => [108000, 128000]],
                'connected' => ['dragon_valley' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 6. ОРЕН И ОКРЕСТНОСТИ (OREN)
            // =========================================================================
            'oren_town' => [
                'name' => 'Город Орен',
                'region_id' => 'oren', 'region_name' => 'Орен',
                'respawn_id' => 'oren_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Северная заснеженная крепость на границе с опасными лесами.',
                'bounds' => ['x' => [77000, 86000], 'y' => [50000, 59000]],
                'connected' => ['ivory_tower' => true, 'plains_of_lizardmen' => true, 'oren_castle' => true],
                'teleports' => ['giran_town' => 7500, 'aden_town' => 7000],
                'npcs' => [], 'monsters' => [],
            ],
            'oren_castle' => [
                'name' => 'Замок Орен',
                'region_id' => 'oren', 'region_name' => 'Орен',
                'respawn_id' => 'oren_town', 'type' => 'siege_zone', 'is_peace' => false,
                'description' => 'Неприступная северная твердыня.',
                'bounds' => ['x' => [74000, 88000], 'y' => [28000, 42000]],
                'connected' => ['oren_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ivory_tower' => [
                'name' => 'Башня Слоновой Кости (Ivory Tower)',
                'region_id' => 'oren', 'region_name' => 'Орен',
                'respawn_id' => 'oren_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Высокая башня могущественных магов, парящая посреди гигантского кратера.',
                'bounds' => ['x' => [83500, 87500], 'y' => [14500, 18000]],
                'connected' => ['oren_town' => true, 'ivory_crater' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'ivory_crater' => [
                'name' => 'Кратер Башни Слоновой Кости',
                'region_id' => 'oren', 'region_name' => 'Орен',
                'respawn_id' => 'oren_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Глубокий котлован с магическими монстрами, горгульями и големами (40-48 ур.).',
                'bounds' => ['x' => [76000, 94000], 'y' => [8000, 26000]],
                'connected' => ['ivory_tower' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'sea_of_spores' => [
                'name' => 'Море Спор (Sea of Spores)',
                'region_id' => 'oren', 'region_name' => 'Орен',
                'respawn_id' => 'oren_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Зона магической катастрофы, окруженная силовым куполом. Родина босса Орфен (Orfen).',
                'bounds' => ['x' => [36000, 68000], 'y' => [8000, 34000]],
                'connected' => ['oren_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'plains_of_lizardmen' => [
                'name' => 'Долина Ящеров Лето',
                'region_id' => 'oren', 'region_name' => 'Орен',
                'respawn_id' => 'oren_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Бескрайние степи к югу от Орена, занятые сильным племенем ящеров Лето (48-55 ур.).',
                'bounds' => ['x' => [72000, 98000], 'y' => [62000, 100000]],
                'connected' => ['oren_town' => true, 'hunters_village' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 7. СТОЛИЦА АДЕН И ЭНДГЕЙМ-ЗОНЫ (ADEN)
            // =========================================================================
            'aden_town' => [
                'name' => 'Город Аден',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Величественная столица всей империи Lineage 2.',
                'bounds' => ['x' => [142000, 153000], 'y' => [19000, 33000]],
                'connected' => ['coliseum' => true, 'cemetery' => true, 'aden_castle' => true, 'toi_entrance' => true],
                'teleports' => ['giran_town' => 10000, 'oren_town' => 7000],
                'npcs' => [], 'monsters' => [],
            ],
            'aden_castle' => [
                'name' => 'Замок Аден',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'siege_zone', 'is_peace' => false,
                'description' => 'Императорский замок, венчающий владычество над миром.',
                'bounds' => ['x' => [141000, 154000], 'y' => [200, 17000]],
                'connected' => ['aden_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'coliseum' => [
                'name' => 'Великий Колизей Адена (PvP)',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'arena', 'is_peace' => false,
                'description' => 'Огромный амфитеатр для гладиаторских турниров и дуэлей.',
                'bounds' => ['x' => [144000, 154500], 'y' => [42000, 52000]],
                'connected' => ['aden_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'cemetery' => [
                'name' => 'Кладбище Королей (Cemetery)',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Древние склепы аденских правителей. Обитель сильной нежити 50-58 уровней.',
                'bounds' => ['x' => [152000, 180000], 'y' => [12000, 38000]],
                'connected' => ['aden_town' => true, 'blazing_swamp' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'blazing_swamp' => [
                'name' => 'Раскаленные Топи (Blazing Swamp)',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Кипящие лавовые гейзеры и огненные твари (65-75 ур.).',
                'bounds' => ['x' => [135000, 175000], 'y' => [-30000, 5000]],
                'connected' => ['cemetery' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'silent_valley' => [
                'name' => 'Безмолвная Долина (Silent Valley)',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Ущелье гигантов к востоку от Адена (70-75 ур.).',
                'bounds' => ['x' => [165000, 200000], 'y' => [32000, 65000]],
                'connected' => ['aden_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'toi_entrance' => [
                'name' => 'Башня Дерзости (Нижние этажи)',
                'region_id' => 'aden', 'region_name' => 'Аден',
                'respawn_id' => 'aden_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Гигантская башня императора Баюма. Этажи с призраками и рыцарями (60-68 ур.).',
                'bounds' => ['x' => [106000, 124000], 'y' => [10000, 28000]],
                'connected' => ['aden_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 8. ДЕРЕВНЯ ОХОТНИКОВ (HUNTERS)
            // =========================================================================
            'hunters_village' => [
                'name' => 'Деревня Охотников',
                'region_id' => 'hunters', 'region_name' => 'Охотничьи Угодья',
                'respawn_id' => 'hunters_village', 'type' => 'town', 'is_peace' => true,
                'description' => 'Горный поселок следопытов и лучников среди крутых скал.',
                'bounds' => ['x' => [113000, 123500], 'y' => [72000, 82500]],
                'connected' => ['plains_of_lizardmen' => true, 'giran_town' => true],
                'teleports' => ['giran_town' => 6000, 'aden_town' => 6000],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 9. ХЕЙН / ИННАДРИЛ (INNADRIL)
            // =========================================================================
            'heine_town' => [
                'name' => 'Город Хейн',
                'region_id' => 'innadril', 'region_name' => 'Иннадрил',
                'respawn_id' => 'heine_town', 'type' => 'town', 'is_peace' => true,
                'description' => 'Прекрасный город на воде с каналами и мостами в землях Иннадрил.',
                'bounds' => ['x' => [105000, 117000], 'y' => [215000, 227000]],
                'connected' => ['alligator_island' => true, 'garden_of_eva' => true, 'innadril_castle' => true],
                'teleports' => ['giran_town' => 6500],
                'npcs' => [], 'monsters' => [],
            ],
            'innadril_castle' => [
                'name' => 'Замок Иннадрил',
                'region_id' => 'innadril', 'region_name' => 'Иннадрил',
                'respawn_id' => 'heine_town', 'type' => 'siege_zone', 'is_peace' => false,
                'description' => 'Водный замок правителей Иннадрила.',
                'bounds' => ['x' => [107000, 124000], 'y' => [236000, 255000]],
                'connected' => ['heine_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'alligator_island' => [
                'name' => 'Остров Аллигаторов',
                'region_id' => 'innadril', 'region_name' => 'Иннадрил',
                'respawn_id' => 'heine_town', 'type' => 'field', 'is_peace' => false,
                'description' => 'Тропический остров, кишащий крокодилами и болотными тварями (40-48 ур.).',
                'bounds' => ['x' => [102000, 128000], 'y' => [192000, 217000]],
                'connected' => ['heine_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'garden_of_eva' => [
                'name' => 'Сады Евы (Garden of Eva)',
                'region_id' => 'innadril', 'region_name' => 'Иннадрил',
                'respawn_id' => 'heine_town', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Затопленный подводный дворец богини Евы (42-52 ур.).',
                'bounds' => ['x' => [72000, 98000], 'y' => [242000, 268000]],
                'connected' => ['heine_town' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],

            // =========================================================================
            // 10. СТАРТОВЫЕ ПОСЕЛЕНИЯ ДРУГИХ РАС
            // =========================================================================
            'elven_village' => [
                'name' => 'Деревня Эльфов',
                'region_id' => 'elven_territory', 'region_name' => 'Эльфийские Земли',
                'respawn_id' => 'elven_village', 'type' => 'town', 'is_peace' => true,
                'description' => 'Деревня Перворожденных под сенью священного Материнского Древа.',
                'bounds' => ['x' => [41000, 49500], 'y' => [45000, 54500]],
                'connected' => ['elven_forest' => true],
                'teleports' => ['gludio_town' => 3500],
                'npcs' => [], 'monsters' => [],
            ],
            'elven_forest' => [
                'name' => 'Эльфийский Лес',
                'region_id' => 'elven_territory', 'region_name' => 'Эльфийские Земли',
                'respawn_id' => 'elven_village', 'type' => 'field', 'is_peace' => false,
                'description' => 'Густые священные чащи с духами, дриадами и пауками (1-18 ур.).',
                'bounds' => ['x' => [0, 65535], 'y' => [32768, 98303]],
                'connected' => ['elven_village' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'darkelf_village' => [
                'name' => 'Деревня Темных Эльфов',
                'region_id' => 'darkelven_territory', 'region_name' => 'Земли Темных Эльфов',
                'respawn_id' => 'darkelf_village', 'type' => 'town', 'is_peace' => true,
                'description' => 'Подземное поселение последователей богини Шилен.',
                'bounds' => ['x' => [6000, 17500], 'y' => [11000, 23000]],
                'connected' => ['darkelf_swamp' => true],
                'teleports' => ['gludio_town' => 3500],
                'npcs' => [], 'monsters' => [],
            ],
            'darkelf_swamp' => [
                'name' => 'Болота Темных Эльфов',
                'region_id' => 'darkelven_territory', 'region_name' => 'Земли Темных Эльфов',
                'respawn_id' => 'darkelf_village', 'type' => 'field', 'is_peace' => false,
                'description' => 'Мрачные туманные трясины с зомби и болотными хищниками (1-18 ур.).',
                'bounds' => ['x' => [-65536, 46000], 'y' => [0, 65535]],
                'connected' => ['darkelf_village' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'orc_village' => [
                'name' => 'Деревня Орков',
                'region_id' => 'orc_territory', 'region_name' => 'Земли Орков',
                'respawn_id' => 'orc_village', 'type' => 'town', 'is_peace' => true,
                'description' => 'Скалистая цитадель огнепоклонников Паагрио на морозном севере.',
                'bounds' => ['x' => [-56000, -39000], 'y' => [-119000, -107000]],
                'connected' => ['immortal_plateau' => true],
                'teleports' => ['gludio_town' => 5000],
                'npcs' => [], 'monsters' => [],
            ],
            'immortal_plateau' => [
                'name' => 'Плато Бессмертия',
                'region_id' => 'orc_territory', 'region_name' => 'Земли Орков',
                'respawn_id' => 'orc_village', 'type' => 'field', 'is_peace' => false,
                'description' => 'Суровые заснеженные горы и ущелья орков (1-18 ур.).',
                'bounds' => ['x' => [-65536, 32767], 'y' => [-163840, -65537]],
                'connected' => ['orc_village' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
            'dwarf_village' => [
                'name' => 'Деревня Гномов',
                'region_id' => 'dwarven_territory', 'region_name' => 'Земли Гномов',
                'respawn_id' => 'dwarf_village', 'type' => 'town', 'is_peace' => true,
                'description' => 'Подгорный город кузнецов, механиков и собирателей руды.',
                'bounds' => ['x' => [112000, 119000], 'y' => [-186000, -174000]],
                'connected' => ['dwarf_mines' => true],
                'teleports' => ['gludio_town' => 5000],
                'npcs' => [], 'monsters' => [],
            ],
            'dwarf_mines' => [
                'name' => 'Заброшенные Шахты Гномов',
                'region_id' => 'dwarven_territory', 'region_name' => 'Земли Гномов',
                'respawn_id' => 'dwarf_village', 'type' => 'dungeon', 'is_peace' => false,
                'description' => 'Штольни и рудники, кишащие летучими мышами и гоблинами (1-18 ур.).',
                'bounds' => ['x' => [98304, 196607], 'y' => [-229376, -163841]],
                'connected' => ['dwarf_village' => true],
                'teleports' => [],
                'npcs' => [], 'monsters' => [],
            ],
        ];
    }

    /**
     * Определение, является ли существо охотничьим монстром
     */
    private function isMonsterNpc(array $npcInfo, bool $isPeaceZone): bool
    {
        $name = $npcInfo['name'];
        $type = $npcInfo['type'];

        // 1. В мирных зонах (городах) все существа — это мирные жители, торговцы или стражники
        if ($isPeaceZone) {
            return false;
        }

        // 2. Явные системные типы мирных жителей L2OFF
        $peacefulTypes = [
            'citizen', 'merchant', 'teleporter', 'warehouse_keeper',
            'guild_master', 'guild_coach', 'blacksmith', 'guard',
            'package_keeper', 'doorman', 'chamberlain', 'holything'
        ];
        if (in_array($type, $peacefulTypes, true)) {
            return false;
        }

        // 3. Явные префиксы мирных NPC и стражи
        if (preg_match('/^(trader_|merchant_|gatekeeper_|teleporter_|warehouse_|master_|priest_|guard_|chamberlain_|herald_|doorman_|sir_|captain_|lady_|elder_|pet_manager_|symbol_maker_|news_|wh_)/i', $name)) {
            return false;
        }

        // 4. Наличие дропа или спойла — 100% признак моба для охоты
        if ($npcInfo['has_loot']) {
            return true;
        }

        // 5. Типы боевых существ
        if (in_array($type, ['warrior', 'monster', 'boss', 'treasure'], true)) {
            return true;
        }

        return false;
    }

    private function resolveZoneForPoint(float $x, float $y, array &$zones): ?string
    {
        $bestZone = null;
        $smallestArea = PHP_FLOAT_MAX;

        foreach ($zones as $id => $z) {
            $bounds = $z['bounds'];
            if ($x >= $bounds['x'][0] && $x <= $bounds['x'][1] && $y >= $bounds['y'][0] && $y <= $bounds['y'][1]) {
                $area = ($bounds['x'][1] - $bounds['x'][0]) * ($bounds['y'][1] - $bounds['y'][0]);
                if ($area < $smallestArea) {
                    $smallestArea = $area;
                    $bestZone = $id;
                }
            }
        }

        return $bestZone;
    }

    /**
     * Парсинг настроек старта персонажей (экипировка и стартовые локации):
     * php yii pts/settings
     */
    public function actionSettings(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/setting.txt');
        $itemsPath = Yii::getAlias('@app/data/game/items.php');
        $outputFile = Yii::getAlias('@app/data/game/settings.php');

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $itemNameToId = [];
        if (file_exists($itemsPath)) {
            $itemsData = require $itemsPath;
            foreach ($itemsData as $itemId => $row) {
                $itemNameToId[strtolower($row['name'])] = $itemId;
            }
        }

        $this->stdout("Чтение setting.txt...\n");
        $content = file_get_contents($inputPath);
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        unset($content);

        $initialEquipment = [];
        $isEquipBlock = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) continue;

            if (str_contains($line, 'initial_equipment_begin')) {
                $isEquipBlock = true;
                continue;
            }
            if (str_contains($line, 'initial_equipment_end')) {
                $isEquipBlock = false;
                continue;
            }

            if ($isEquipBlock && preg_match('/^([a-zA-Z0-9_]+)\s*=\s*\{([^\}]+)\}/', $line, $m)) {
                $className = strtolower(trim($m[1]));
                preg_match_all('/\[([^\]]+)\]/', $m[2], $items);

                $equipList = [];
                foreach ($items[1] as $itemSysName) {
                    $itemSysName = strtolower(trim($itemSysName));
                    $itemId = $itemNameToId[$itemSysName] ?? null;
                    if ($itemId !== null) {
                        $equipList[] = [
                            'item_id'     => $itemId,
                            'system_name' => $itemSysName,
                            'count'       => 1,
                        ];
                    }
                }
                $initialEquipment[$className] = $equipList;
            }
        }

        // Стартовые локации рас
        $startLocations = [
            'human_fighter'    => 'ti_town',
            'human_magician'   => 'ti_town',
            'elf_fighter'      => 'elven_village',
            'elf_magician'     => 'elven_village',
            'darkelf_fighter'  => 'darkelf_village',
            'darkelf_magician' => 'darkelf_village',
            'orc_fighter'      => 'orc_village',
            'orc_shaman'       => 'orc_village',
            'dwarf_apprentice' => 'dwarf_village',
        ];

        $result = [
            'initial_equipment' => $initialEquipment,
            'start_locations'   => $startLocations,
        ];

        $exported = VarDumper::export($result);
        file_put_contents($outputFile, "<?php\ndeclare(strict_types=1);\n\nreturn {$exported};\n");

        $this->stdout("Готово! Настройки сохранены в {$outputFile}\n");
        return ExitCode::OK;
    }

    /**
     * Парсинг магазинов, крафта и обмена (Multisell):
     * php yii pts/multisell
     */
    public function actionMultisell(): int
    {
        $inputPath = Yii::getAlias('@app/data/pts/multisell.txt');
        $itemsPath = Yii::getAlias('@app/data/game/items.php');
        $outputFile = Yii::getAlias('@app/data/game/multisell.php');

        if (!file_exists($inputPath)) {
            $this->stderr("Ошибка: файл {$inputPath} не найден!\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $itemNameToId = [];
        if (file_exists($itemsPath)) {
            $itemsData = require $itemsPath;
            foreach ($itemsData as $itemId => $row) {
                $itemNameToId[strtolower($row['name'])] = $itemId;
            }
        }
        $itemNameToId['adena'] = 57; // системный ID адены

        $this->stdout("Чтение multisell.txt...\n");
        $content = file_get_contents($inputPath);
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Извлекаем все блоки MultiSell_begin ... MultiSell_end
        preg_match_all('/MultiSell_begin\s+\[([^\]]+)\]\s+(\d+)(.*?)MultiSell_end/s', $content, $blocks, PREG_SET_ORDER);
        unset($content);

        $multisells = [];

        foreach ($blocks as $b) {
            $listName = trim($b[1]);
            $listId = (int)$b[2];
            $body = $b[3];

            // Ищем каждую пару: {{получаемый предмет; кол-во}; {{требуемый 1; кол-во}; {требуемый 2; кол-во}...}}
            preg_match_all('/\{\{\[([^\]]+)\];\s*(\d+)\};\s*\{([^\}]+(?:;\{[^\}]+\})*)\}\}/', $body, $entries, PREG_SET_ORDER);

            $entriesList = [];
            foreach ($entries as $e) {
                $rewardName = strtolower(trim($e[1]));
                $rewardCount = (int)$e[2];
                $reqString = $e[3];

                // Парсим необходимые ингредиенты: {[name];count}
                preg_match_all('/\{?\[([^\]]+)\];\s*(\d+)\}?/', $reqString, $reqMatches, PREG_SET_ORDER);
                $ingredients = [];
                foreach ($reqMatches as $rm) {
                    $ingName = strtolower(trim($rm[1]));
                    $ingredients[] = [
                        'item_id' => $itemNameToId[$ingName] ?? null,
                        'name'    => $ingName,
                        'count'   => (int)$rm[2],
                    ];
                }

                $entriesList[] = [
                    'reward' => [
                        'item_id' => $itemNameToId[$rewardName] ?? null,
                        'name'    => $rewardName,
                        'count'   => $rewardCount,
                    ],
                    'required' => $ingredients,
                ];
            }

            $multisells[$listId] = [
                'id'      => $listId,
                'name'    => $listName,
                'entries' => $entriesList,
            ];
        }

        $exported = VarDumper::export($multisells);
        file_put_contents($outputFile, "<?php\ndeclare(strict_types=1);\n\nreturn {$exported};\n");

        $this->stdout("Готово! Распарсено магазинов/крафт-листов: " . count($multisells) . "\n");
        return ExitCode::OK;
    }
}
