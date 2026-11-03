<?php

declare(strict_types=1);

namespace App\Uuims\Modules\Sample\Controller;

use App\Uuims\Samples\SampleTenants;
use App\Uuims\Support\Api;
use App\Uuims\Support\ApiException;
use App\Uuims\Support\I18n;
use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * SAMPLE tenant API behind the 租户详情（样例） page (uuims.sample.tenant-detail, RENDER.md §14).
 * Read-only fixtures from SampleTenants (ids 1001–1003); nothing is persisted. Replaced by the real
 * tenant module in P2.
 */
final class SampleTenantController
{
    /**
     * GET ?page=&page_size=&keyword=&status= → page format (租户列表（样例）, P5 mobile 租户 tab +
     * PC /p/uuims.sample.tenants). Fixtures only (SampleTenants::IDS), labelled sample.
     */
    public function index(Request $request): Response
    {
        [$page, $size] = Api::pagination($request);
        $kw = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');
        $rows = [];
        foreach (SampleTenants::IDS as $id) {
            $t = SampleTenants::find($id);
            if ($kw !== '' && !str_contains($t['name'], $kw) && !str_contains($t['code'], $kw) && !str_contains($t['contact'], $kw)) {
                continue;
            }
            if ($status !== null && $status !== '' && (int) $status !== $t['status']) {
                continue;
            }
            $rows[] = $t;
        }

        return Api::ok(['list' => I18n::deep(array_slice($rows, ($page - 1) * $size, $size)), 'total' => count($rows), 'page' => $page, 'page_size' => $size, 'sample' => true]);
    }

    public function show(Request $request): Response
    {
        return Api::ok(I18n::deep($this->tenant($request)));
    }

    public function usageSummary(Request $request): Response
    {
        return Api::ok(I18n::deep(SampleTenants::usageSummary((int) $this->tenant($request)['id'])));
    }

    public function usage(Request $request): Response
    {
        return Api::ok(I18n::deep(SampleTenants::usage((int) $this->tenant($request)['id'])));
    }

    public function features(Request $request): Response
    {
        return Api::ok(I18n::deep(SampleTenants::features((int) $this->tenant($request)['id'])));
    }

    public function logs(Request $request): Response
    {
        return Api::ok(I18n::deep(SampleTenants::logs((int) $this->tenant($request)['id'])));
    }

    /** GET plan-logs?tenant_id= → page format {list,total,page,page_size} (embedded table page). */
    public function planLogs(Request $request): Response
    {
        [$page, $size] = Api::pagination($request);
        $id = (int) $request->query('tenant_id', 0);
        if (!SampleTenants::has($id)) {
            throw ApiException::validation(['tenant_id' => '样例租户不存在']);
        }
        $rows = SampleTenants::planLogs($id);
        $action = trim((string) $request->query('action', ''));
        if ($action !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['action'] === $action));
        }

        return Api::ok(['list' => I18n::deep(array_slice($rows, ($page - 1) * $size, $size)), 'total' => count($rows), 'page' => $page, 'page_size' => $size, 'sample' => true]);
    }

    /** Sample action: validates the tenant and answers, but writes nothing. */
    public function renew(Request $request): Response
    {
        $t = $this->tenant($request);

        return Api::ok(['id' => $t['id'], 'sample' => true], '样例操作：未写入任何数据');
    }

    private function tenant(Request $request): array
    {
        return SampleTenants::find(Api::id($request)) ?? throw ApiException::notFound();
    }
}
