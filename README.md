# Extensions for the Laravel `Illuminate\Database\Query\Builder`.

Currently only provides extensions to make it easier to work with paged result sets.
Both methods don't buffer the result set, so once a page is consumed, a new query will be issued to retrieve the next page.

The package also has a safer method to count records (`getCount()`), which is supposed to work faster (by dropping `ORDER BY` clause) and be more reliable (works with `GROUP BY` clauses) than the Laravel's `count()` method.


## Safely count records with the getCount()

```php
getCount(): int
```

If you've got a `SELECT field FROM test GROUP BY field`, the Laravel's `count()` will convert it to `SELECT COUNT(0) FROM test GROUP BY field`, which might retrieve N records. Laravel will retrieve only the value for the first record, which will break user expectations.

This `getCount()` method will instead generate a `SELECT COUNT(0) FROM (SELECT 0 FROM test GROUP BY field)` which will retrieve the proper record count.

## Lazy Paginator

```php
paginateLazily(Builder $query, int $rows): \Generator
```

Retrieves a generator that will run through all the records of every page (broken by `$rows`).

The method will not touch your query, it's just a helper, so you must add the sorting by yourself.


## Dynamic Paginator

```php
bufferedIterator(Builder $query, array $sortMap, int $rows): \Generator
```

It does the same as the previous method... But:
- It's probably much more performatic, as it doesn't use the `LIMIT` clause, which gets slower as you advances through the pages.
- Ensure that past records will not be revisited and also that records will not be lost/skipped, due to updates happening against previous pages (removed/inserted/updated records that provokes a shift effect).

So I think it's great to be used when processing a large result set, as you can consume the data on demand without having much issues due to updates.

### Details

To avoid skipping/reprocessing past records, the code keeps track of the last processed record in the page.

#### `array $sortMap`

This argument is used to tell the paginator which fields should be used to sort, and also to help it retrieving the key values for the last record (so it knows what to skip in the next page...).
- The "key" represents the value which will be used in the `ORDER BY` clause (so it can be a valid `ORDER BY` field, such as `table.field DESC`)
- The "value" must be mapped to a field name, available in the `SELECT` clause, which holds the same value used by the `ORDER BY` expression. A callable is also supported (receives an object, the last record of the page, and must return the expected data).

```php
use JonasRaoni\QueryBuilder\Extensions;

Extensions::extend();
$records = $connection->table('posts')
    ->select('id', 'date', 'title')
    // Will produce an "ORDER BY id DESC, IF(date IS NULL, 0, 1)"
    ->bufferedIterator(
        [
            // Maps the given sort expression to the "id" field (must be available in the "SELECT")
            'id DESC' => 'id',
            // Maps the given sort expression using a callable
            'IF(date IS NULL, 0, 1)' => function ($record) {
                return $record->date ? 1 : 0;
            }
        ]
    );

foreach ($records as $record) {
    echo $record->id;
}
```


### Requirements

- The query must be sorted **only** by the given fields/keys (the method adds the sorting by itself, so it's not needed to do it manually).
- **No duplicated rows are allowed!** It might cause an unexpected behavior. The values from the `$sortMap` must be able to perfectly identify a row (so **watch out** for case-insensitive comparisons, such as `'a' > 'A'`).

PS: In case it's not clear, here's an example of the issue you might face when using a standard paging method:
- Suppose you have a paged result set based on the query:
`SELECT * FROM posts WHERE active = 1 ORDER BY id`
- If you're, let's say, in the page 10 and someone updates all the records that you visited in previous pages (e.g. `UPDATE posts SET active = 0 WHERE id < :lastVisitedId`), then when you advance to the page 11, you'll have a little surprise! Some records will be skipped... And the inverse also might happen, after someone doing an operation that adds records to the previous pages (e.g. `UPDATE posts SET active = 1 WHERE active = 0 AND id < :lastVisitedId`).

## General Usage

Install the package:

```
composer require jonasraoni/query-builder-extensions
```

The package just has one class (`JonasRaoni\QueryBuilder\Extensions`) which can be used in two ways:

### A. Macro extensions

Call the `extend` method, to extend all Builder instances:

```php
use JonasRaoni\QueryBuilder\Extensions;

Extensions::extend();
$records = $connection->table('test')
    ->select('field')
    ->orderBy('field')
    ->paginateLazily(100);

foreach ($records as $record) {
    echo $record->id;
}

echo $connection->table('test')->getCount();

```

### B. Calling the methods directly

```php
use JonasRaoni\QueryBuilder\Extensions;

$queryBuilder = $connection
    ->table('test')
    ->select('field')
    ->orderBy('field');
$records = Extensions::paginateLazily($queryBuilder, 100);

foreach ($records as $record) {
    echo $record->id;
}
```
