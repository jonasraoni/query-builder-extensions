<?php

declare(strict_types=1);

namespace JonasRaoni\QueryBuilder;

use Illuminate\Database\Query\Builder;

class Extensions
{
    /**
     * Extends the given query builder
     * @param Builder $query
     * @return Builder
     */
    public static function extend(Builder $query): Builder
    {
        $query->macro('bufferedIterator', function () {
            return Extensions::bufferedIterator($this, ...func_get_args());
        });
        $query->macro('paginateLazily', function () {
            return Extensions::paginateLazily($this, ...func_get_args());
        });
        return $query;
    }

    /**
     * Given a Laravel query builder and the amount of rows, it creates a Laravel paginator and lazily retrieve all the rows using a generator.
     * As the resultset is paged, be sure to use a good sorting method, to avoid visiting the same record in a next page.
     * @param Builder $query
     * @param int $rows
     * @return \Generator
     */
    public static function paginateLazily(Builder $query, int $rows): \Generator
    {
        $baseQuery = clone $query;
        $currentPage = 0;
        do {
            $page = $baseQuery->simplePaginate($rows, ['*'], '', ++$currentPage);
            foreach ($page as $row) {
                yield $row;
            }
        } while ($page->hasMorePages());
    }

    /**
     * Creates a generator that will paginate the records internally and retrieve the records.
     * To avoid skipping/reprocessing past records (due to updates), the code keeps track of the last processed record in the page, which means it has some requirements:
     * - The query must be sorted only by the given keys (done automatically by the function)
     * - No duplicated rows are allowed, so when "joined", the keys must be able to perfectly identify a row
     *
     * @param array $sortMap This is used to tell the paginator which fields should be used to sort, and also to help it retrieving the keys for the last record.
     * The "key" is the value which will be used in the ORDER BY clause (the value can be suffixed by ASC/DESC)
     * The "value" must map to a column (available in the SELECT clause) which has the respective sorted data. A callback is also supported, it will receive an object (the last row of the page) and it must return the related data.
     * If the value is suffixed by " desc", it will sorted in a descending way:
     * Example:
     * ['id DESC' => 'id', 'IF(date IS NULL, 0, 1)' => function ($row) { return $row->date ? 1 : 0; }]
     * @param int $rows
     * @return \Generator
     */
    public static function bufferedIterator(Builder $query, array $sortMap, int $rows): \Generator
    {
        // Cloning to avoid side-effects
        $baseQuery = clone $query;
        $count = count($sortMap);
        // Build a filter clause to retrieve the "next pages" based on the last retrieved record
        $filterClause = '';
        $i = 0;
        foreach (array_keys($sortMap) as $sortField) {
            $isDescending = preg_match('/\s(desc|asc)\s*$/i', $sortField, $matches) && strtolower($matches[1] ?? '') === 'desc';
            $baseQuery->orderByRaw($sortField);
            $cleanSortField = substr($sortField, 0, strlen($sortField) - strlen($matches[0] ?? ''));
            $filterClause .= $cleanSortField . ($isDescending ? ' < ' : ' > ') . '?' . (++$i < $count ? ' OR (' . $cleanSortField . ' = ? AND (' : '');
        }
        $filterClause = '(' . $filterClause . str_repeat(')', 2 * ($count - 1)) . ')';
        $lastRow = null;
        do {
            $query = clone $baseQuery;
            // If the first page was processed
            if ($lastRow) {
                // Feed the filter clause with the keys
                $bindings = [];
                $i = 0;
                foreach ($sortMap as $getter) {
                    $value = is_callable($getter) ? $getter($lastRow) : $lastRow->$getter;
                    $bindings[] = $value;
                    if (++$i < $count) {
                        $bindings[] = $value;
                    }
                }
                $query->whereRaw($filterClause, $bindings);
            }
            $results = $query->limit($rows)->get();
            foreach ($results as $lastRow) {
                yield $lastRow;
            }
        } while ($results->count() === $rows);
    }
}
