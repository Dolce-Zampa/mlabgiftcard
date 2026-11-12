<?php
define('_PS_VERSION_', '9.0.0');
if (getenv('MLABGC_TEST_PS_ROOT')) {
    define('_PS_ROOT_DIR_', (string) getenv('MLABGC_TEST_PS_ROOT'));
}
require_once dirname(__DIR__) . '/bootstrap.php';

final class GiftCardTestDb
{
    public ?string $failOn = null;
    public bool $abortOuterOnFailure = false;
    public function __construct(public readonly PDO $pdo) {}
    public function getLink(): PDO { return $this->pdo; }
    public function escape(string $value, bool $html = false): string { return substr($this->pdo->quote($value), 1, -1); }
    public function execute(string $sql): bool
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            $this->failOn = null;
            if ($this->abortOuterOnFailure && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Injected database failure');
        }
        return $this->pdo->exec($sql) !== false;
    }
    public function executeS(string $sql, bool $array = true, bool $cache = false): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

function giftCardTestConnection(): PDO
{
    $dsn = (string) getenv('MLABGC_TEST_DSN');
    if (!preg_match('/^mysql:.*dbname=mlabgiftcard_test(?:;|$)/D', $dsn)) {
        throw new RuntimeException('Use an isolated MySQL/MariaDB database named mlabgiftcard_test.');
    }
    return new PDO($dsn, getenv('MLABGC_TEST_USER') ?: 'root', getenv('MLABGC_TEST_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
