<?php

declare(strict_types=1);

/**
 * MySQL JSON Schema Synchronizer
 *
 * Safe mode (used automatically during container startup):
 *   php database.php
 *
 * Destructive mode (modifies changed columns and removes extra columns):
 *   php database.php --destructive
 *
 * Drop tables that are absent from schema.json as well:
 *   php database.php --destructive --drop-missing-tables
 *
 * Destructive mode creates data snapshot tables before making changes. Their
 * names begin with "__schema_backup_" and they are ignored by synchronization.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/database_connection.php';

final class SchemaException extends RuntimeException {}

final class MySQLSchemaSynchronizer
{
    private const BACKUP_PREFIX = '__schema_backup_';

    private const ALLOWED_ENGINES = [
        'InnoDB',
    ];

    private const ALLOWED_CHARSETS = [
        'ascii',
        'utf8',
        'utf8mb4',
    ];

    private PDO $pdo;
    private array $schema;
    private bool $destructive;
    private bool $dropMissingTables;

    /** @var string[] */
    private array $messages = [];

    public function __construct(
        PDO $pdo,
        string $schemaFile,
        bool $destructive = false,
        bool $dropMissingTables = false
    ) {
        $this->pdo = $pdo;
        $this->schema = $this->loadSchema($schemaFile);
        $this->destructive = $destructive;
        $this->dropMissingTables = $dropMissingTables;
    }

    public function synchronize(): void
    {
        $this->validateSchema();

        if ($this->destructive) {
            $this->createDataSnapshots();
        }

        foreach ($this->schema['tables'] as $tableName => $definition) {
            $this->synchronizeTable($tableName, $definition);
        }

        $this->handleMissingTables();
        $this->log('Schema synchronization completed successfully.');
    }

    public function printReport(): void
    {
        foreach ($this->messages as $message) {
            echo $message . PHP_EOL;
        }
    }

    private function synchronizeTable(
        string $tableName,
        array $definition
    ): void {
        if (!$this->tableExists($tableName)) {
            $this->pdo->exec(
                $this->buildCreateTableSql($tableName, $definition)
            );
            $this->log("[CREATE TABLE] {$tableName}");
            return;
        }

        $actualColumns = $this->getColumns($tableName);
        $desiredColumns = $definition['columns'];

        foreach ($desiredColumns as $columnName => $columnDefinition) {
            if (!isset($actualColumns[$columnName])) {
                $sql = sprintf(
                    'ALTER TABLE %s ADD COLUMN %s',
                    $this->quoteIdentifier($tableName),
                    $this->buildColumnSql($columnName, $columnDefinition)
                );
                $this->pdo->exec($sql);
                $this->log("[ADD COLUMN] {$tableName}.{$columnName}");
                continue;
            }

            $changes = $this->compareColumn(
                $actualColumns[$columnName],
                $columnDefinition
            );

            if ($changes === []) {
                continue;
            }

            $this->log(
                "[CHANGED COLUMN] {$tableName}.{$columnName}: " .
                    implode(', ', $changes)
            );

            if ($this->destructive) {
                $sql = sprintf(
                    'ALTER TABLE %s MODIFY COLUMN %s',
                    $this->quoteIdentifier($tableName),
                    $this->buildColumnSql($columnName, $columnDefinition)
                );
                $this->pdo->exec($sql);
                $this->log("[MODIFY COLUMN] {$tableName}.{$columnName}");
            } else {
                $this->log(
                    "[SKIPPED] {$tableName}.{$columnName} requires " .
                        'destructive mode.'
                );
            }
        }

        $this->synchronizeIndexes($tableName, $definition);
        $this->synchronizePrimaryKey($tableName, $definition);

        foreach (array_keys($actualColumns) as $columnName) {
            if (isset($desiredColumns[$columnName])) {
                continue;
            }

            $this->log("[EXTRA COLUMN] {$tableName}.{$columnName}");

            if ($this->destructive) {
                $sql = sprintf(
                    'ALTER TABLE %s DROP COLUMN %s',
                    $this->quoteIdentifier($tableName),
                    $this->quoteIdentifier($columnName)
                );
                $this->pdo->exec($sql);
                $this->log("[DROP COLUMN] {$tableName}.{$columnName}");
            }
        }

        $this->synchronizeTableOptions($tableName, $definition);
    }

    private function synchronizeIndexes(
        string $tableName,
        array $definition
    ): void {
        $actualIndexes = $this->getIndexes($tableName);
        $desiredIndexes = [];

        foreach ($definition['indexes'] ?? [] as $index) {
            $desiredIndexes[$index['name']] = [
                'unique' => (bool) ($index['unique'] ?? false),
                'columns' => array_values($index['columns']),
            ];
        }

        foreach ($desiredIndexes as $indexName => $desiredIndex) {
            if (!isset($actualIndexes[$indexName])) {
                $this->createIndex($tableName, $indexName, $desiredIndex);
                continue;
            }

            if ($actualIndexes[$indexName] === $desiredIndex) {
                continue;
            }

            $this->log("[CHANGED INDEX] {$tableName}.{$indexName}");

            if ($this->destructive) {
                $this->dropIndex($tableName, $indexName);
                $this->createIndex($tableName, $indexName, $desiredIndex);
            } else {
                $this->log(
                    "[SKIPPED] {$tableName}.{$indexName} requires " .
                        'destructive mode.'
                );
            }
        }

        foreach (array_keys($actualIndexes) as $indexName) {
            if (isset($desiredIndexes[$indexName])) {
                continue;
            }

            $this->log("[EXTRA INDEX] {$tableName}.{$indexName}");

            if ($this->destructive) {
                $this->dropIndex($tableName, $indexName);
            }
        }
    }

    private function synchronizePrimaryKey(
        string $tableName,
        array $definition
    ): void {
        $actual = $this->getPrimaryKey($tableName);
        $desired = array_values($definition['primary_key'] ?? []);

        if ($actual === $desired) {
            return;
        }

        $this->log("[CHANGED PRIMARY KEY] {$tableName}");

        if (!$this->destructive) {
            $this->log(
                "[SKIPPED] {$tableName} primary key requires " .
                    'destructive mode.'
            );
            return;
        }

        if ($actual !== []) {
            $this->pdo->exec(
                'ALTER TABLE ' . $this->quoteIdentifier($tableName) .
                    ' DROP PRIMARY KEY'
            );
        }

        if ($desired !== []) {
            $columns = implode(
                ', ',
                array_map([$this, 'quoteIdentifier'], $desired)
            );
            $this->pdo->exec(
                'ALTER TABLE ' . $this->quoteIdentifier($tableName) .
                    " ADD PRIMARY KEY ({$columns})"
            );
        }
    }

    private function synchronizeTableOptions(
        string $tableName,
        array $definition
    ): void {
        $actual = $this->getTableOptions($tableName);
        $desiredEngine = $definition['engine'] ?? 'InnoDB';
        $desiredCollation = $definition['collation'] ?? 'utf8mb4_unicode_ci';

        if (
            strcasecmp($actual['engine'], $desiredEngine) === 0 &&
            strcasecmp($actual['collation'], $desiredCollation) === 0
        ) {
            return;
        }

        $this->log("[CHANGED TABLE OPTIONS] {$tableName}");

        if (!$this->destructive) {
            $this->log(
                "[SKIPPED] {$tableName} table options require " .
                    'destructive mode.'
            );
            return;
        }

        $charset = $definition['charset'] ?? 'utf8mb4';
        $sql = sprintf(
            'ALTER TABLE %s ENGINE=%s, CONVERT TO CHARACTER SET %s COLLATE %s',
            $this->quoteIdentifier($tableName),
            $desiredEngine,
            $charset,
            $desiredCollation
        );
        $this->pdo->exec($sql);
    }

    private function createIndex(
        string $tableName,
        string $indexName,
        array $index
    ): void {
        $columns = implode(
            ', ',
            array_map([$this, 'quoteIdentifier'], $index['columns'])
        );
        $unique = $index['unique'] ? 'UNIQUE ' : '';

        $sql = sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique,
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($tableName),
            $columns
        );
        $this->pdo->exec($sql);
        $this->log("[CREATE INDEX] {$tableName}.{$indexName}");
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        $sql = sprintf(
            'DROP INDEX %s ON %s',
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($tableName)
        );
        $this->pdo->exec($sql);
        $this->log("[DROP INDEX] {$tableName}.{$indexName}");
    }

    private function buildCreateTableSql(
        string $tableName,
        array $definition
    ): string {
        $parts = [];

        foreach ($definition['columns'] as $columnName => $column) {
            $parts[] = $this->buildColumnSql($columnName, $column);
        }

        $primaryKey = $definition['primary_key'] ?? [];
        if ($primaryKey !== []) {
            $parts[] = 'PRIMARY KEY (' . implode(
                ', ',
                array_map([$this, 'quoteIdentifier'], $primaryKey)
            ) . ')';
        }

        foreach ($definition['indexes'] ?? [] as $index) {
            $columns = implode(
                ', ',
                array_map([$this, 'quoteIdentifier'], $index['columns'])
            );
            $keyword = ($index['unique'] ?? false)
                ? 'UNIQUE KEY'
                : 'KEY';
            $parts[] = sprintf(
                '%s %s (%s)',
                $keyword,
                $this->quoteIdentifier($index['name']),
                $columns
            );
        }

        $engine = $definition['engine'] ?? 'InnoDB';
        $charset = $definition['charset'] ?? 'utf8mb4';
        $collation = $definition['collation'] ?? 'utf8mb4_unicode_ci';

        return sprintf(
            "CREATE TABLE %s (\n  %s\n) ENGINE=%s " .
                'DEFAULT CHARACTER SET %s COLLATE %s',
            $this->quoteIdentifier($tableName),
            implode(",\n  ", $parts),
            $engine,
            $charset,
            $collation
        );
    }

    private function buildColumnSql(
        string $columnName,
        array $definition
    ): string {
        $parts = [
            $this->quoteIdentifier($columnName),
            strtoupper($definition['type']),
            ($definition['nullable'] ?? true) ? 'NULL' : 'NOT NULL',
        ];

        if (array_key_exists('default', $definition)) {
            if ($definition['default'] === null) {
                $parts[] = 'DEFAULT NULL';
            } elseif (($definition['default_raw'] ?? false) === true) {
                $parts[] = 'DEFAULT ' . strtoupper($definition['default']);
            } else {
                $parts[] = 'DEFAULT ' . $this->pdo->quote(
                    (string) $definition['default']
                );
            }
        }

        if (($definition['auto_increment'] ?? false) === true) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if (isset($definition['on_update'])) {
            $parts[] = 'ON UPDATE ' . strtoupper($definition['on_update']);
        }

        return implode(' ', $parts);
    }

    /** @return string[] */
    private function compareColumn(
        array $actual,
        array $desired
    ): array {
        $changes = [];

        if (
            $this->normalizeType($actual['COLUMN_TYPE']) !==
            $this->normalizeType($desired['type'])
        ) {
            $changes[] = 'type';
        }

        $actualNullable = $actual['IS_NULLABLE'] === 'YES';
        $desiredNullable = (bool) ($desired['nullable'] ?? true);
        if ($actualNullable !== $desiredNullable) {
            $changes[] = 'nullability';
        }

        $actualDefault = $this->normalizeDefault($actual['COLUMN_DEFAULT']);
        $desiredDefault = array_key_exists('default', $desired)
            ? $this->normalizeDefault($desired['default'])
            : null;
        if ($actualDefault !== $desiredDefault) {
            $changes[] = 'default';
        }

        $actualAutoIncrement = str_contains(
            strtolower((string) $actual['EXTRA']),
            'auto_increment'
        );
        $desiredAutoIncrement = (bool) ($desired['auto_increment'] ?? false);
        if ($actualAutoIncrement !== $desiredAutoIncrement) {
            $changes[] = 'auto_increment';
        }

        $actualOnUpdate = str_contains(
            strtolower((string) $actual['EXTRA']),
            'on update current_timestamp'
        );
        $desiredOnUpdate = isset($desired['on_update']);
        if ($actualOnUpdate !== $desiredOnUpdate) {
            $changes[] = 'on_update';
        }

        return $changes;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\s+/', ' ', $type) ?? $type;

        return str_replace('integer', 'int', $type);
    }

    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $default));

        return preg_replace(
            '/^current_timestamp\(\)$/',
            'current_timestamp',
            $normalized
        ) ?? $normalized;
    }

    private function tableExists(string $tableName): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute([':table' => $tableName]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array<string, array<string, mixed>> */
    private function getColumns(string $tableName): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, ' .
                'COLUMN_DEFAULT, EXTRA ' .
                'FROM information_schema.COLUMNS ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ' .
                'ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([':table' => $tableName]);

        $columns = [];
        foreach ($statement->fetchAll() as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        return $columns;
    }

    /** @return array<string, array{unique: bool, columns: string[]}> */
    private function getIndexes(string $tableName): array
    {
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME ' .
                'FROM information_schema.STATISTICS ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ' .
                "AND INDEX_NAME <> 'PRIMARY' " .
                'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([':table' => $tableName]);

        $indexes = [];
        foreach ($statement->fetchAll() as $row) {
            $name = $row['INDEX_NAME'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'unique' => (int) $row['NON_UNIQUE'] === 0,
                    'columns' => [],
                ];
            }
            $indexes[$name]['columns'][] = $row['COLUMN_NAME'];
        }

        return $indexes;
    }

    /** @return string[] */
    private function getPrimaryKey(string $tableName): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ' .
                "AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION"
        );
        $statement->execute([':table' => $tableName]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{engine: string, collation: string} */
    private function getTableOptions(string $tableName): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute([':table' => $tableName]);
        $row = $statement->fetch();

        if ($row === false) {
            throw new SchemaException("Table {$tableName} does not exist.");
        }

        return [
            'engine' => (string) $row['ENGINE'],
            'collation' => (string) $row['TABLE_COLLATION'],
        ];
    }

    /** @return string[] */
    private function listTables(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = :type ' .
                'AND LEFT(TABLE_NAME, :prefix_length) <> :backup_prefix ' .
                'ORDER BY TABLE_NAME'
        );
        $statement->execute([
            ':type' => 'BASE TABLE',
            ':prefix_length' => strlen(self::BACKUP_PREFIX),
            ':backup_prefix' => self::BACKUP_PREFIX,
        ]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function handleMissingTables(): void
    {
        $desiredTables = array_keys($this->schema['tables']);

        foreach ($this->listTables() as $tableName) {
            if (in_array($tableName, $desiredTables, true)) {
                continue;
            }

            $this->log("[EXTRA TABLE] {$tableName}");

            if ($this->destructive && $this->dropMissingTables) {
                $this->pdo->exec(
                    'DROP TABLE ' . $this->quoteIdentifier($tableName)
                );
                $this->log("[DROP TABLE] {$tableName}");
            }
        }
    }

    private function createDataSnapshots(): void
    {
        $timestamp = gmdate('Ymd_His');

        foreach ($this->listTables() as $tableName) {
            $maximumOriginalLength = 64 - strlen(self::BACKUP_PREFIX) - 16;
            $shortTableName = substr($tableName, 0, $maximumOriginalLength);
            $backupName = self::BACKUP_PREFIX . $timestamp . '_' . $shortTableName;
            $suffix = 1;

            while ($this->tableExists($backupName)) {
                $suffixText = '_' . $suffix;
                $backupName = substr(
                    self::BACKUP_PREFIX . $timestamp . '_' . $shortTableName,
                    0,
                    64 - strlen($suffixText)
                ) . $suffixText;
                $suffix++;
            }

            $this->pdo->exec(
                'CREATE TABLE ' . $this->quoteIdentifier($backupName) .
                    ' LIKE ' . $this->quoteIdentifier($tableName)
            );
            $this->pdo->exec(
                'INSERT INTO ' . $this->quoteIdentifier($backupName) .
                    ' SELECT * FROM ' . $this->quoteIdentifier($tableName)
            );
            $this->log("[BACKUP TABLE] {$tableName} -> {$backupName}");
        }
    }

    private function validateSchema(): void
    {
        if (!isset($this->schema['tables']) || !is_array($this->schema['tables'])) {
            throw new SchemaException('schema.json must contain a tables object.');
        }

        foreach ($this->schema['tables'] as $tableName => $definition) {
            $this->validateIdentifier($tableName, 'table');

            if (
                !isset($definition['columns']) ||
                !is_array($definition['columns']) ||
                $definition['columns'] === []
            ) {
                throw new SchemaException(
                    "Table {$tableName} must define at least one column."
                );
            }

            $engine = $definition['engine'] ?? 'InnoDB';
            if (!in_array($engine, self::ALLOWED_ENGINES, true)) {
                throw new SchemaException("Unsupported engine: {$engine}");
            }

            $charset = $definition['charset'] ?? 'utf8mb4';
            if (!in_array($charset, self::ALLOWED_CHARSETS, true)) {
                throw new SchemaException("Unsupported charset: {$charset}");
            }

            $collation = $definition['collation'] ?? 'utf8mb4_unicode_ci';
            if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
                throw new SchemaException("Invalid collation: {$collation}");
            }

            $autoIncrementColumns = [];
            foreach ($definition['columns'] as $columnName => $column) {
                $this->validateIdentifier($columnName, 'column');
                $this->validateColumn($tableName, $columnName, $column);

                if (($column['auto_increment'] ?? false) === true) {
                    $autoIncrementColumns[] = $columnName;
                }
            }

            if (count($autoIncrementColumns) > 1) {
                throw new SchemaException(
                    "Table {$tableName} has more than one AUTO_INCREMENT column."
                );
            }

            $primaryKey = $definition['primary_key'] ?? [];
            if (!is_array($primaryKey)) {
                throw new SchemaException(
                    "Table {$tableName} primary_key must be an array."
                );
            }
            $this->validateColumnList(
                $tableName,
                $primaryKey,
                $definition['columns'],
                'primary key'
            );

            if (
                $autoIncrementColumns !== [] &&
                !in_array($autoIncrementColumns[0], $primaryKey, true)
            ) {
                throw new SchemaException(
                    "AUTO_INCREMENT column {$tableName}." .
                        $autoIncrementColumns[0] . ' must be in the primary key.'
                );
            }

            $indexNames = [];
            foreach ($definition['indexes'] ?? [] as $index) {
                if (!is_array($index) || !isset($index['name'], $index['columns'])) {
                    throw new SchemaException(
                        "Every index on {$tableName} needs name and columns."
                    );
                }
                $this->validateIdentifier($index['name'], 'index');

                if (isset($indexNames[$index['name']])) {
                    throw new SchemaException(
                        "Duplicate index {$tableName}.{$index['name']}."
                    );
                }
                $indexNames[$index['name']] = true;

                $this->validateColumnList(
                    $tableName,
                    $index['columns'],
                    $definition['columns'],
                    "index {$index['name']}"
                );
            }
        }
    }

    private function validateColumn(
        string $tableName,
        string $columnName,
        array $definition
    ): void {
        if (!isset($definition['type']) || !is_string($definition['type'])) {
            throw new SchemaException(
                "Column {$tableName}.{$columnName} needs a type."
            );
        }

        $type = strtoupper(trim($definition['type']));
        $allowedPatterns = [
            '/^(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)( UNSIGNED)?$/',
            '/^(DECIMAL|NUMERIC)\([0-9]{1,2},[0-9]{1,2}\)( UNSIGNED)?$/',
            '/^(FLOAT|DOUBLE)( UNSIGNED)?$/',
            '/^(CHAR|VARCHAR)\([0-9]{1,5}\)$/',
            '/^(TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT)$/',
            '/^(TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB)$/',
            '/^(DATE|YEAR|JSON|BOOLEAN|BOOL)$/',
            '/^(DATETIME|TIMESTAMP|TIME)(\([0-6]\))?$/',
        ];

        $supported = false;
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $type) === 1) {
                $supported = true;
                break;
            }
        }

        if (!$supported) {
            throw new SchemaException(
                "Unsupported MySQL type for {$tableName}.{$columnName}: {$type}"
            );
        }

        foreach (['default_raw', 'nullable', 'auto_increment'] as $flag) {
            if (isset($definition[$flag]) && !is_bool($definition[$flag])) {
                throw new SchemaException(
                    "{$tableName}.{$columnName}.{$flag} must be boolean."
                );
            }
        }

        foreach (['default', 'on_update'] as $property) {
            if (
                isset($definition[$property]) &&
                ($definition[$property] === 'CURRENT_TIMESTAMP')
            ) {
                continue;
            }

            if (
                $property === 'on_update' &&
                isset($definition[$property])
            ) {
                throw new SchemaException(
                    "Only CURRENT_TIMESTAMP is allowed for on_update."
                );
            }
        }

        if (
            ($definition['default_raw'] ?? false) === true &&
            ($definition['default'] ?? null) !== 'CURRENT_TIMESTAMP'
        ) {
            throw new SchemaException(
                "Only CURRENT_TIMESTAMP is allowed as a raw default."
            );
        }
    }

    private function validateColumnList(
        string $tableName,
        mixed $columns,
        array $definedColumns,
        string $kind
    ): void {
        if (!is_array($columns) || $columns === []) {
            if ($kind === 'primary key' && $columns === []) {
                return;
            }
            throw new SchemaException(
                ucfirst($kind) . " on {$tableName} must contain columns."
            );
        }

        foreach ($columns as $columnName) {
            if (!is_string($columnName) || !isset($definedColumns[$columnName])) {
                throw new SchemaException(
                    ucfirst($kind) . " on {$tableName} references " .
                        'an undefined column.'
                );
            }
        }
    }

    private function validateIdentifier(string $identifier, string $kind): void
    {
        if (
            strlen($identifier) > 64 ||
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1
        ) {
            throw new SchemaException(
                "Invalid MySQL {$kind} identifier: {$identifier}"
            );
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        $this->validateIdentifier($identifier, 'SQL');

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function loadSchema(string $schemaFile): array
    {
        if (!is_file($schemaFile) || !is_readable($schemaFile)) {
            throw new SchemaException(
                "Schema file is not readable: {$schemaFile}"
            );
        }

        try {
            $schema = json_decode(
                file_get_contents($schemaFile),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new SchemaException(
                'Invalid schema JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($schema)) {
            throw new SchemaException('Schema root must be a JSON object.');
        }

        return $schema;
    }

    private function log(string $message): void
    {
        $this->messages[] = $message;
    }
}

$arguments = array_slice($argv, 1);
$destructive = in_array('--destructive', $arguments, true);
$dropMissingTables = in_array('--drop-missing-tables', $arguments, true);

if ($dropMissingTables && !$destructive) {
    fwrite(
        STDERR,
        "--drop-missing-tables requires --destructive.\n"
    );
    exit(1);
}

try {
    $pdo = createDatabaseConnection(30, 2);
    $synchronizer = new MySQLSchemaSynchronizer(
        pdo: $pdo,
        schemaFile: __DIR__ . '/schema.json',
        destructive: $destructive,
        dropMissingTables: $dropMissingTables
    );
    $synchronizer->synchronize();
    $synchronizer->printReport();
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Synchronization failed: ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}
