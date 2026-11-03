<?php

declare(strict_types=1);

namespace App\Uuims\Pages\Sample;

use App\Uuims\Samples\SampleTenantStatus;
use Loongs\Render\AsPage;
use Loongs\Render\Builder\Detail;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Block;
use Loongs\Render\Component\Chart;
use Loongs\Render\Component\Column;
use Loongs\Render\Component\Tab;
use Loongs\Render\DetailPage;
use Loongs\Render\PageContext;

/**
 * 租户详情（样例） — SAMPLE detail page (RENDER.md §6.4, R3). Backed by fixed fixtures
 * (App\Uuims\Samples\SampleTenants, ids 1001–1003, Modules/Sample routes); no tenant tables exist.
 * Opened from 平台概览 → 快捷入口 (openDetail) or /p/uuims.sample.tenant-detail?id=1001.
 * The real tenant detail page arrives with the tenant module (P2).
 */
#[AsPage('uuims.sample.tenant-detail', permission: 'dashboard:overview')]
final class TenantDetailPage extends DetailPage
{
    public function build(PageContext $ctx): Detail
    {
        $api = '/uuims/api/samples/tenants/{id}';

        return Detail::make('租户详情（样例）')
            ->breadcrumb(['平台概览', '租户详情（样例）'])
            ->load($api)
            ->header('{name}', [
                Column::tag('status', '状态')->enum(SampleTenantStatus::class),
                Column::badge('plan', '套餐'),
            ], [
                Action::request('续期 30 天', 'POST', "{$api}/renew")
                    ->confirm('为 {name} 续期 30 天？（样例操作，不会写入数据）')->message('样例操作：未写入任何数据')->then('refresh')->primary()
                    ->hiddenWhen('status', 'eq', 3),
                Action::refresh('刷新'),
                Action::navigate('返回概览', '/dashboard'),
            ])
            ->tabs([
                Tab::make('基本信息', [
                    Block::alert('这是样例租户（固定演示数据，不写数据库）。租户模块（P2）上线后由真实详情页替代。', 'warning'),
                    Block::descriptions([
                        Column::text('id', '租户ID'),
                        Column::copyable('code', '租户编码'),
                        Column::text('name', '名称'),
                        Column::text('contact', '联系人'),
                        Column::copyable('mobile', '手机号'),
                        Column::text('email', '邮箱'),
                        Column::text('region', '部署区域'),
                        Column::text('domain', '访问域名'),
                        Column::bool('auto_renew', '自动续费'),
                    ], 3, '租户资料'),
                    Block::descriptions([
                        Column::tag('status', '状态')->enum(SampleTenantStatus::class),
                        Column::text('plan', '套餐'),
                        Column::money('balance', '账户余额')->format('{value|money:CNY}'),
                        Column::text('seats', '席位上限')->format('{value} 席'),
                        Column::progress('seat_usage', '席位使用率'),
                        Column::datetime('opened_at', '开通时间')->format('yyyy-MM-dd'),
                        Column::datetime('expires_at', '到期时间')->format('yyyy-MM-dd HH:mm'),
                    ], 3, '套餐与计费'),
                    Block::markdown("备注：{remark}\n\n数据来源：App\\Uuims\\Samples\\SampleTenants（样例）"),
                ]),
                Tab::make('用量统计', [
                    Block::stat('近30天 API 调用', "{$api}/usage-summary", 'api_calls')->trend('mom')->format('{value|thousands}')->span(8),
                    Block::stat('用户数', "{$api}/usage-summary", 'users')->trend('users_mom')->span(8),
                    Block::stat('存储用量', "{$api}/usage-summary", 'storage_gb')->format('{value} GB')->span(8),
                    Chart::area('近12个月 API 调用（样例）')
                        ->api("{$api}/usage")
                        ->x('month')
                        ->series('api_calls', 'API 调用', color: '#2563eb|#60a5fa', smooth: true)
                        ->yAxis('left', min: 0, format: '{value|thousands}')
                        ->markArea(date('Y-m', strtotime('first day of -2 months')), date('Y-m'), '近三个月', color: '#f59e0b33|#fbbf2433')
                        ->tooltip(format: '{x}: {value|thousands} 次')
                        ->height(280)->span(16),
                    Chart::pie('功能用量占比（样例）')
                        ->api("{$api}/features")
                        ->x('feature')->series('count', '次数')
                        ->labels(true, '{percent|percent:0}')->legend('right')
                        ->height(280)->span(8),
                    Chart::bar('每月新增用户（样例）')
                        ->api("{$api}/usage")
                        ->x('month')
                        ->series('users_new', '新增用户', color: '#10b981|#34d399', labels: true)
                        ->yAxis('left', min: 0)
                        ->height(260)->span(24),
                ]),
                Tab::make('套餐记录', [
                    Block::table('uuims.sample.tenant-plan-logs', ['tenant_id' => '{id}']),
                ]),
                Tab::make('操作日志', [
                    Block::timeline("{$api}/logs", 'time', 'content'),
                ]),
            ]);
    }
}
