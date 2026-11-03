<?php

declare(strict_types=1);

namespace App\Uuims\Support;

/**
 * Minimal rule validator → 422 (code 42201) with {errors: {field: message}}.
 * Rules: required, string, int, bool, array, min:N, max:N (length for strings / value for ints),
 * in:a,b,c, regex:/…/, email, nullable. Messages / labels are translated (I18n).
 */
final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules field => 'required|string|max:64'
     * @param array<string, string> $labels field => display name
     * @return array<string, mixed> only the validated keys that were present
     */
    public static function check(array $input, array $rules, array $labels = []): array
    {
        $errors = [];
        $out = [];
        foreach ($rules as $field => $ruleText) {
            $rules1 = explode('|', $ruleText);
            $label = I18n::msg($labels[$field] ?? $field);
            $present = array_key_exists($field, $input);
            $value = $input[$field] ?? null;
            if (!$present || $value === null || $value === '') {
                if (in_array('required', $rules1, true)) {
                    $errors[$field] = I18n::t('{label}不能为空', ['label' => $label]);
                } elseif ($present && in_array('nullable', $rules1, true)) {
                    $out[$field] = null;
                }
                continue;
            }
            foreach ($rules1 as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = match ($name) {
                    'string' => is_string($value) ? null : I18n::t('{label}必须是字符串', ['label' => $label]),
                    'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : I18n::t('{label}必须是整数', ['label' => $label]),
                    'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true) ? null : I18n::t('{label}必须是布尔值', ['label' => $label]),
                    'array' => is_array($value) ? null : I18n::t('{label}必须是数组', ['label' => $label]),
                    'min' => self::size($value) >= (int) $arg ? null : I18n::t('{label}不能小于 {arg}', ['label' => $label, 'arg' => $arg]),
                    'max' => self::size($value) <= (int) $arg ? null : I18n::t('{label}不能大于 {arg}', ['label' => $label, 'arg' => $arg]),
                    'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : I18n::t('{label}取值无效', ['label' => $label]),
                    'regex' => is_string($value) && preg_match((string) $arg, $value) === 1 ? null : I18n::t('{label}格式不正确', ['label' => $label]),
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : I18n::t('{label}格式不正确', ['label' => $label]),
                    default => null,
                };
                if ($err !== null) {
                    $errors[$field] = $err;
                    break;
                }
            }
            if (!isset($errors[$field])) {
                $out[$field] = in_array('int', $rules1, true) ? (int) $value
                    : (in_array('bool', $rules1, true) ? (int) (bool) $value : $value);
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $out;
    }

    private static function size(mixed $v): int
    {
        return match (true) {
            is_array($v) => count($v),
            is_int($v), is_numeric($v) && !is_string($v) => (int) $v,
            default => mb_strlen((string) $v),
        };
    }

    /** @return list<int> */
    public static function ids(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $v), static fn (int $i): bool => $i > 0)));
    }
}
