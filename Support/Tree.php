<?php

declare(strict_types=1);

namespace App\Uuims\Support;

final class Tree
{
    /**
     * @param list<array<string, mixed>> $rows each with id, parent_id
     * @return list<array<string, mixed>> nested with `children`
     */
    public static function build(array $rows, int $root = 0): array
    {
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) $r['parent_id']][] = $r;
        }
        $walk = static function (int $pid) use (&$walk, $byParent): array {
            $out = [];
            foreach ($byParent[$pid] ?? [] as $r) {
                $r['children'] = $walk((int) $r['id']);
                $out[] = $r;
            }

            return $out;
        };

        return $walk($root);
    }

    /**
     * Ids of $id and all its descendants.
     *
     * @param list<array{id: int|string, parent_id: int|string}> $rows
     * @return list<int>
     */
    public static function descendants(array $rows, int $id): array
    {
        $children = [];
        foreach ($rows as $r) {
            $children[(int) $r['parent_id']][] = (int) $r['id'];
        }
        $out = [];
        $stack = [$id];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if (in_array($cur, $out, true)) {
                continue;
            }
            $out[] = $cur;
            foreach ($children[$cur] ?? [] as $c) {
                $stack[] = $c;
            }
        }

        return $out;
    }
}
