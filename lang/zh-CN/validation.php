<?php

declare(strict_types=1);

/**
 * zh-CN / validation.php — Validation messages. {label} is the translated field label, {arg} the rule argument.
 * zh-CN shows the Chinese source text (the translation key) explicitly, because the fallback locale is en-US.
 * Files in this directory are merged by filename; a later file overrides the same key (README §19).
 */

return [
    '{label}不能为空' => '{label}不能为空',
    '{label}必须是字符串' => '{label}必须是字符串',
    '{label}必须是整数' => '{label}必须是整数',
    '{label}必须是布尔值' => '{label}必须是布尔值',
    '{label}必须是数组' => '{label}必须是数组',
    '{label}不能小于 {arg}' => '{label}不能小于 {arg}',
    '{label}不能大于 {arg}' => '{label}不能大于 {arg}',
    '{label}取值无效' => '{label}取值无效',
    '{label}格式不正确' => '{label}格式不正确',
];
