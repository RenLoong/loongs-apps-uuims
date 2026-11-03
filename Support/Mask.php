<?php

declare(strict_types=1);

namespace App\Uuims\Support;

/** Display masks for field-level "masked" permission. */
final class Mask
{
    public static function value(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $v = (string) $value;

        return match (true) {
            str_contains($field, 'mobile') || str_contains($field, 'phone') => self::keep($v, 3, 4),
            str_contains($field, 'email') && str_contains($v, '@') => self::email($v),
            default => self::keep($v, 1, 1),
        };
    }

    private static function keep(string $v, int $head, int $tail): string
    {
        $len = mb_strlen($v);
        if ($len <= $head + $tail) {
            return str_repeat('*', $len);
        }

        return mb_substr($v, 0, $head) . str_repeat('*', $len - $head - $tail) . mb_substr($v, $len - $tail);
    }

    private static function email(string $v): string
    {
        [$user, $domain] = explode('@', $v, 2);

        return mb_substr($user, 0, 1) . '***@' . $domain;
    }
}
