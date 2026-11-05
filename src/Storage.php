<?php
namespace Mlab\GiftCard;

/** All reads use the primary connection and bypass PrestaShop's SQL cache. */
final class Storage
{
    public function __construct(private readonly object $db, private readonly string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix.');
        }
    }

    public function table(string $name): string
    {
        if (!preg_match('/^[a-z_]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }
        return '`' . $this->prefix . 'mlabgc_' . $name . '`';
    }

    public function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return "'" . $this->db->escape((string) $value, true) . "'";
    }

    public function exec(string $sql): void
    {
        if (!$this->db->execute($sql)) {
            throw new \RuntimeException('Gift card database write failed.');
        }
    }

    public function rows(string $sql): array
    {
        $rows = $this->db->executeS($sql, true, false);
        if ($rows === false) {
            throw new \RuntimeException('Gift card database read failed.');
        }
        return $rows;
    }

    public function row(string $sql): ?array
    {
        // Db::getRow appends LIMIT, which would break SELECT ... FOR UPDATE.
        return $this->rows($sql)[0] ?? null;
    }

    public function insert(string $table, array $values): void
    {
        $columns = implode(',', array_map(static fn($column) => '`' . $column . '`', array_keys($values)));
        $this->exec('INSERT INTO ' . $this->table($table) . ' (' . $columns . ') VALUES ('
            . implode(',', array_map([$this, 'quote'], $values)) . ')');
    }

    public function update(string $table, array $values, string $where): void
    {
        $assignments = [];
        foreach ($values as $column => $value) {
            $assignments[] = '`' . $column . '`=' . $this->quote($value);
        }
        $this->exec('UPDATE ' . $this->table($table) . ' SET ' . implode(',', $assignments) . ' WHERE ' . $where);
    }

    public function transaction(callable $callback): mixed
    {
        $nested = $this->inTransaction();
        $savepoint = 'mlabgc_' . bin2hex(random_bytes(8));
        $this->exec($nested ? 'SAVEPOINT ' . $savepoint : 'START TRANSACTION');
        try {
            $result = $callback();
            $this->exec($nested ? 'RELEASE SAVEPOINT ' . $savepoint : 'COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try {
                $this->db->execute($nested ? 'ROLLBACK TO SAVEPOINT ' . $savepoint : 'ROLLBACK');
                if ($nested) {
                    $this->db->execute('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable $rollbackError) {
                // A deadlock may already have rolled back the transaction. Keep the original failure.
            }
            throw $error;
        }
    }

    public function inTransaction(): bool
    {
        $link = $this->db->getLink();
        return $link instanceof \PDO ? $link->inTransaction()
            : ($link instanceof \mysqli && ($link->server_status & 1) !== 0);
    }
}
