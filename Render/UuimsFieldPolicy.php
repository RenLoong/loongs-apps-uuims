<?php

declare(strict_types=1);

namespace App\Uuims\Render;

use Loongs\Render\Contract\FieldPolicy;
use Loongs\Render\FieldAccess;
use Loongs\Render\PageContext;

/**
 * Field permissions of the P1 permission context (resource_fields + role_field, broadest role wins,
 * already merged by PermissionService): hidden → removed, masked → masked + read-only, readonly →
 * read-only. Super uuims and uncontrolled fields are editable.
 */
final class UuimsFieldPolicy implements FieldPolicy
{
    public function access(string $resource, string $field, PageContext $ctx): FieldAccess
    {
        $u = $ctx->user;
        if (!is_array($u) || ($u['is_super'] ?? false) === true) {
            return FieldAccess::Editable;
        }

        return match ($u['fields'][$resource][$field] ?? 'editable') {
            'hidden' => FieldAccess::Hidden,
            'masked' => FieldAccess::Masked,
            'readonly' => FieldAccess::Readonly,
            default => FieldAccess::Editable,
        };
    }
}
