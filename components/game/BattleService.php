<?php

namespace app\components\game;

use app\models\Character;
use app\models\CharacterItem;
use app\models\CharacterSkill;
use app\models\game\battle\ActiveEffect;
use app\models\game\battle\BattleMob;
use app\models\game\battle\BattleResult;
use app\models\game\battle\BattleSession;
use app\models\game\ExpTableRepository;
use app\models\game\Npc;
use app\models\game\SkillRepository;
use Yii;
use yii\db\Exception;

class BattleService
{
    private const TTL = 1800; // 30 минут жизни сессии в кэше

    private static function cacheKey(int $characterId): string
    {
        return "battle:session:{$characterId}";
    }

    public static function hasBattle(int $characterId): bool
    {
        // Проверяем не просто факт наличия ключа, а валидность объекта боя
        return self::getSession($characterId) !== null;
    }

    public static function getSession(int $characterId): ?BattleSession
    {
        $data = Yii::$app->cache->get(self::cacheKey($characterId));
        return is_array($data) ? BattleSession::fromArray($data) : null;
    }

    public static function saveSession(BattleSession $session): void
    {
        $session->updatedAt = time();
        Yii::$app->cache->set(self::cacheKey($session->characterId), $session->toArray(), self::TTL);
    }

    public static function clearSession(int $characterId): void
    {
        Yii::$app->cache->delete(self::cacheKey($characterId));
    }

    public static function startBattle(Character $character, array $npcs): BattleSession
    {
        $mobs = [];
        foreach (array_values($npcs) as $idx => $npc) {
            $mobs[$idx] = new BattleMob(
                index: $idx,
                npcId: $npc->id,
                curHp: (float)$npc->hp,
                curMp: (float)$npc->mp,
                maxHp: (int)$npc->hp
            );
        }

        $names = implode(', ', array_map(fn(Npc $n) => $n->getName(), $npcs));
        $session = new BattleSession(
            characterId: $character->id,
            round: 1,
            targetIndex: 0,
            mobs: $mobs,
            logs: ["Бой начался! Противники: {$names}."],
            updatedAt: time()
        );

        self::saveSession($session);
        return $session;
    }

    public static function changeTarget(Character $character, int $index): bool
    {
        $session = self::getSession($character->id);
        if ($session !== null && $session->setTarget($index)) {
            self::saveSession($session);
            return true;
        }
        return false;
    }


    public static function executeAttack(Character $character): BattleResult
    {
        $session = self::getSession($character->id);
        if ($session === null) {
            return BattleResult::fleeSuccess('Бой не найден.');
        }

        $target = $session->getTarget();
        if ($target === null) {
            self::clearSession($character->id);
            return BattleResult::fleeSuccess('Все противники повержены.');
        }

        $maxTargets = self::resolveMaxAttackTargets($character);
        $attackTargets = $session->resolveTargets($maxTargets);

        $usedSoulshot = self::consumeSoulshot($character);
        $shotBonus = $usedSoulshot ? 2.0 : 1.0;
        $shotTag = $usedSoulshot ? ' <span class="text-primary">(SS)</span>' : '';

        $doubleChance = max(0, min(40, (int)round(($character->attackSpeed - 300) * 0.25)));
        $isDouble = mt_rand(1, 100) <= $doubleChance;

        $logEntries = [];
        $killedNpcs = [];

        foreach ($attackTargets as $mob) {
            $isCrit = mt_rand(1, 100) <= min(50, (int)round($character->dex * 0.35 + 4));
            $critBonus = $isCrit ? 2.0 : 1.0;

            $pAtk = max(10, $character->pAtk);
            $pDef = $mob->getPDef();
            $rand = mt_rand(95, 105) / 100.0;

            $dmg = (int)round(((70.0 * $pAtk * $shotBonus) / $pDef) * $critBonus * $rand);
            if ($isDouble) {
                $dmg += (int)round($dmg * 0.85);
            }
            $dmg = max(1, $dmg);

            $mob->takeDamage((float)$dmg);

            $critTag = $isCrit ? ' <b>[КРИТ!]</b>' : '';
            $doubleTag = $isDouble ? ' <b>[ДВОЙНОЙ УДАР!]</b>' : '';
            $logEntries[] = "Раунд {$session->round}: Вы нанесли {$mob->getName()} <b>{$dmg}</b> ур.{$shotTag}{$critTag}{$doubleTag}";

            if ($mob->isDead()) {
                $npcTemplate = $mob->getNpc();
                if ($npcTemplate) {
                    $killedNpcs[] = $npcTemplate;
                }
            }
        }

        if ($session->isAllDead()) {
            $msg = self::finalizeVictory($character, $killedNpcs);
            self::clearSession($character->id);
            return BattleResult::victory($msg);
        }

        // ЕДИНСТВЕННЫЙ расчет ответного удара с учетом затраченного времени
        $now = time();
        $elapsed = max(0, $now - $session->updatedAt);

        $retaliationResult = self::processRetaliation($character, $session, $logEntries, 1.0, $elapsed, true);
        if ($retaliationResult !== null) {
            return $retaliationResult;
        }

        $session->round++;
        $session->updatedAt = $now;
        self::saveSession($session);
        return BattleResult::inProgress();
    }

    public static function executeSkill(Character $character, int $skillId): BattleResult
    {
        $session = self::getSession($character->id);
        if ($session === null) {
            return BattleResult::fleeSuccess('Бой не найден.');
        }

        $charSkill = $character->getCharacterSkills()->where(['skill_id' => $skillId])->one();
        if (!$charSkill) {
            return BattleResult::inProgress();
        }

        /** @var CharacterSkill $charSkill */
        $skill = SkillRepository::find($charSkill->skill_id, $charSkill->level);
        if (!$skill || $skill->isPassive()) {
            return BattleResult::inProgress();
        }

        if ($character->cur_mp < $skill->mpConsume) {
            $session->addLog("<span class='text-danger'>Недостаточно MP для умения {$skill->getName()}!</span>");
            self::saveSession($session);
            return BattleResult::inProgress();
        }

        $character->cur_mp = max(0.0, $character->cur_mp - $skill->mpConsume);

        $logEntries = [];
        $killedNpcs = [];

        if ($skill->type === 'EFFECT' && in_array($skill->targetType, ['self', 'party'], true)) {
            if (!empty($skill->effects['stats'])) {
                foreach ($skill->effects['stats'] as $st) {
                    $eff = new ActiveEffect(
                        id: $skill->systemName,
                        abnormalType: $skill->systemName,
                        abnormalLv: $skill->level,
                        type: 'STAT',
                        stat: $st['stat'],
                        value: (float)$st['val'],
                        mode: $st['mode'],
                        duration: (int)($st['duration'] ?? 1200),
                        icon: $skill->icon
                    );
                    CharacterEffectService::addEffect($character, $eff);
                }
                $logEntries[] = "Раунд {$session->round}: Вы применили {$skill->getName()}.";
            }
        } else {
            $maxTargets = $skill->isAoe() ? $skill->getMaxTargets() : 1;
            $targets = $session->resolveTargets($maxTargets);

            $shotBonus = 1.0;
            $shotTag = '';
            if ($skill->isMagic) {
                if ($character->auto_spiritshot && self::consumeSpiritshot($character)) {
                    $shotBonus = 1.414;
                    $shotTag = ' <span class="text-info">(SPS)</span>';
                }
            } else {
                if ($character->auto_soulshot && self::consumeSoulshot($character)) {
                    $shotBonus = 2.0;
                    $shotTag = ' <span class="text-primary">(SS)</span>';
                }
            }

            foreach ($targets as $mob) {
                $damage = 0;
                $isCrit = false;

                if ($skill->type === 'PHYS_DMG') {
                    $pAtk = max(10, $character->pAtk);
                    $pDef = $mob->getPDef();
                    $isCrit = mt_rand(1, 100) <= 8;
                    $critMul = $isCrit ? 2.0 : 1.0;
                    $damage = (int)round(((77.0 * ($pAtk + $skill->power) * $shotBonus) / $pDef) * $critMul * (mt_rand(95, 105) / 100.0));
                } elseif ($skill->type === 'MAGIC_DMG') {
                    $mAtk = max(6, $character->mAtk);
                    $mDef = $mob->getMDef();
                    $isCrit = mt_rand(1, 100) <= min(30, (int)round($character->wit * 0.3));
                    $critMul = $isCrit ? 3.0 : 1.0;
                    $damage = (int)round((91.0 * (sqrt($mAtk) / $mDef) * $skill->power * $shotBonus) * $critMul * (mt_rand(95, 105) / 100.0));
                }

                $damage = max(1, $damage);
                $mob->takeDamage((float)$damage);

                $critTag = $isCrit ? ' <b>[КРИТ!]</b>' : '';
                $logEntries[] = "Раунд {$session->round}: {$skill->getName()} нанес {$mob->getName()} <b>{$damage}</b> ур.{$shotTag}{$critTag}";

                if (isset($skill->effects['debuff']) && $mob->isAlive()) {
                    $chance = (int)($skill->effects['chance'] ?? 80);
                    if (mt_rand(1, 100) <= $chance) {
                        $rounds = max(1, (int)round(($skill->effects['duration'] ?? 9) / 4));
                        $mob->addEffect(new ActiveEffect(
                            id: $skill->effects['debuff'],
                            abnormalType: $skill->effects['debuff'],
                            abnormalLv: $skill->level,
                            type: 'CONTROL',
                            duration: $rounds
                        ));
                        $logEntries[] = "{$mob->getName()} оглушен на {$rounds} р.!";
                    }
                }

                if ($mob->isDead()) {
                    $npcTemplate = $mob->getNpc();
                    if ($npcTemplate) {
                        $killedNpcs[] = $npcTemplate;
                    }
                }
            }
        }

        if ($session->isAllDead()) {
            $msg = self::finalizeVictory($character, $killedNpcs);
            self::clearSession($character->id);
            return BattleResult::victory($msg);
        }

        // ЕДИНСТВЕННЫЙ ответный расчет
        $now = time();
        $elapsed = max(0, $now - $session->updatedAt);

        $retaliationResult = self::processRetaliation($character, $session, $logEntries, 1.0, $elapsed, true);
        if ($retaliationResult !== null) {
            return $retaliationResult;
        }

        $session->round++;
        $session->updatedAt = $now;
        self::saveSession($session);
        return BattleResult::inProgress();
    }

    public static function executePotion(Character $character, int $charItemId): BattleResult
    {
        $session = self::getSession($character->id);
        if ($session === null) {
            return BattleResult::fleeSuccess('Бой не найден.');
        }

        /** @var CharacterItem|null $item */
        $item = CharacterItem::findOne(['id' => $charItemId, 'character_id' => $character->id]);
        if (!$item || $item->count <= 0) {
            return BattleResult::inProgress();
        }

        $heal = $item->item_id === 1060 ? 100 : 250;
        $character->cur_hp = min((float)$character->maxHp, $character->cur_hp + $heal);

        $item->count--;
        if ($item->count <= 0) {
            $item->delete();
        } else {
            $item->save(false, ['count']);
        }

        $logEntries = ["Раунд {$session->round}: Вы выпили зелье (+{$heal} HP)."];

        $now = time();
        $elapsed = max(0, $now - $session->updatedAt);

        $retaliationResult = self::processRetaliation($character, $session, $logEntries, 1.0, $elapsed, true);
        if ($retaliationResult !== null) {
            return $retaliationResult;
        }

        $session->round++;
        $session->updatedAt = $now;
        self::saveSession($session);
        return BattleResult::inProgress();
    }

    public static function executeFlee(Character $character): BattleResult
    {
        $session = self::getSession($character->id);
        if ($session === null) {
            return BattleResult::fleeSuccess('Бой не найден.');
        }

        $playerSpeed = $character->getRunSpeed();
        $target = $session->getTarget();
        $mobSpeed = $target ? $target->getRunSpeed() : 120;

        $speedDiff = $playerSpeed - $mobSpeed;
        $fleeChance = max(15, min(90, (int)round(55 + ($speedDiff * 0.6))));

        if (mt_rand(1, 100) <= $fleeChance) {
            self::clearSession($character->id);
            return BattleResult::fleeSuccess("Вы оторвались от преследования и успешно сбежали!");
        }

        $logEntries = ["Раунд {$session->round}: Попытка бегства провалилась (шанс был {$fleeChance}%)!"];
        $now = time();
        $elapsed = max(0, $now - $session->updatedAt);

        $retaliationResult = self::processRetaliation($character, $session, $logEntries, 1.2, $elapsed, true);
        if ($retaliationResult !== null) {
            return $retaliationResult;
        }

        $session->round++;
        $session->updatedAt = $now;
        self::saveSession($session);
        return BattleResult::inProgress();
    }

    /**
     * @param Npc[] $killedNpcs
     * @throws Exception
     */
    private static function finalizeVictory(Character $character, array $killedNpcs): string
    {
        $totalExp = 0;
        $totalSp = 0;
        $dropListAll = [];

        foreach ($killedNpcs as $npc) {
            $expGained = (int)($npc->exp ?? 0);
            $spGained  = (int)($npc->sp ?? 0);

            $lvlDiff = $character->level - $npc->level;
            if ($lvlDiff >= 6) {
                $penalty = min(0.9, ($lvlDiff - 5) * 0.15);
                $expGained = (int)round($expGained * (1.0 - $penalty));
                $spGained  = (int)round($spGained * (1.0 - $penalty));
            }

            $totalExp += max(0, $expGained);
            $totalSp  += max(0, $spGained);

            foreach ($npc->drops ?? [] as $drop) {
                $chance = (float)($drop->chance ?? 0);
                if ($chance <= 0 || (mt_rand(1, 10000) / 100.0) > $chance) {
                    continue;
                }

                $min = (int)($drop->min ?? 1);
                $max = (int)($drop->max ?? 1);
                if ($min <= 0 || $max <= 0) continue;

                $count = mt_rand($min, $max);
                if ($count <= 0) continue;

                $item = $drop->getItem();
                if ($item !== null) {
                    CharacterItem::addItem($character->id, $item->id, $count);
                    if ($item->id === 57) {
                        $dropListAll[] = number_format($count, 0, '', ' ') . ' адены';
                    } else {
                        $dropListAll[] = $item->getName() . ($count > 1 ? " (x{$count})" : '');
                    }
                }
            }
        }

        $character->exp += $totalExp;
        $character->sp  += $totalSp;

        $msg = [];
        if ($totalExp > 0 || $totalSp > 0) {
            $msg[] = "Победа! Получено: +{$totalExp} EXP, +{$totalSp} SP.";
        } else {
            $msg[] = 'Враги повержены!';
        }

        $leveledUp = false;
        while ($character->level < 80 && $character->exp >= ExpTableRepository::getNextLvlExp($character->level)) {
            $character->level++;
            $leveledUp = true;
        }

        if ($leveledUp) {
            $character->cur_hp = (float)$character->maxHp;
            $character->cur_mp = (float)$character->maxMp;
            $character->cur_cp = (float)$character->maxCp;
            $msg[] = "🎉 <b>ПОЗДРАВЛЯЕМ! Вы получили {$character->level} уровень!</b>";
        }

        if (!empty($dropListAll)) {
            $msg[] = 'Добыча: ' . implode(', ', array_unique($dropListAll));
        }

        $character->save(false);
        return implode('<br>', $msg);
    }

    private static function resolveMaxAttackTargets(Character $character): int
    {
        if (method_exists($character, 'getMaxAttackTargets')) {
            return $character->getMaxAttackTargets();
        }

        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item && $item->isWeapon() && $item->weaponType === 'pole') {
                return 3;
            }
        }

        return 1;
    }

    private static function consumeSoulshot(Character $character): bool
    {
        if (!$character->auto_soulshot) {
            return false;
        }

        /** @var CharacterItem|null $shotItem */
        $shotItem = $character->getCharacterItems()
            ->where(['in', 'item_id', [1835, 5789, 1463, 1464, 1465, 1466, 1467]])
            ->andWhere(['>', 'count', 0])
            ->one();

        if ($shotItem) {
            $shotItem->count--;
            if ($shotItem->count <= 0) {
                $shotItem->delete();
            } else {
                $shotItem->save(false, ['count']);
            }
            return true;
        }

        return false;
    }

    private static function consumeSpiritshot(Character $character): bool
    {
        if (!$character->auto_spiritshot) {
            return false;
        }

        /** @var CharacterItem|null $spsItem */
        $spsItem = $character->getCharacterItems()
            ->where(['in', 'item_id', [2509, 3947, 5790, 2510, 3948, 2511, 3949, 2512, 3950, 2513, 3951, 2514, 3952]])
            ->andWhere(['>', 'count', 0])
            ->one();

        if ($spsItem) {
            $spsItem->count--;
            if ($spsItem->count <= 0) {
                $spsItem->delete();
            } else {
                $spsItem->save(false, ['count']);
            }
            return true;
        }

        return false;
    }

    /**
     * Проверка простоя при обновлении экрана боя (F5 / GET-запрос)
     */
    public static function processIdleRetaliation(Character $character, BattleSession $session): ?BattleResult
    {
        $now = time();
        $elapsed = max(0, $now - $session->updatedAt);

        // Если прошло меньше 2 секунд — ничего не рассчитываем (защита от случайных быстрых кликов)
        if ($elapsed < 2) {
            return null;
        }

        $playerLogs = [
            "<i>Пока вы бездействовали ({$elapsed} сек.), противники непрерывно атаковали!</i>"
        ];

        // Проводим пассивную серию атак за время простоя ($isActionTurn = false)
        $result = self::processRetaliation($character, $session, $playerLogs, 1.0, $elapsed, false);

        $session->updatedAt = $now;
        self::saveSession($session);

        return $result;
    }

    /**
     * Ответная фаза монстров с учетом времени и скорости атаки
     */
    private static function processRetaliation(
        Character $character,
        BattleSession $session,
        array $playerLogs,
        float $damageMultiplier = 1.0,
        int $elapsed = 0,
        bool $isActionTurn = true
    ): ?BattleResult {
        $aliveMobs = $session->getAliveMobs();
        $totalIncomingDamage = 0;
        $retaliationTexts = [];

        // Ограничиваем расчет 300 секундами (5 минут), чтобы исключить зависания при возврате через неделю
        $elapsed = max(0, min(300, $elapsed));

        $isRanged = false;
        foreach ($character->equippedItems as $invItem) {
            $item = $invItem->getItem();
            if ($item && $item->isWeapon() && $item->weaponType === 'bow') {
                $isRanged = true;
                break;
            }
        }
        $playerSpeed = $character->getRunSpeed();

        foreach ($aliveMobs as $mob) {
            // 1. Прогон DoT-эффектов моба за прошедшие секунды
            if ($elapsed > 0) {
                [$dotDmg, $dotLogs] = $mob->processElapsedEffects($elapsed);
                foreach ($dotLogs as $dLog) {
                    $playerLogs[] = $dLog;
                }
            }
            if ($mob->isDead()) {
                continue;
            }

            // 2. Расчет времени активности с вычетом контроля (стана)
            $stunDuration = $mob->getControlRemainingDuration();
            $activeSeconds = max(0, $elapsed - $stunDuration);

            // Если моб был оглушен все это время
            if ($activeSeconds <= 0 && $mob->isStunned()) {
                $retaliationTexts[] = "{$mob->getName()}: [Оглушен, не может атаковать]";
                continue;
            }

            // 3. Расчет количества ударов от Atk.Spd
            $interval = $mob->getAttackInterval();
            if ($isActionTurn) {
                // Если ход инициирован действием игрока — минимум 1 удар
                $hits = max(1, (int)floor($activeSeconds / $interval));
            } else {
                // Если игрок просто стоял/обновил F5 — честное число завершенных атак
                $hits = (int)floor($activeSeconds / $interval);
            }

            if ($hits <= 0) {
                continue;
            }

            // 4. Кайтинг лучника (проверяется для серии ударов)
            if ($isRanged && $playerSpeed > $mob->getRunSpeed()) {
                $kiteChance = min(35, (int)round(($playerSpeed - $mob->getRunSpeed()) * 0.5));
                if (mt_rand(1, 100) <= $kiteChance) {
                    $retaliationTexts[] = "{$mob->getName()}: [Вы разорвали дистанцию!]";
                    continue;
                }
            }

            // 5. Расчет урона за серию ударов
            $mobPAtk = $mob->getPAtk();
            $playerPDef = max(10, $character->pDef);
            $singleHitDmg = (int)round(((70.0 * $mobPAtk * $damageMultiplier) / $playerPDef) * (mt_rand(95, 105) / 100.0));
            $singleHitDmg = max(1, $singleHitDmg);

            // Проверка применения скилла (один из ударов серии может быть скиллом)
            $usedSkillName = null;
            $mobSkills = $mob->getSkills();
            if (!empty($mobSkills) && mt_rand(1, 100) <= min(60, 20 + ($hits * 5))) {
                $offensive = array_filter($mobSkills, fn($s) => in_array($s['trigger'], ['DDMagic', 'PhysicalSpecial', 'DeBuff'], true));
                if (!empty($offensive)) {
                    $sInfo = $offensive[array_rand($offensive)];
                    $skill = SkillRepository::find($sInfo['skill_id'], $sInfo['level']);
                    if ($skill && $mob->curMp >= $skill->mpConsume) {
                        $mob->curMp = max(0.0, $mob->curMp - $skill->mpConsume);
                        $usedSkillName = $skill->getName();

                        // Накладываем дебафф на игрока
                        if (!empty($skill->effects['debuff']) || !empty($skill->effects['dot'])) {
                            $debuffType = $skill->effects['debuff'] ?? $skill->effects['dot'];
                            CharacterEffectService::addEffect($character, new ActiveEffect(
                                id: $skill->systemName,
                                abnormalType: $debuffType,
                                abnormalLv: $skill->level,
                                type: isset($skill->effects['dot']) ? 'DOT' : 'CONTROL',
                                duration: (int)($skill->effects['duration'] ?? 15),
                                icon: $skill->icon
                            ));
                        }
                    }
                }
            }

            $mobTotalDmg = $singleHitDmg * $hits;
            $totalIncomingDamage += $mobTotalDmg;

            $skillTag = $usedSkillName ? " (включая {$usedSkillName})" : '';
            if ($hits > 1) {
                $retaliationTexts[] = "{$mob->getName()}: <b>-{$mobTotalDmg}</b> ({$hits} уд. за {$activeSeconds}с){$skillTag}";
            } else {
                $retaliationTexts[] = "{$mob->getName()}: -{$mobTotalDmg}{$skillTag}";
            }
        }

        if ($totalIncomingDamage > 0) {
            $character->cur_hp = max(0.0, $character->cur_hp - $totalIncomingDamage);
        }

        $character->last_action_at = time();
        $character->save(false, ['cur_hp', 'cur_mp', 'last_action_at', 'updated_at']);

        if (!empty($retaliationTexts)) {
            $summary = 'Ответный урон: ' . implode(', ', $retaliationTexts);
            $session->addLog(implode('<br>', $playerLogs) . '<br>' . $summary);
        }

        if ($character->cur_hp <= 0) {
            self::clearSession($character->id);
            CharacterEffectService::flushToDb($character);
            return BattleResult::defeat('Вы погибли в неравной схватке!');
        }

        return null;
    }



}
