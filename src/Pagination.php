<?php

declare(strict_types=1);

namespace Fiz;

/**
 * List endpoints answer one page as a plain array. These walk the pages until
 * an empty one: stopping at a short page would be one request cheaper, but a
 * server-side cap below the requested size would then end the walk early and
 * silently drop records.
 */
final class Pagination
{
    /** Records per request while `listAll` walks the pages. */
    public const DEFAULT_PAGE_SIZE = 100;

    /**
     * For endpoints paged with `offset` and `limit`.
     *
     * @template T
     * @param callable(array<string, mixed>): list<T> $list
     * @param array<string, mixed> $query the filters, and `pageSize` (default 100)
     * @return \Generator<int, T, mixed, void>
     */
    public static function byOffset(callable $list, array $query): \Generator
    {
        [$pageSize, $filters] = self::split($query);
        $offset = 0;
        while (true) {
            $page = $list([...$filters, 'offset' => $offset, 'limit' => $pageSize]);
            if ($page === []) {
                return;
            }
            foreach ($page as $item) {
                yield $item;
            }
            $offset += count($page);
        }
    }

    /**
     * Same as `byOffset`, for endpoints that number pages from 1.
     *
     * @template T
     * @param callable(array<string, mixed>): list<T> $list
     * @param array<string, mixed> $query
     * @return \Generator<int, T, mixed, void>
     */
    public static function byPage(callable $list, array $query): \Generator
    {
        [$pageSize, $filters] = self::split($query);
        for ($number = 1;; $number++) {
            $page = $list([...$filters, 'page' => $number, 'pageSize' => $pageSize]);
            if ($page === []) {
                return;
            }
            foreach ($page as $item) {
                yield $item;
            }
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array{mixed, array<string, mixed>}
     */
    private static function split(array $query): array
    {
        $pageSize = $query['pageSize'] ?? self::DEFAULT_PAGE_SIZE;
        unset($query['pageSize']);
        return [$pageSize, $query];
    }
}
