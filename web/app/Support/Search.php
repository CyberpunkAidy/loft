<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/** Case-insensitive "contains" search that behaves the same on Postgres and SQLite. */
class Search
{
    /** @param list<string> $columns */
    public static function apply(Builder $query, array $columns, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        // "!" as the LIKE escape: a backslash inside the SQL literal confuses PDO's placeholder parser on Postgres.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->where(function ($q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhereRaw("lower({$column}) like ? escape '!'", [$like]);
            }
        });
    }
}
