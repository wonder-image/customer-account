<?php
/**
 * Database finto in memoria per i test: sostituisce `Wonder\Sql\Connection` e `Wonder\Sql\Query`
 * prima che l'autoload carichi quelle vere. Va richiesto PRIMA di `bootstrap.php`.
 * Copre solo quello che usano i Model del modulo: condizioni come array di uguaglianze,
 * ordinamento su una colonna, limit e `MAX(col) AS alias`.
 */
declare(strict_types=1);

namespace Wonder\Sql {

    class Connection
    {
        public function __construct(...$args) {}

        public static function Connect(...$args): \mysqli
        {
            return \mysqli_init();
        }
    }

    class Query
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public static array $tables = [];
        /** @var array<int, array{0: string, 1: string}> operazioni di scrittura: [tipo, tabella] */
        public static array $writes = [];
        /** @var array<int, array{table: string, condition: mixed, limit: mixed, columns: string}> letture eseguite */
        public static array $selects = [];
        public static bool $failWrites = false;

        public function __construct(?\mysqli $connection = null, bool $informationSchema = false) {}

        public static function reset(array $tables = []): void
        {
            self::$tables = $tables;
            self::$writes = [];
            self::$selects = [];
            self::$failWrites = false;
        }

        public function Insert(string $table, string|array $values): object
        {
            self::$writes[] = ['insert', $table];
            if (self::$failWrites) {
                return (object) ['success' => false, 'table' => $table];
            }
            $rows = self::$tables[$table] ?? [];
            $id = $rows === [] ? 1 : max(array_keys($rows)) + 1;
            self::$tables[$table][$id] = ['id' => $id, 'deleted' => 'false'] + (array) $values;

            return (object) ['success' => true, 'table' => $table, 'insert_id' => $id];
        }

        public function Update(string $table, array $values, string $column, string|int $value): object
        {
            self::$writes[] = ['update', $table];
            if (self::$failWrites) {
                return (object) ['success' => false, 'table' => $table];
            }
            foreach (self::$tables[$table] ?? [] as $id => $row) {
                if ((string) ($row[$column] ?? '') === (string) $value) {
                    self::$tables[$table][$id] = array_merge($row, $values);
                }
            }

            return (object) ['success' => true, 'table' => $table];
        }

        public function Delete(string $table, string|array|null $condition = null): string
        {
            self::$writes[] = ['delete', $table];
            if (self::$failWrites) {
                return '';
            }
            foreach ($this->matching($table, $condition) as $id => $_row) {
                unset(self::$tables[$table][$id]);
            }

            return '1';
        }

        public function Select(string|array $table, string|array|null $condition = null, string|int|null $limit = null, ?string $order = null, ?string $orderDirection = null, string|array $attributes = '*'): object
        {
            $attributes = is_array($attributes) ? implode(',', $attributes) : $attributes;
            self::$selects[] = ['table' => (string) $table, 'condition' => $condition, 'limit' => $limit, 'columns' => $attributes];
            $rows = array_values($this->matching((string) $table, $condition));

            if (preg_match('/^\s*MAX\(`?(\w+)`?\)\s+AS\s+`?(\w+)`?\s*$/i', $attributes, $m) === 1) {
                $values = array_map(static fn (array $row) => $row[$m[1]] ?? null, $rows);
                $values = array_filter($values, static fn ($v) => $v !== null);
                $rows = [[$m[2] => $values === [] ? null : max($values)]];
            } elseif ($order !== null) {
                $column = trim($order, '` ');
                usort($rows, static fn (array $a, array $b) => ($a[$column] ?? 0) <=> ($b[$column] ?? 0));
                if (strtoupper((string) $orderDirection) === 'DESC') {
                    $rows = array_reverse($rows);
                }
            }

            if ($limit !== null && $limit !== '') {
                $rows = array_slice($rows, 0, (int) $limit);
            }

            $result = (object) ['success' => true, 'row' => [], 'exists' => $rows !== []];
            if (count($rows) === 1) {
                $result->row = (string) $limit === '1' ? $rows[0] : $rows;
            } elseif (count($rows) > 1) {
                $result->row = $rows;
            }

            return $result;
        }

        public function Count(string|array $table, string|array|null $query = null, string $column = '*', bool $distinct = false): int
        {
            return count($this->matching((string) $table, $query));
        }

        /** @return array<int, array<string, mixed>> */
        private function matching(string $table, string|array|null $condition): array
        {
            if (is_string($condition) && trim($condition) !== '') {
                throw new \LogicException("FakeDb: condizione SQL non supportata: {$condition}");
            }

            return array_filter(
                self::$tables[$table] ?? [],
                static function (array $row) use ($condition): bool {
                    foreach ((array) $condition as $column => $value) {
                        $column = trim((string) $column, '` ');
                        if ((string) ($row[$column] ?? '') !== (string) $value) {
                            return false;
                        }
                    }

                    return true;
                }
            );
        }
    }
}

namespace {

    /** Utenti finti letti da `infoUser()` del core. */
    $GLOBALS['__fake_users'] = [];

    if (!function_exists('infoUser')) {
        function infoUser($value, string $column = 'id'): object
        {
            foreach ($GLOBALS['__fake_users'] as $user) {
                if ((string) ($user[$column] ?? '') === (string) $value) {
                    return (object) (['exists' => true] + $user);
                }
            }

            return (object) ['exists' => false];
        }
    }
}
