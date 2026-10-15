<?php
declare(strict_types=1);

namespace AstroPet;

require_once __DIR__ . '/Support.php';

/** Numeric, read-only projection of the Mercury product schema and last reported DPs. */
final class MercuryValues
{
    private const LITTER_MAX_AGE_SECONDS = 180;
    private const RESERVED = ['bridge_ok', 'data_valid', 'online', 'data_age_seconds', 'last_poll_ok'];

    /**
     * $now and *_reported_at use Unix seconds. Missing report times are 0/age -1.
     * dp_<id> preserves schema-checked raw numbers; dp_<code> applies schema scale.
     * Weight is a last reported measurement. Bitmap meanings remain unspecified.
     *
     * @return array<string, int|float>
     */
    public static function values(array $device, array $schema, int $now, array $mapping = []): array
    {
        $info = is_array($device['dataPointInfo'] ?? null) ? $device['dataPointInfo'] : $device;
        $dps = is_array($info['dps'] ?? null) ? $info['dps'] : [];
        $times = is_array($info['dpsTime'] ?? null) ? $info['dpsTime'] : [];
        $entries = [];
        $byCode = [];
        $values = ['visits_today_valid' => 0, 'litter_level_valid' => 0];

        foreach ($schema as $row) {
            if (!is_array($row) || !isset($row['id'], $row['code']) || !is_string($row['code'])
                || !preg_match('/\A[1-9][0-9]{0,8}\z/D', (string) $row['id'])
                || !array_key_exists((string) $row['id'], $dps) || ($row['type'] ?? 'obj') !== 'obj') {
                continue;
            }
            $id = (string) $row['id'];
            $property = $row['property'] ?? null;
            if (is_string($property)) {
                try { $property = json_decode($property, true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException $ignoredException) { continue; }
            }
            if (!is_array($property)) { continue; }
            $entry = self::typed($dps[$id], $property);
            if ($entry === null) { continue; }
            $entry['code'] = strtolower($row['code']);
            $entry['reported_at'] = self::timestamp($times[$id] ?? null);
            $entry['age_seconds'] = $entry['reported_at'] > 0 && $entry['reported_at'] <= $now + 5
                ? max(0, $now - $entry['reported_at']) : -1;
            $entries[$id] = $entry;
            $byCode[$row['code']] = $entry;
            $byCode[$entry['code']] = $entry;
            if ($entry['number'] !== null) {
                $values['dp_' . $id] = $entry['raw_number'];
                if (preg_match('/\A[a-z][a-z0-9_]{0,59}\z/D', $entry['code'])) {
                    $values['dp_' . $entry['code']] = $entry['number'];
                }
            }
        }

        $weight = self::entry($entries, '6', 'cat_weight', 'value');
        if ($weight !== null) {
            $unit = strtolower(trim($weight['unit']));
            if ($unit === 'g' || $unit === 'kg') {
                $grams = $weight['number'] * ($unit === 'kg' ? 1000 : 1);
                if (is_finite((float) $grams)) {
                    $values['weight_g'] = $grams;
                    $values['weight_kg'] = $grams / 1000;
                    self::report($values, 'weight', $weight);
                }
            }
        }

        $visits = self::entry($entries, '7', 'excretion_times_day', 'value');
        if ($visits !== null && $visits['unit'] === 'times') {
            $values['visits_today'] = $visits['number'];
            self::report($values, 'visits_today', $visits);
        }

        $duration = self::entry($entries, '8', 'excretion_time_day', 'value');
        if ($duration !== null) {
            $factors = ['s' => 1, 'ms' => 0.001, 'min' => 60];
            $factor = $factors[strtolower(trim($duration['unit']))] ?? null;
            if ($factor !== null && is_finite((float) ($duration['number'] * $factor))) {
                $values['last_visit_duration_seconds'] = $duration['number'] * $factor;
                self::report($values, 'last_visit_duration', $duration);
            }
        }

        $state = self::entry($entries, '101', 'work_state', 'enum');
        if ($state !== null) { $values['work_state'] = $state['number']; }
        $litter = self::entry($entries, '102', 'allowance', 'value');
        if ($litter !== null && trim($litter['unit']) === '%') {
            // The actual schema allows 0..250. Do not reinterpret it as 0..100.
            $values['litter_level_percent'] = $litter['number'];
            self::report($values, 'litter_level', $litter);
        }

        $message = self::entry($entries, '103', 'message', 'bitmap');
        $fault = self::entry($entries, '117', 'bit', 'bitmap');
        foreach (['message' => $message, 'fault' => $fault] as $name => $entry) {
            if ($entry !== null) {
                $values[$name . '_bitmap'] = $entry['number'];
                self::report($values, $name, $entry);
            }
        }
        if (($message !== null && $message['number'] !== 0) || ($fault !== null && $fault['number'] !== 0)) {
            $values['fault_active'] = 1;
        } elseif ($message !== null && $fault !== null) {
            $values['fault_active'] = 0;
        }
        // A zero aggregate requires both source DPs; a missing source is unknown.
        if (isset($values['fault_active'])) {
            $reported = $message !== null && $fault !== null
                ? min($message['reported_at'], $fault['reported_at'])
                : ($message ?? $fault)['reported_at'];
            $values['fault_active_reported_at'] = $reported;
            $values['fault_active_age_seconds'] = $reported > 0 && $reported <= $now + 5
                ? max(0, $now - $reported) : -1;
        }

        $auto = self::entry($entries, '124', 'auto', 'bool');
        if ($auto !== null) { $values['auto_clean_enabled'] = $auto['number']; }
        self::mapped($values, $byCode, $mapping);
        $values = self::refreshQuality($values, is_string($device['timezoneId'] ?? null) ? $device['timezoneId'] : null, $now);
        ksort($values, SORT_STRING);
        return $values;
    }

    /** Reevaluate cached report ages and validity without changing any measurement. */
    public static function refreshQuality(array $values, ?string $timezoneId, int $now): array
    {
        foreach ($values as $name => $value) {
            if (!is_string($name) || !preg_match('/\A(.+)_age_seconds\z/D', $name, $match)) { continue; }
            $reported = $values[$match[1] . '_reported_at'] ?? null;
            $values[$name] = self::reportAge($reported, $now);
        }
        $visitsReported = $values['visits_today_reported_at'] ?? null;
        $values['visits_today_valid'] = (int) (isset($values['visits_today'])
            && self::reportAge($visitsReported, $now) >= 0
            && self::reportedTodayTimestamp($visitsReported, $timezoneId, $now));
        $litterReported = $values['litter_level_reported_at'] ?? null;
        $litterAge = self::reportAge($litterReported, $now);
        $values['litter_level_valid'] = (int) (isset($values['litter_level_percent'])
            && $litterAge >= 0 && $litterAge <= self::LITTER_MAX_AGE_SECONDS
            && self::reportedTodayTimestamp($litterReported, $timezoneId, $now));
        return $values;
    }

    private static function reportAge($reported, int $now): int
    {
        return is_int($reported) && $reported > 0 && $reported <= $now + 5
            ? max(0, $now - $reported) : -1;
    }

    private static function typed($raw, array $property): ?array
    {
        $type = $property['type'] ?? null;
        $number = null;
        $rawNumber = null;
        $unit = is_string($property['unit'] ?? null) ? $property['unit'] : '';
        if ($type === 'bool') {
            if (is_bool($raw)) { $rawNumber = (int) $raw; }
            elseif (in_array($raw, [0, 1, '0', '1'], true)) { $rawNumber = (int) $raw; }
            else { return null; }
            $number = $rawNumber;
        } elseif ($type === 'enum') {
            if (!is_array($property['range'] ?? null) || (!is_string($raw) && !is_int($raw) && !is_float($raw))) {
                return null;
            }
            $allowed = array_filter($property['range'], fn ($item) => is_string($item) || is_int($item));
            if (!in_array((string) $raw, array_map('strval', $allowed), true)) { return null; }
            $number = self::number($raw);
            $rawNumber = $number;
        } elseif ($type === 'value' || $type === 'bitmap') {
            $rawNumber = self::number($raw);
            if ($rawNumber === null) { return null; }
            if ($type === 'bitmap') {
                $maxlen = $property['maxlen'] ?? null;
                if (!is_int($maxlen) || $maxlen < 1 || $maxlen > min(63, PHP_INT_SIZE * 8 - 1)
                    || $rawNumber < 0 || floor((float) $rawNumber) !== (float) $rawNumber
                    || $rawNumber > PHP_INT_MAX) { return null; }
                $rawNumber = (int) $rawNumber;
                $limit = $maxlen === 63 ? PHP_INT_MAX : (1 << $maxlen) - 1;
                if ($rawNumber > $limit) { return null; }
                $number = $rawNumber;
            } else {
                $scale = $property['scale'] ?? 0;
                if (!is_int($scale) || $scale < 0 || $scale > 12) { return null; }
                foreach (['min', 'max'] as $bound) {
                    if (array_key_exists($bound, $property)) {
                        $limit = self::number($property[$bound]);
                        if ($limit === null || ($bound === 'min' && $rawNumber < $limit)
                            || ($bound === 'max' && $rawNumber > $limit)) { return null; }
                    }
                }
                $number = $scale === 0 ? $rawNumber : $rawNumber / (10 ** $scale);
                if (!is_finite((float) $number)) { return null; }
            }
        } else { return null; }
        return ['type' => $type, 'raw' => $raw, 'raw_number' => $rawNumber, 'number' => $number, 'unit' => $unit];
    }

    private static function number($value)
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) { return $value; }
        if (!is_string($value) || !preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D', $value)) {
            return null;
        }
        $number = $value + 0;
        return is_finite((float) $number) ? $number : null;
    }

    private static function entry(array $entries, string $id, string $code, string $type): ?array
    {
        $entry = $entries[$id] ?? null;
        return $entry !== null && $entry['code'] === $code && $entry['type'] === $type && $entry['number'] !== null
            ? $entry : null;
    }

    private static function timestamp($raw): int
    {
        $number = self::number($raw);
        if ($number === null || $number <= 0 || $number > PHP_INT_MAX) { return 0; }
        return (int) floor($number >= 100000000000 ? $number / 1000 : $number);
    }

    private static function report(array &$values, string $prefix, array $entry): void
    {
        $values[$prefix . '_reported_at'] = $entry['reported_at'];
        $values[$prefix . '_age_seconds'] = $entry['age_seconds'];
    }

    private static function reportedTodayTimestamp($reported, ?string $timezoneId, int $now): bool
    {
        if (!is_int($reported) || $reported <= 0 || $timezoneId === null || $timezoneId === '') { return false; }
        try {
            $timezone = new \DateTimeZone($timezoneId);
            $today = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone)->format('Y-m-d');
            $day = (new \DateTimeImmutable('@' . $reported))->setTimezone($timezone)->format('Y-m-d');
            return $today === $day;
        } catch (\Exception $ignoredException) { return false; }
    }

    /** Compatible mapping rules: semantic code, optional enum lookup and positive divisor. */
    private static function mapped(array &$values, array $byCode, array $mapping): void
    {
        foreach ($mapping as $name => $rule) {
            if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name)
                || Support::startsWith($name, 'dp_') || in_array($name, self::RESERVED, true)
                || !is_array($rule) || !is_string($rule['code'] ?? null)) {
                throw new \RuntimeException('Ungültige Zuordnung in mapping.');
            }
            $entry = $byCode[$rule['code']] ?? null;
            if ($entry === null) { continue; }
            $number = $entry['number'];
            if (array_key_exists('enum', $rule)) {
                if (!is_array($rule['enum']) || !is_string($entry['raw'])
                    || !array_key_exists($entry['raw'], $rule['enum'])) { continue; }
                // Explicit mappings may turn confirmed symbolic enums into numbers.
                $mapped = $rule['enum'][$entry['raw']];
                $number = is_bool($mapped) ? (int) $mapped
                    : (is_int($mapped) || (is_float($mapped) && is_finite($mapped)) ? $mapped : null);
            }
            $divisor = self::number($rule['divisor'] ?? 1);
            if ($divisor === null || $divisor <= 0) { throw new \RuntimeException('divisor muss eine positive Zahl sein.'); }
            if ($number === null) { continue; }
            $scaled = $divisor == 1 ? $number : $number / $divisor;
            if (!is_finite((float) $scaled)) { throw new \RuntimeException('Wert außerhalb des Zahlenbereichs.'); }
            $values[$name] = $scaled;
        }
    }
}
