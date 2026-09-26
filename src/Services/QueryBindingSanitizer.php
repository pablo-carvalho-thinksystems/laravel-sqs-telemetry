<?php

namespace Pablocarvalho\SqsTelemetry\Services;

/**
 * Masks credential-bearing query bindings and keeps everything else.
 *
 * The consumer needs the real values to re-run a query (EXPLAIN, rewrites),
 * so dropping all bindings of a "sensitive" table throws away too much. What
 * must never leave the host is narrower: passwords, tokens, secrets and the
 * session row itself — its id IS the credential that authenticates a browser,
 * and its payload carries the CSRF token and the login hash.
 *
 * Each `?` is paired with the column it is compared to, read from the SQL
 * text: INSERT column lists (multi-row too), `col = ?` and the other
 * comparison operators, `col in (?, ?)` and `col between ? and ?`. A
 * placeholder that cannot be paired keeps its value — unknown is not the same
 * as sensitive, and a guess that shifts by one would mask the wrong column.
 */
class QueryBindingSanitizer
{
    const REDACTED = '[REDACTED]';

    /** Identifier: bare word or quoted with "", `` or [], optionally table-qualified. */
    const IDENT = '(?:(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)\.)?(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)';

    /** @var string */
    protected $columnPattern;

    /** @var array<string, array<int, string>> table => columns, lowercase */
    protected $tableColumns;

    /**
     * @param array<int, string> $columnFragments Column names containing any of these are masked.
     * @param array<string, array<int, string>> $tableColumns Columns masked only on that table.
     */
    public function __construct(array $columnFragments, array $tableColumns)
    {
        $quoted = array_map(function ($fragment) {
            return preg_quote(strtolower((string) $fragment), '/');
        }, array_filter($columnFragments, 'strlen'));

        // Matches nothing when the list is empty.
        $this->columnPattern = $quoted === [] ? '/(?!)/' : '/'.implode('|', $quoted).'/';

        $this->tableColumns = [];
        foreach ($tableColumns as $table => $columns) {
            $this->tableColumns[strtolower((string) $table)] = array_map('strtolower', (array) $columns);
        }
    }

    /**
     * @param string $sql
     * @param array $bindings
     * @return array
     */
    public function sanitize(string $sql, array $bindings): array
    {
        if ($bindings === []) {
            return $bindings;
        }

        $placeholders = $this->placeholders($sql);
        $mainTable = $this->mainTable($sql);
        $insertColumns = $this->insertColumns($sql);
        $keys = array_keys($bindings);

        foreach ($placeholders as $index => $offset) {
            if (! isset($keys[$index])) {
                break;
            }

            if ($insertColumns !== null && $index < $insertColumns['placeholders']) {
                $column = [null, $insertColumns['columns'][$index % count($insertColumns['columns'])]];
            } else {
                $column = $this->columnBefore(substr($sql, 0, $offset));
            }

            if ($column !== null && $this->isSensitive($column[0] ?: $mainTable, $column[1])) {
                $bindings[$keys[$index]] = self::REDACTED;
            }
        }

        return $bindings;
    }

    /**
     * @param string|null $table
     * @param string $column
     * @return bool
     */
    protected function isSensitive($table, string $column): bool
    {
        $column = strtolower($column);

        if (preg_match($this->columnPattern, $column)) {
            return true;
        }

        return $table !== null
            && isset($this->tableColumns[strtolower($table)])
            && in_array($column, $this->tableColumns[strtolower($table)], true);
    }

    /**
     * Byte offsets of every binding placeholder, skipping string literals and
     * the `??` that grammars emit for PostgreSQL's jsonb `?` operator.
     *
     * @param string $sql
     * @return array<int, int>
     */
    protected function placeholders(string $sql): array
    {
        $offsets = [];
        $length = strlen($sql);
        $inString = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'") {
                // '' inside a literal is an escaped quote, not the end of it.
                if ($inString && $i + 1 < $length && $sql[$i + 1] === "'") {
                    $i++;
                    continue;
                }
                $inString = ! $inString;
                continue;
            }

            if ($inString || $char !== '?') {
                continue;
            }

            if ($i + 1 < $length && $sql[$i + 1] === '?') {
                $i++;
                continue;
            }

            $offsets[] = $i;
        }

        return $offsets;
    }

    /**
     * Column (and table, when qualified) compared to the placeholder that
     * follows `$prefix`, or null when the SQL does not say.
     *
     * @param string $prefix SQL text up to (excluding) the placeholder.
     * @return array{0: string|null, 1: string}|null
     */
    protected function columnBefore(string $prefix)
    {
        $ident = '('.self::IDENT.')';
        $patterns = [
            // col = ?, col >= ?, col like ?, col not ilike ?
            '/'.$ident.'\s*(?:=|!=|<>|<=|>=|<|>|\bnot\s+i?like\b|\bi?like\b)\s*$/i',
            // col in (?, ?, ?  /  col not in (?
            '/'.$ident.'\s+(?:not\s+)?in\s*\((?:\s*\?\s*,)*\s*$/i',
            // col between ?  /  col between ? and ?
            '/'.$ident.'\s+(?:not\s+)?between\s+(?:\?\s+and\s+)?$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prefix, $match)) {
                return $this->splitIdentifier($match[1]);
            }
        }

        return null;
    }

    /**
     * Column list and placeholder count of an INSERT's VALUES tuples.
     *
     * @param string $sql
     * @return array{columns: array<int, string>, placeholders: int}|null
     */
    protected function insertColumns(string $sql)
    {
        if (! preg_match('/^\s*insert\s+(?:ignore\s+)?into\s+'.self::IDENT.'\s*\(([^)]*)\)\s*values\s*/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $columns = [];
        foreach (explode(',', $match[1][0]) as $column) {
            $parts = $this->splitIdentifier(trim($column));
            $columns[] = $parts[1];
        }

        if ($columns === []) {
            return null;
        }

        // Only the placeholders inside the VALUES tuples map to the column
        // list; an `on conflict ... do update set x = ?` tail is paired by
        // the generic rules instead.
        $valuesStart = $match[0][1] + strlen($match[0][0]);
        $inValues = 0;
        foreach ($this->placeholders($sql) as $offset) {
            if ($offset < $valuesStart) {
                continue;
            }
            $between = substr($sql, $valuesStart, $offset - $valuesStart);
            if (preg_match('/\)\s*(?:on\s+conflict|on\s+duplicate|returning)\b/i', $between)) {
                break;
            }
            $inValues++;
        }

        // A count that is not a whole number of rows means the tuples are
        // not plain placeholders; pairing by position would drift.
        if ($inValues % count($columns) !== 0) {
            return null;
        }

        return ['columns' => $columns, 'placeholders' => $inValues];
    }

    /**
     * Table the statement is about: FROM / UPDATE / INSERT INTO / DELETE FROM.
     *
     * @param string $sql
     * @return string|null
     */
    protected function mainTable(string $sql)
    {
        if (preg_match('/\b(?:from|update|into)\s+('.self::IDENT.')/i', $sql, $match)) {
            return $this->splitIdentifier($match[1])[1];
        }

        return null;
    }

    /**
     * "users"."email" → ['users', 'email'];  email → [null, 'email'].
     *
     * @param string $identifier
     * @return array{0: string|null, 1: string}
     */
    protected function splitIdentifier(string $identifier): array
    {
        $unquote = function ($part) {
            return trim((string) $part, " \t\n\r\0\x0B\"`[]");
        };
        $single = '("[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)';

        if (preg_match('/^\s*(?:'.$single.'\.)?'.$single.'\s*$/', $identifier, $match)) {
            return [$match[1] !== '' ? $unquote($match[1]) : null, $unquote($match[2])];
        }

        return [null, $unquote($identifier)];
    }
}
