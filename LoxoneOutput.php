<?php
declare(strict_types=1);

namespace AstroPet;

/** Projects a private poller snapshot to the numeric fields consumed by Loxone. */
final class LoxoneOutput
{
    private const RESERVED = [
        'bridge_ok', 'data_valid', 'online', 'data_age_seconds', 'last_poll_ok',
    ];

    /**
     * Older measurements remain visible after a failed poll. Consumers must gate
     * automations with data_valid=1 before using any measurement.
     *
     * @return array<string, int|float>
     */
    public static function project(?array $snapshot, int $now, int $maxAgeSeconds = 180): array
    {
        $failure = [
            'bridge_ok' => 0,
            'data_valid' => 0,
            'online' => -1,
            'data_age_seconds' => -1,
            'last_poll_ok' => 0,
        ];

        if (!self::isWellFormed($snapshot)
            || ($snapshot['last_success_at'] !== null && $snapshot['last_success_at'] > $now + 5)) {
            return $failure;
        }

        $lastSuccess = $snapshot['last_success_at'];
        $age = $lastSuccess === null ? -1 : max(0, $now - $lastSuccess);
        $valid = $snapshot['last_poll_ok']
            && $age >= 0
            && $age <= max(0, $maxAgeSeconds)
            && $snapshot['online'] === true;

        $projection = [
            'bridge_ok' => 1,
            'data_valid' => (int) $valid,
            'online' => $snapshot['online'] === null ? -1 : (int) $snapshot['online'],
            'data_age_seconds' => $age,
            'last_poll_ok' => (int) $snapshot['last_poll_ok'],
        ];

        $values = $snapshot['values'];
        ksort($values, SORT_STRING);
        foreach ($values as $key => $value) {
            $projection[$key] = $value;
        }
        return $projection;
    }

    private static function isWellFormed(?array $snapshot): bool
    {
        if ($snapshot === null
            || !isset($snapshot['last_poll_ok'], $snapshot['last_attempt_at'], $snapshot['values'], $snapshot['device_id'])
            || !array_key_exists('last_success_at', $snapshot)
            || !array_key_exists('online', $snapshot)
            || !is_bool($snapshot['last_poll_ok'])
            || !self::isTimestamp($snapshot['last_attempt_at'])
            || ($snapshot['last_success_at'] !== null && !self::isTimestamp($snapshot['last_success_at']))
            || ($snapshot['online'] !== null && !is_bool($snapshot['online']))
            || !is_array($snapshot['values'])
            || !is_string($snapshot['device_id'])) {
            return false;
        }

        if ($snapshot['last_success_at'] !== null
            && $snapshot['last_success_at'] > $snapshot['last_attempt_at']) {
            return false;
        }

        foreach ($snapshot['values'] as $key => $value) {
            if (!self::isSafeKey($key)
                || in_array($key, self::RESERVED, true)
                || !self::isFiniteNumber($value)) {
                return false;
            }
        }
        return true;
    }

    private static function isTimestamp($value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function isSafeKey($key): bool
    {
        // PHP converts wholly numeric JSON object keys to integers.
        return (is_string($key) || is_int($key))
            && preg_match('/\A[a-z0-9_]{1,64}\z/D', (string) $key) === 1;
    }

    private static function isFiniteNumber($value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }
}
