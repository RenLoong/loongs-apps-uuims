<?php

declare(strict_types=1);

namespace App\Uuims\Models\Concerns;

use App\Uuims\Support\UuimsContext;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\I18n;
use App\Uuims\Support\Mask;

/**
 * Field-level permission in the model layer (README §3): the model declares
 * `protected string $fieldResource` (resource name in resource_fields). For the current uuims:
 *   toArray(): hidden → removed, masked → masked value, readonly / editable → as is
 *   fill():    only editable fields may be written; a non-editable field is accepted only when it
 *              is unchanged (same value or the masked value it was shown as), else 403 (40302).
 * No uuims context / super uuims → unrestricted.
 */
trait HasFieldPermissions
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = parent::toArray();
        $ctx = UuimsContext::get();
        if ($ctx === null || $ctx['is_super']) {
            return $out;
        }
        foreach ($ctx['fields'][$this->fieldResource] ?? [] as $field => $perm) {
            if (!array_key_exists($field, $out)) {
                continue;
            }
            if ($perm === 'hidden') {
                unset($out[$field]);
            } elseif ($perm === 'masked') {
                $out[$field] = Mask::value($field, $out[$field]);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): static
    {
        $ctx = UuimsContext::get();
        if ($ctx !== null && !$ctx['is_super']) {
            foreach ($ctx['fields'][$this->fieldResource] ?? [] as $field => $perm) {
                if ($perm === 'editable' || !array_key_exists($field, $attributes)) {
                    continue;
                }
                $new = $attributes[$field];
                $old = $this->exists ? $this->getAttribute($field) : null;
                $unchanged = $this->exists && ((string) $new === (string) $old || (string) $new === (string) Mask::value($field, $old));
                if (!$unchanged && !(!$this->exists && ($new === null || $new === ''))) {
                    throw new ApiException(40302, I18n::t('无权修改字段：{field}（{perm}）', ['field' => $field, 'perm' => $perm]), 403, ['field' => $field, 'perm' => $perm]);
                }
                unset($attributes[$field]);
            }
        }

        return parent::fill($attributes);
    }

    public function fieldResource(): string
    {
        return $this->fieldResource;
    }
}
