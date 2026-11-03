<?php

declare(strict_types=1);

namespace App\Uuims\Models\Concerns;

use App\Uuims\Support\UuimsContext;
use Loongs\Orm\Model\Builder;

/**
 * Row-level data scope (global scope "data_scope"). Uses the current uuims's merged data scope
 * (broadest across roles): all → no condition; otherwise dept column IN (allowed depts) OR owner = me.
 * No uuims context (CLI / install / login) or super uuims → no condition.
 *
 * Models override dataScopeColumns(): ['dept' => 'dept_id', 'owner' => 'created_by'].
 * Updates / deletes must load the row through a scoped query first (out-of-scope → 404).
 */
trait HasDataScope
{
    public static function bootHasDataScope(): void
    {
        static::addGlobalScope('data_scope', static function (Builder $b): void {
            $ctx = UuimsContext::get();
            if ($ctx === null || $ctx['is_super'] || $ctx['data']['all']) {
                return;
            }
            $model = $b->getModel();
            $cols = $model->dataScopeColumns();
            $deptIds = $ctx['data']['dept_ids'];
            $self = $ctx['data']['self'];
            if ($deptIds === [] && !$self) {
                $b->whereRaw('1 = 0');

                return;
            }
            $b->where(static function (Builder $w) use ($model, $cols, $deptIds, $self, $ctx): void {
                if ($deptIds !== []) {
                    $w->whereIn($model->qualifyColumn($cols['dept']), $deptIds);
                }
                if ($self) {
                    $w->orWhere($model->qualifyColumn($cols['owner']), (int) $ctx['id']);
                }
            });
        });
    }

    /** @return array{dept: string, owner: string} */
    public function dataScopeColumns(): array
    {
        return ['dept' => 'dept_id', 'owner' => 'created_by'];
    }

    /** Whether a department id is inside the current uuims's data scope (for create / move). */
    public static function deptInScope(?int $deptId): bool
    {
        $ctx = UuimsContext::get();
        if ($ctx === null || $ctx['is_super'] || $ctx['data']['all']) {
            return true;
        }

        return $deptId !== null && in_array($deptId, $ctx['data']['dept_ids'], true);
    }
}
