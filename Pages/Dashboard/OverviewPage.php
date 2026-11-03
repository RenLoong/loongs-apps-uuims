<?php

declare(strict_types=1);

namespace App\Uuims\Pages\Dashboard;

use Loongs\Render\AsPage;
use Loongs\Render\Builder\Dashboard;
use Loongs\Render\Component\Action;
use Loongs\Render\Component\Block;
use Loongs\Render\Component\Chart;
use Loongs\Render\DashboardPage;
use Loongs\Render\PageContext;

/**
 * 平台概览 (RENDER.md §6.1, R3). Menu /dashboard (loongs.json page uuims.dashboard.overview).
 * Every block loads its own API (Modules/Dashboard routes); one failing block does not affect the others.
 *
 * - Real data: uuims / role / department / WeChat counts, 30-day uuims trend, status share, per-department
 *   counts (uuims data scope applies).
 * - SAMPLE data (titles marked 样例): tenant charts until the tenant module (P2) exists.
 */
#[AsPage('uuims.dashboard.overview', permission: 'dashboard:overview')]
final class OverviewPage extends DashboardPage
{
    public function build(PageContext $ctx): Dashboard
    {
        $api = '/uuims/api/dashboard/overview';
        $sample = '/uuims/api/samples/overview';

        return Dashboard::make('平台概览')
            ->breadcrumb(['平台概览'])
            ->blocks([
                Block::stat('管理员总数', "{$api}/stats", 'users')->trend('mom')->format('{value|thousands}')->span(6)->refresh(60)->id('stat_users'),
                Block::stat('正常管理员', "{$api}/stats", 'enabled')->format('{value} 人')->span(6),
                Block::stat('角色数', "{$api}/stats", 'roles')->span(6),
                Block::stat('部门数', "{$api}/stats", 'depts')->span(6),

                Chart::line('近30天管理员新增 / 最近登录')
                    ->api("{$api}/uuims-trend")
                    ->x('date', type: 'time', format: 'MM-dd')
                    // series colors are "light|dark" pairs (render Color::pair): the clients pick the variant for their theme
                    ->series('created', '新增管理员', color: '#2563eb|#60a5fa', area: true, smooth: true)
                    ->series('active', '最近登录（按最后登录日）', color: '#f59e0b|#fbbf24', dashed: true, axis: 'right')
                    ->yAxis('left', min: 0, format: '{value}', name: '新增')
                    ->yAxis('right', min: 0, format: '{value} 人', name: '登录')
                    ->legend('top')->tooltip(shared: true, format: '{series}: {value}')
                    ->emptyText('近30天暂无数据')
                    ->renderer('fl_chart', ['lineWidth' => 2, 'dotSize' => 2])
                    ->height(300)->span(16)->id('user_trend'),
                Chart::donut('管理员状态')
                    ->api("{$api}/uuims-status")
                    ->x('name')->series('count', '人数')
                    ->labels(true, '{name} {percent|percent:0}')
                    ->palette(['#16a34a', '#ef4444'])->legend('bottom')
                    ->renderer('fl_chart', ['centerSpaceRadius' => 42, 'sectionsSpace' => 2])
                    ->height(300)->span(8),

                Chart::stackedBar('各部门管理员（按状态）')
                    ->api("{$api}/dept-users")
                    ->x('name')
                    ->series('enabled', '正常', color: '#16a34a|#4ade80', stack: 'status')
                    ->series('disabled', '停用', color: '#ef4444|#f87171', stack: 'status')
                    ->yAxis('left', min: 0)->legend('top')->tooltip(format: '{x} {series}: {value}')
                    ->renderer('fl_chart', ['barWidth' => 22, 'barRadius' => 3])
                    ->height(280)->span(16),
                Block::rank('部门人数排行', "{$api}/dept-users", 'name', 'count', 5)->format('{value} 人')->span(8),

                Block::alert('以下区块为样例数据（标题带“样例”）：租户模块（P2）上线后替换为真实统计。', 'info', '样例数据')->span(24),
                Chart::combo('租户开通与续费率（样例）')
                    ->api("{$sample}/tenant-opens")
                    ->x('month', format: '{value}')
                    ->series('opens', '开通数', color: '#2563eb|#60a5fa', type: 'bar', labels: true)
                    ->series('renew_rate', '续费率', color: '#10b981|#34d399', type: 'line', smooth: true, axis: 'right', format: '{value|percent:1}')
                    ->yAxis('left', min: 0, name: '开通')
                    ->yAxis('right', min: 0, max: 1, format: '{value|percent:0}', name: '续费率')
                    ->markLine(30, '月目标 30', color: '#ef4444|#f87171')
                    ->legend('top')->tooltip(shared: true)
                    ->height(300)->span(16),
                Chart::gauge('系统健康度（样例）')
                    ->api("{$sample}/health")
                    ->series('score', '健康度', color: '#16a34a|#4ade80', format: '{value} 分')
                    ->height(300)->span(8),
                Chart::radar('租户活跃画像（样例）')
                    ->api("{$sample}/tenant-profile")
                    ->x('metric')
                    ->series('demo_hz', '杭州示例', color: '#2563eb|#60a5fa')
                    ->series('demo_sz', '深圳样例', color: '#f59e0b|#fbbf24')
                    ->legend('bottom')
                    ->height(300)->span(12),
                Chart::scatter('席位数 vs 月活（样例）')
                    ->api("{$sample}/tenant-scale")
                    ->x('seats', type: 'value', format: '{value} 席', name: '席位数')
                    ->series('mau', '月活', color: '#7c3aed|#a78bfa')
                    ->yAxis('left', min: 0, name: '月活')
                    ->tooltip(format: '{x} → {value} 月活')
                    ->renderer('fl_chart', ['dotSize' => 6])
                    ->height(300)->span(12),

                Block::shortcuts([
                    Action::navigate('管理员管理', '/system/users')->icon('users'),
                    Action::navigate('角色管理', '/system/roles')->icon('shield'),
                    Action::openDetail('样例租户详情', 'uuims.sample.tenant-detail', ['id' => 1001])->icon('building'),
                    Action::refresh('刷新管理员统计', 'stat_users')->icon('refresh-cw'),
                ], '快捷入口')->span(24),
            ]);
    }
}
