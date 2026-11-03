<?php

declare(strict_types=1);

namespace App\Uuims\Console;

use App\Uuims\Support\OAuth;
use Loongs\Console\Command;
use Loongs\OAuth\Storage\OrmStorage;
use Loongs\Orm\Orm;
use Loongs\Orm\Support\DatabaseEnsurer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ./loongs uuims:install [--username=uuims] [--reset-password]
 *
 * Idempotent platform install on the default connection (user_platform):
 *   1. OAUTH_TOKEN_PEPPER in .env (generated once, never printed)
 *   2. platform tables (database/platform/schema.sql, CREATE TABLE IF NOT EXISTS)
 *   3. loongs/oauth tables (OrmStorage::installOn, same as ./loongs oauth:install)
 *   4. seed: departments, controllable fields (menus + buttons are files: apps/<App>/loongs.json)
 *   4b. upgrade from the menus table (≤ P1): role_menu grants → role_permission codes, then drop
 *       role_menu + menus
 *   5. super uuims with a random password → runtime/user_initial_password (0600, never echoed)
 *   6. public OAuth clients from config/uuims.php (uuims-pc, uuims-mobile; upsert)
 */
#[AsCommand(name: 'uuims:install', description: 'Create/upgrade user_platform tables, seed depts, super uuims and OAuth clients (idempotent)')]
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('username', null, InputOption::VALUE_REQUIRED, 'Super uuims username', 'uuims')
            ->addOption('reset-password', null, InputOption::VALUE_NONE, 'Generate a new password for the existing super uuims');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->io($input, $output);
        $base = $this->basePath();
        $rows = [];

        $pepper = $this->ensurePepper($base . '/.env');
        $rows[] = ['.env OAUTH_TOKEN_PEPPER', $pepper];

        $db = (static fn (): mixed => require $base . '/config/database.php')();
        Orm::configure(is_array($db) ? $db : []);
        $conn = (string) ($db['default'] ?? 'mysql');

        try {
            $ensured = DatabaseEnsurer::ensureExists($conn);
            if (!$ensured['ok']) {
                $io->error($ensured['message']);

                return self::FAILURE;
            }
            if ($ensured['created']) {
                $rows[] = ['database ' . $ensured['database'], 'created'];
            }

            $c = Orm::connection($conn);
            $prefix = Orm::config($conn)->prefix();

            $sql = str_replace('{{prefix}}', $prefix, (string) file_get_contents($base . '/apps/Uuims/database/platform/schema.sql'));
            $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $stmt, $m);
                $table = $m[1] ?? '?';
                $exists = $c->selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== null;
                if (!$exists) {
                    $c->unprepared($stmt);
                }
                $rows[] = ["table {$table}", $exists ? 'exists' : 'created'];
            }
            foreach (OrmStorage::installOn($conn) as $table => $created) {
                $rows[] = ["table {$table}", $created ? 'created' : 'exists'];
            }

            $rows[] = ['seed depts', $this->seedDepts() . ' new'];
            foreach ($this->migrateMenusTable($c, $prefix) as $row) {
                $rows[] = $row;
            }
            $rows[] = ['seed resource_fields', $this->seedFields() . ' new'];
            $rows[] = ['super uuims', $this->superUuims($base, (string) $input->getOption('username'), (bool) $input->getOption('reset-password'))];

            $uuimsCfg = (static fn (): mixed => require $base . '/config/uuims.php')();
            $storage = new OrmStorage($conn);
            foreach (OAuth::clients((array) $uuimsCfg['oauth']) as $client) {
                $storage->saveClient($client);
                $rows[] = ["oauth client {$client->id}", 'public, PKCE S256, redirect: ' . implode(' ', $client->redirectUris)];
            }
        } catch (\Throwable $e) {
            $io->error($e::class . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        $io->writeln('uuims:install on ' . Orm::resolver()->spec($conn)->describe());
        $io->table(['step', 'result'], $rows);
        $io->success('user_platform ready.');

        return self::SUCCESS;
    }

    private function ensurePepper(string $envFile): string
    {
        $text = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        if (preg_match('/^OAUTH_TOKEN_PEPPER=(.+)$/m', $text, $m) === 1 && trim($m[1]) !== '') {
            return 'exists (unchanged)';
        }
        $line = 'OAUTH_TOKEN_PEPPER=' . bin2hex(random_bytes(32));
        $text = preg_match('/^OAUTH_TOKEN_PEPPER=.*$/m', $text) === 1
            ? (string) preg_replace('/^OAUTH_TOKEN_PEPPER=.*$/m', $line, $text)
            : rtrim($text, "\n") . "\n\n# loongs/oauth token HMAC pepper (generated by uuims:install; never change)\n" . $line . "\n";
        file_put_contents($envFile, $text);
        @chmod($envFile, 0600);

        return 'generated (value not shown)';
    }

    private function seedDepts(): int
    {
        $new = 0;
        $now = date('Y-m-d H:i:s');
        $ensure = function (string $name, int $parentId, int $sort) use (&$new, $now): int {
            $row = Orm::table('uuims_depts')->where('name', $name)->where('parent_id', $parentId)->first();
            if ($row !== null) {
                return (int) $row['id'];
            }
            $ancestors = '0';
            if ($parentId > 0) {
                $ancestors = Orm::table('uuims_depts')->where('id', $parentId)->value('ancestors') . ',' . $parentId;
            }
            $new++;

            return (int) Orm::table('uuims_depts')->insertGetId(['parent_id' => $parentId, 'ancestors' => $ancestors, 'name' => $name,
                'sort' => $sort, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        };
        $root = $ensure('LOONGS 平台', 0, 0);
        $rd = $ensure('研发部', $root, 1);
        $ensure('研发一组', $rd, 1);
        $ensure('运营部', $root, 2);

        return $new;
    }

    /**
     * P1 kept menus in a table and grants in role_menu(menu_id). Menus are files now; grants are
     * permission codes. Copy every granted code once (INSERT IGNORE), then drop both tables.
     * Idempotent: nothing happens when the old tables are gone.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function migrateMenusTable(\Loongs\Orm\Connection\Connection $c, string $prefix): array
    {
        $exists = static fn (string $t): bool => $c->selectOne('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$prefix . $t]) !== null;
        $rows = [];
        if ($exists('role_menu') && $exists('menus')) {
            $n = $c->affectingStatement("INSERT IGNORE INTO `{$prefix}uuims_role_permission` (role_id, code) SELECT rm.role_id, m.perms FROM `{$prefix}role_menu` rm "
                . "JOIN `{$prefix}menus` m ON m.id = rm.menu_id WHERE m.perms IS NOT NULL AND m.perms <> ''");
            $rows[] = ['migrate role_menu → role_permission', "{$n} grants copied"];
        }
        foreach (['role_menu', 'menus'] as $t) {
            if ($exists($t)) {
                $c->unprepared("DROP TABLE `{$prefix}{$t}`");
                $rows[] = ["table {$prefix}{$t}", 'dropped (menus: apps/*/loongs.json)'];
            }
        }

        return $rows;
    }

    private function seedFields(): int
    {
        $new = 0;
        foreach ([['uuims', 'mobile', '手机号'], ['uuims', 'email', '邮箱']] as [$res, $field, $label]) {
            $new += Orm::table('uuims_resource_fields')->insertOrIgnore(['resource' => $res, 'field' => $field, 'label' => $label, 'default_perm' => 'editable']);
        }

        return $new;
    }

    private function superUuims(string $base, string $username, bool $reset): string
    {
        $row = Orm::table('uuims_users')->where('username', $username)->first();
        if ($row !== null && !$reset) {
            return "{$username} exists (password unchanged)";
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 20; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $now = date('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($row === null) {
            $root = (int) Orm::table('uuims_depts')->where('parent_id', 0)->orderBy('id')->value('id');
            Orm::table('uuims_users')->insert(['username' => $username, 'password' => $hash, 'nickname' => '超级管理员', 'dept_id' => $root ?: null,
                'status' => 1, 'is_super' => 1, 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            Orm::table('uuims_users')->where('id', $row['id'])->update(['password' => $hash, 'password_changed_at' => $now, 'is_super' => 1, 'status' => 1, 'updated_at' => $now]);
        }
        $file = $base . '/runtime/user_initial_password';
        $old = umask(0077);
        file_put_contents($file, "username={$username}\npassword={$password}\n");
        umask($old);
        chmod($file, 0600);

        return ($row === null ? 'created' : 'password reset') . ", credentials in runtime/user_initial_password (0600)";
    }
}
