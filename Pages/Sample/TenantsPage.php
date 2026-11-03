<?php

declare(strict_types=1);

namespace App\Uuims\Pages\Sample;

use App\Uuims\Samples\SampleTenantStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Table;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Field;
use Loongs\Render\PageContext;
use Loongs\Render\TablePage;

/**
 * 租户列表（样例） — SAMPLE read-only list (P5 mobile tab 租户; PC /p/uuims.sample.tenants).
 * Backed by the R3 fixtures (App\Uuims\Samples\SampleTenants, ids 1001–1003, GET
 * /uuims/api/samples/tenants); no tenant tables exist. Replaced by the tenant module (P3).
 * Mobile: the ->mobile() columns become the card fields; 详情 opens uuims.sample.tenant-detail.
 */
#[AsPage('uuims.sample.tenants', permission: 'dashboard:overview')]
final class TenantsPage extends TablePage
{
    public function build(PageContext $ctx): Table
    {
        return Table::make('租户状态（样例）')
            ->breadcrumb(['平台概览', '租户状态（样例）'])
            ->api('/uuims/api/samples/tenants')
            ->rowKey('id')
            ->notice('样例数据：租户模块（P3）上线前展示固定演示租户，不读写数据库。', null, 'warning')
            ->search([
                Field::text('keyword', '名称/编码/联系人'),
                Field::select('status', '状态')->options(SampleTenantStatus::class),
            ])
            ->columns([
                Column::text('id', '租户ID')->width(90),
                Column::text('name', '名称')->mobile(),
                Column::copyable('code', '编码'),
                Column::tag('status', '状态')->enum(SampleTenantStatus::class)->mobile(),
                Column::badge('plan', '套餐')->mobile(),
                Column::progress('seat_usage', '席位使用率'),
                Column::money('balance', '余额')->format('{value|money:CNY}'),
                Column::datetime('expires_at', '到期时间')->format('yyyy-MM-dd')->mobile(),
            ])
            ->rowActions([
                Action::openDetail('详情', 'uuims.sample.tenant-detail', ['id' => '{id}']),
            ])
            ->pageSizes([10, 20, 50]);
    }
}
