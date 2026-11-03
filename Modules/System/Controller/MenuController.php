<?php

declare(strict_types=1);

namespace App\Uuims\Modules\System\Controller;

use App\Uuims\Services\PermissionService;
use App\Uuims\Support\Api;
use App\Uuims\Support\I18n;
use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * Read-only merged menu tree (dirs, menus, buttons) of every apps/<App>/loongs.json. Menus are edited
 * in those files (reviewed with the code), not in the database; `./loongs uuims:menus` validates them.
 */
final class MenuController
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request): Response
    {
        $registry = $this->permissions->registry();

        return Api::ok(I18n::tree($registry->tree()), 'ok');
    }

    /** Where the tree comes from (files + content hash), for the read-only menus page. */
    public function sources(Request $request): Response
    {
        $registry = $this->permissions->registry();

        return Api::ok(['files' => $registry->files(), 'hash' => $registry->hash(), 'codes' => count($registry->codes())]);
    }
}
