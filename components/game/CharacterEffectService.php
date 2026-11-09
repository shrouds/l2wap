<?php

namespace app\components\game;

use app\models\Character;
use app\models\game\battle\ActiveEffect;
use Yii;

class CharacterEffectService
{
    private const TTL = 86400; // 24 часа жизни горячего ключа в кэше
    private const MAX_BUFF_SLOTS = 20;

    private static function cacheKey(int $characterId): string
    {
        return "char:effects:{$characterId}";
    }

    /**
     * Получение активных эффектов с ленивой гидратацией из MySQL
     * @return ActiveEffect[]
     */
    public static function getEffects(Character $character): array
    {
        $cache = Yii::$app->cache;
        $key = self::cacheKey($character->id);
        $data = $cache->get($key);

        if (is_array($data)) {
            return array_map(fn(array $row) => ActiveEffect::fromArray($row), $data);
        }

        // Гидратация из холодного хранилища MySQL: читаем новое текстовое поле JSON
        $dbRaw = $character->active_effects;
        $decoded = is_string($dbRaw) ? json_decode($dbRaw, true) : (is_array($dbRaw) ? $dbRaw : []);
        $effects = [];

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (isset($item['duration']) && $item['duration'] > 0) {
                    $effects[$item['id']] = ActiveEffect::fromArray($item);
                }
            }
        }

        self::saveToCache($character->id, $effects);
        return $effects;
    }

    /**
     * Наложение эффекта с соблюдением правил стекирования L2 PTS
     * @return bool Был ли эффект успешно наложен/обновлен
     */
    public static function addEffect(Character $character, ActiveEffect $newEffect): bool
    {
        $effects = self::getEffects($character);

        // 1. Поиск конфликта по abnormal_type
        foreach ($effects as $key => $existing) {
            if ($existing->abnormalType === $newEffect->abnormalType) {
                // Если текущий бафф сильнее — отклоняем новый
                if ($existing->abnormalLv > $newEffect->abnormalLv) {
                    return false;
                }
                // Если новый сильнее или равен — заменяем старый со сбросом таймера
                unset($effects[$key]);
                break;
            }
        }

        // 2. Контроль лимита слотов баффов (только для положительных STAT)
        if ($newEffect->isStat() && $newEffect->value >= 0) {
            $buffCount = count(array_filter($effects, fn(ActiveEffect $e) => $e->isStat() && $e->value >= 0));
            if ($buffCount >= self::MAX_BUFF_SLOTS) {
                // Вытеснение самого первого (старого) баффа
                $firstKey = array_key_first($effects);
                if ($firstKey !== null) {
                    unset($effects[$firstKey]);
                }
            }
        }

        $effects[$newEffect->id] = $newEffect;
        self::saveToCache($character->id, $effects);
        return true;
    }

    /**
     * Учет активного времени персонажа (списание длительности баффов и тики DoT)
     */
    public static function tickElapsed(Character $character, int $rawElapsed): void
    {
        // Ограничиваем списание активного времени максимум 60 секундами за действие (защита оффлайна)
        $elapsed = max(0, min(60, $rawElapsed));
        if ($elapsed <= 0) {
            return;
        }

        $effects = self::getEffects($character);
        if (empty($effects)) {
            return;
        }

        $hasChanges = false;
        $dotDamage = 0.0;

        foreach ($effects as $id => $eff) {
            // Списание времени
            $eff->duration -= $elapsed;

            // Расчет тиков урона DoT за активный интервал
            if ($eff->isDot()) {
                $ticks = (int)floor($elapsed / 3);
                if ($ticks > 0) {
                    $dotDamage += ($eff->value * $ticks);
                }
            }

            // Удаление истекших эффектов
            if ($eff->duration <= 0) {
                unset($effects[$id]);
                $hasChanges = true;
            }
        }

        // Нанесение периодического урона персонажу в быту
        if ($dotDamage > 0) {
            $character->cur_hp = max(1.0, $character->cur_hp - $dotDamage);
            $character->save(false, ['cur_hp']);
        }

        self::saveToCache($character->id, $effects);
    }

    /**
     * Контрольная точка: сброс горячего слепка в MySQL (окончание боя, спасброс, выход)
     */
    public static function flushToDb(Character $character): void
    {
        $effects = self::getEffects($character);
        $payload = array_map(fn(ActiveEffect $e) => $e->toArray(), array_values($effects));

        $character->active_effects = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $character->last_action_at = time();
        $character->save(false, ['active_effects', 'last_action_at', 'updated_at']);
    }

    private static function saveToCache(int $characterId, array $effects): void
    {
        $payload = array_map(fn(ActiveEffect $e) => $e->toArray(), array_values($effects));
        Yii::$app->cache->set(self::cacheKey($characterId), $payload, self::TTL);
    }
}
