<?php

declare(strict_types=1);

namespace App\Uuims\Samples;

use App\Uuims\Support\I18n;

/**
 * SAMPLE DATA ONLY (RENDER.md §14 R3). There is no tenant module yet (P2): the platform overview's
 * tenant charts and the 租户详情（样例） page read these fixed, in-memory fixtures. Nothing is stored,
 * no tenant tables exist. Values are deterministic (seeded by tenant id, dates relative to today) so
 * tests can assert them. Remove this class when the P2 tenant module provides real data.
 */
final class SampleTenants
{
    public const IDS = [1001, 1002, 1003];

    private const TENANTS = [
        1001 => ['code' => 'demo-hz', 'name' => '杭州示例科技有限公司', 'contact' => '王小明', 'mobile' => '13800001001', 'email' => 'ops@demo-hz.example',
            'plan' => '专业版', 'plan_level' => 2, 'status' => 1, 'seats' => 50, 'seats_used' => 38, 'balance' => 12880.5, 'auto_renew' => true,
            'domain' => 'https://demo-hz.example', 'region' => '华东（杭州）', 'opened_days' => 400, 'expires_days' => 65],
        1002 => ['code' => 'demo-sz', 'name' => '深圳样例零售连锁', 'contact' => '李华', 'mobile' => '13900001002', 'email' => 'it@demo-sz.example',
            'plan' => '旗舰版', 'plan_level' => 3, 'status' => 1, 'seats' => 200, 'seats_used' => 171, 'balance' => 56000.0, 'auto_renew' => true,
            'domain' => 'https://demo-sz.example', 'region' => '华南（深圳）', 'opened_days' => 720, 'expires_days' => 210],
        1003 => ['code' => 'demo-bj', 'name' => '北京演示教育', 'contact' => '赵敏', 'mobile' => '13700001003', 'email' => 'uuims@demo-bj.example',
            'plan' => '基础版', 'plan_level' => 1, 'status' => 0, 'seats' => 10, 'seats_used' => 9, 'balance' => 0.0, 'auto_renew' => false,
            'domain' => 'https://demo-bj.example', 'region' => '华北（北京）', 'opened_days' => 12, 'expires_days' => 2],
    ];

    public static function has(int $id): bool
    {
        return isset(self::TENANTS[$id]);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $t = self::TENANTS[$id] ?? null;
        if ($t === null) {
            return null;
        }
        $today = new \DateTimeImmutable('today');

        return [
            'id' => $id,
            'code' => $t['code'],
            'name' => $t['name'],
            'contact' => $t['contact'],
            'mobile' => $t['mobile'],
            'email' => $t['email'],
            'plan' => $t['plan'],
            'plan_level' => $t['plan_level'],
            'status' => $t['status'],
            'seats' => $t['seats'],
            'seats_used' => $t['seats_used'],
            'seat_usage' => round($t['seats_used'] / $t['seats'], 4),
            'balance' => $t['balance'],
            'auto_renew' => $t['auto_renew'],
            'domain' => $t['domain'],
            'region' => $t['region'],
            'opened_at' => $today->modify("-{$t['opened_days']} days")->format('Y-m-d 10:00:00'),
            'expires_at' => $today->modify("+{$t['expires_days']} days")->format('Y-m-d 23:59:59'),
            'remark' => '样例租户：数据为固定演示数据，不会写入数据库。',
            'sample' => true,
        ];
    }

    /** Summary numbers of one tenant (stat blocks). */
    public static function usageSummary(int $id): array
    {
        $s = $id - 1000;

        return [
            'api_calls' => 120000 * $s + 34567,
            'api_calls_mom' => round(0.08 * $s - 0.05, 4),
            'users' => 300 * $s + 42,
            'users_mom' => round(0.03 * $s, 4),
            'storage_gb' => round(12.5 * $s + 3.2, 1),
            'sample' => true,
        ];
    }

    /** 12 months of API calls / new users / error rate (area + bar charts). */
    public static function usage(int $id): array
    {
        $s = $id - 1000;
        $rows = [];
        $month = new \DateTimeImmutable('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $m = $month->modify("-{$i} months");
            $k = 12 - $i;
            $rows[] = [
                'month' => $m->format('Y-m'),
                'api_calls' => 8000 * $s + 900 * $k + ($k % 3) * 1500,
                'users_new' => 10 * $s + (($k * 7) % 11) * 3,
                'error_rate' => round(0.002 + (($k * 5) % 7) / 1000, 4),
            ];
        }

        return $rows;
    }

    /** Feature usage share (pie). */
    public static function features(int $id): array
    {
        $s = $id - 1000;

        return [
            ['feature' => '订单', 'count' => 420 * $s],
            ['feature' => '会员', 'count' => 260 * $s + 40],
            ['feature' => '库存', 'count' => 180 * $s + 10],
            ['feature' => '报表', 'count' => 90 * $s + 5],
        ];
    }

    /** Operation log (timeline), newest first. */
    public static function logs(int $id): array
    {
        $t = self::find($id) ?? [];
        $now = new \DateTimeImmutable('today 09:30');

        return [
            ['time' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'content' => I18n::t('管理员 uuims 调整席位上限为 {n}', ['n' => $t['seats'] ?? 0])],
            ['time' => $now->modify('-9 days')->format('Y-m-d H:i:s'), 'content' => I18n::t('自动续费成功（{plan}）', ['plan' => I18n::msg((string) ($t['plan'] ?? ''))])],
            ['time' => $now->modify('-40 days')->format('Y-m-d H:i:s'), 'content' => I18n::t('租户联系人变更为 {name}', ['name' => I18n::msg((string) ($t['contact'] ?? ''))])],
            ['time' => (string) ($t['opened_at'] ?? ''), 'content' => '租户开通'],
        ];
    }

    /** Plan change records (embedded table page), newest first. */
    public static function planLogs(int $id): array
    {
        $t = self::find($id);
        if ($t === null) {
            return [];
        }
        $opened = new \DateTimeImmutable((string) $t['opened_at']);
        $rows = [];
        $n = 0;
        foreach ([['开通', '基础版', 0], ['升级', $t['plan'], 1], ['续费', $t['plan'], 2], ['续费', $t['plan'], 3]] as [$action, $plan, $step]) {
            $at = $opened->modify('+' . ($step * 90) . ' days');
            if ($at > new \DateTimeImmutable('now')) {
                break;
            }
            $rows[] = ['id' => $id * 10 + ++$n, 'tenant_id' => $id, 'action' => $action, 'plan' => $plan,
                'amount' => [0 => 0, 1 => 3999, 2 => 7999, 3 => 7999][$step] * $t['plan_level'], 'operator' => $step === 0 ? '系统' : 'uuims',
                'created_at' => $at->format('Y-m-d H:i:s')];
        }

        return array_reverse($rows);
    }

    // ---------------------------------------------------------------- platform overview samples

    /** 12 months of tenant openings and renewal rate (combo bar + line, dual axis). */
    public static function tenantOpens(): array
    {
        $rows = [];
        $month = new \DateTimeImmutable('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $k = 12 - $i;
            $rows[] = ['month' => $month->modify("-{$i} months")->format('Y-m'), 'opens' => 18 + ($k * 7) % 23 + $k,
                'renew_rate' => round(0.62 + (($k * 3) % 10) / 40, 4)];
        }

        return $rows;
    }

    public static function health(): array
    {
        return ['score' => 92, 'max' => 100, 'sample' => true];
    }

    /** Radar: activity profile of two sample tenants. */
    public static function tenantProfile(): array
    {
        return [
            ['metric' => '登录活跃', 'demo_hz' => 82, 'demo_sz' => 95],
            ['metric' => '订单量', 'demo_hz' => 64, 'demo_sz' => 88],
            ['metric' => '功能覆盖', 'demo_hz' => 71, 'demo_sz' => 79],
            ['metric' => '续费意愿', 'demo_hz' => 90, 'demo_sz' => 84],
            ['metric' => '工单满意度', 'demo_hz' => 76, 'demo_sz' => 69],
        ];
    }

    /** Scatter: seats vs monthly active users. */
    public static function tenantScale(): array
    {
        $rows = [];
        foreach ([[10, 8], [20, 15], [35, 30], [50, 38], [80, 61], [120, 97], [150, 104], [200, 171], [260, 199], [320, 288]] as [$seats, $mau]) {
            $rows[] = ['seats' => $seats, 'mau' => $mau];
        }

        return $rows;
    }
}
