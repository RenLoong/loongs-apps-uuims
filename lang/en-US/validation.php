<?php

declare(strict_types=1);

/**
 * en-US / validation.php — Validation messages. {label} is the translated field label, {arg} the rule argument.
 * Keys are the Chinese source texts; {name} placeholders are filled by loongs/language.
 * Files in this directory are merged by filename; a later file overrides the same key (README §19).
 */

return [
    '{label}不能为空' => '{label} is required',
    '{label}必须是字符串' => '{label} must be a string',
    '{label}必须是整数' => '{label} must be an integer',
    '{label}必须是布尔值' => '{label} must be a boolean',
    '{label}必须是数组' => '{label} must be an array',
    '{label}不能小于 {arg}' => '{label} must be at least {arg}',
    '{label}不能大于 {arg}' => '{label} must be at most {arg}',
    '{label}取值无效' => '{label} is invalid',
    '{label}格式不正确' => '{label} has an invalid format',
];
