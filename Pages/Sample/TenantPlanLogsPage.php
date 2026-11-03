<?php

declare(strict_types=1);

namespace App\Uuims\Pages\Sample;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 套餐记录（样例） — SAMPLE table page embedded in the 租户详情（样例） page (Block::table with
 * tenant_id). Fixture data from App\Uuims\Samples\SampleTenants; replaced in P2.
 */
#[AsPage('uuims.sample.tenant-plan-logs', permission: 'dashboard:overview')]
final class TenantPlanLogsPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('套餐记录（样例）')
            ->api('/uuims/api/samples/tenants/plan-logs')
            ->rowKey('id')
            ->search([
                Field::select('action', '类型')->options(['开通' => '开通', '升级' => '升级', '续费' => '续费']),
            ])
            ->columns([
                Column::text('id', '记录ID')->width(90),
                Column::tag('action', '类型')->options([
                    ['value' => '开通', 'label' => '开通', 'color' => 'info'],
                    ['value' => '升级', 'label' => '升级', 'color' => 'success'],
                    ['value' => '续费', 'label' => '续费', 'color' => 'primary'],
                ]),
                Column::text('plan', '套餐'),
                Column::money('amount', '金额')->format('{value|money:CNY}'),
                Column::text('operator', '操作人'),
                Column::datetime('created_at', '时间')->format('yyyy-MM-dd HH:mm'),
            ])
            ->pageSizes([10, 20]);
    }
}
