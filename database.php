<?php

declare(strict_types=1);

/**
 * SQLite JSON Schema Synchronizer
 *
 * Safe mode:
 *   php sync_schema.php
 *
 * Destructive mode:
 *   php sync_schema.php --destructive
 *
 * Destructive mode can:
 *   - rebuild tables
 *   - remove columns absent from JSON
 *   - change column types and constraints
 *   - remove tables absent from JSON
 *
 * A database backup is created before destructive changes.
 */

final class SchemaException extends RuntimeException
{
}

final class SQLiteSchemaSynchronizer
{
    private const ALLOWED_TYPES = [
        'INTEGER',
        'REAL',
        'TEXT',
        'BLOB',
        'NUMERIC',
        'ANY',
    ];

    private const ALLOWED_FOREIGN_KEY_ACTIONS = [
        'CASCADE',
        'RESTRICT',
        'SET NULL',
        'SET DEFAULT',
        'NO ACTION',
    ];

    private PDO $pdo;
    private array $schema;
    private bool $destructive;
    private bool $dropMissingTables;
    private string $databaseFile;

    /** @var string[] */
    private array $messages = [];

    public function __construct(
        string $databaseFile,
        string $schemaFile,
        bool $destructive = false,
        bool $dropMissingTables = false
    ) {
        $this->databaseFile = $databaseFile;
        $this->schema = $this->loadSchema($schemaFile);
        $this->destructive = $destructive;
        $this->dropMissingTables = $dropMissingTables;

        $this->pdo = new PDO(
            'sqlite:' . $databaseFile,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public function synchronize(): void
    {
        $this->validateSchema();

        if ($this->destructive) {
            $this->createBackup();
        }

        $this->pdo->beginTransaction();

        try {
            /*
             * Foreign-key checking must be disabled before rebuilding related
             * tables. Changing this PRAGMA while a transaction is active has
             * no effect, so destructive table rebuilding uses deferred checks.
             */
            $this->pdo->exec('PRAGMA defer_foreign_keys = ON');

            foreach ($this->schema['tables'] as $tableName => $definition) {
                $this->synchronizeTable($tableName, $definition);
            }

            $this->handleMissingTables();

            $this->validateForeignKeys();

            $this->pdo->commit();

            $this->log('Schema synchronization completed successfully.');
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function printReport(): void
    {
        foreach ($this->messages as $message) {
            echo $message . PHP_EOL;
        }
    }

    private function synchronizeTable(
        string $tableName,
        array $desiredTable
    ): void {
        if (!$this->tableExists($tableName)) {
            $sql = $this->buildCreateTableSql($tableName, $desiredTable);

            $this->pdo->exec($sql);
            $this->log("[CREATE TABLE] {$tableName}");

            $this->synchronizeIndexes($tableName, $desiredTable);
            return;
        }

        $differences = $this->compareTable($tableName, $desiredTable);

        if ($differences['requires_rebuild']) {
            $this->reportDifferences($tableName, $differences);

            if ($this->destructive) {
                $this->rebuildTable($tableName, $desiredTable);
            } else {
                $this->log(
                    "[SKIPPED] {$tableName} requires rebuilding. " .
                    'Run with --destructive to apply these changes.'
                );
            }
        } else {
            foreach ($differences['missing_columns'] as $columnName) {
                $columnDefinition = $desiredTable['columns'][$columnName];

                $this->addColumn(
                    $tableName,
                    $columnName,
                    $columnDefinition
                );
            }
        }

        /*
         * Synchronize indexes even when destructive changes are skipped.
         * Existing table indexes can still be created safely.
         */
        $this->synchronizeIndexes($tableName, $desiredTable);
    }

    private function compareTable(
        string $tableName,
        array $desiredTable
    ): array {
        $actualColumns = $this->getTableColumns($tableName);
        $desiredColumns = $desiredTable['columns'];

        $missingColumns = [];
        $extraColumns = [];
        $changedColumns = [];

        foreach ($desiredColumns as $columnName => $desiredColumn) {
            if (!isset($actualColumns[$columnName])) {
                $missingColumns[] = $columnName;
                continue;
            }

            $changes = $this->compareColumn(
                $actualColumns[$columnName],
                $desiredColumn
            );

            if ($changes !== []) {
                $changedColumns[$columnName] = $changes;
            }
        }

        foreach ($actualColumns as $columnName => $actualColumn) {
            if (!array_key_exists($columnName, $desiredColumns)) {
                $extraColumns[] = $columnName;
            }
        }

        $actualForeignKeys = $this->getForeignKeys($tableName);
        $desiredForeignKeys = $this->normalizeDesiredForeignKeys(
            $desiredTable['foreign_keys'] ?? []
        );

        $foreignKeysChanged =
            $this->canonicalJson($actualForeignKeys) !==
            $this->canonicalJson($desiredForeignKeys);

        /*
         * A newly added column can use ALTER TABLE only when its definition
         * is valid for ADD COLUMN.
         */
        $unsafeMissingColumns = [];

        foreach ($missingColumns as $columnName) {
            $column = $desiredColumns[$columnName];

            if (!$this->canAddColumnDirectly($column)) {
                $unsafeMissingColumns[] = $columnName;
            }
        }

        return [
            'missing_columns' => $missingColumns,
            'unsafe_missing_columns' => $unsafeMissingColumns,
            'extra_columns' => $extraColumns,
            'changed_columns' => $changedColumns,
            'foreign_keys_changed' => $foreignKeysChanged,
            'requires_rebuild' =>
                $extraColumns !== [] ||
                $changedColumns !== [] ||
                $foreignKeysChanged ||
                $unsafeMissingColumns !== [],
        ];
    }

    private function compareColumn(
        array $actual,
        array $desired
    ): array {
        $changes = [];

        $actualType = $this->normalizeType($actual['type']);
        $desiredType = $this->normalizeType($desired['type']);

        if ($actualType !== $desiredType) {
            $changes[] = "type {$actualType} -> {$desiredType}";
        }

        $actualNotNull = (bool) $actual['not_null'];
        $desiredNotNull = (bool) ($desired['not_null'] ?? false);

        /*
         * SQLite reports INTEGER PRIMARY KEY as nullable in table_info even
         * though it acts as the row identifier. Do not report that as a
         * NOT NULL mismatch.
         */
        $desiredPrimaryKey = (bool) ($desired['primary_key'] ?? false);

        if (!$desiredPrimaryKey && $actualNotNull !== $desiredNotNull) {
            $changes[] =
                'NOT NULL ' .
                ($actualNotNull ? 'enabled' : 'disabled') .
                ' -> ' .
                ($desiredNotNull ? 'enabled' : 'disabled');
        }

        $actualPrimaryKey = (bool) $actual['primary_key'];

        if ($actualPrimaryKey !== $desiredPrimaryKey) {
            $changes[] =
                'PRIMARY KEY ' .
                ($actualPrimaryKey ? 'enabled' : 'disabled') .
                ' -> ' .
                ($desiredPrimaryKey ? 'enabled' : 'disabled');
        }

        $actualDefault = $this->normalizeDefault($actual['default']);
        $desiredDefault = $this->desiredDefaultSql($desired);

        if ($actualDefault !== $this->normalizeDefault($desiredDefault)) {
            $changes[] =
                'default ' .
                var_export($actualDefault, true) .
                ' -> ' .
                var_export(
                    $this->normalizeDefault($desiredDefault),
                    true
                );
        }

        return $changes;
    }

    private function addColumn(
        string $tableName,
        string $columnName,
        array $definition
    ): void {
        if (!$this->canAddColumnDirectly($definition)) {
            throw new SchemaException(
                "Column {$tableName}.{$columnName} cannot be added directly."
            );
        }

        $columnSql = $this->buildColumnDefinition(
            $columnName,
            $definition,
            false
        );

        $sql = sprintf(
            'ALTER TABLE %s ADD COLUMN %s',
            $this->quoteIdentifier($tableName),
            $columnSql
        );

        $this->pdo->exec($sql);
        $this->log("[ADD COLUMN] {$tableName}.{$columnName}");
    }

    private function canAddColumnDirectly(array $column): bool
    {
        if (($column['primary_key'] ?? false) === true) {
            return false;
        }

        if (($column['unique'] ?? false) === true) {
            return false;
        }

        if (($column['autoincrement'] ?? false) === true) {
            return false;
        }

        /*
         * A new NOT NULL column needs a non-NULL default because existing
         * rows need a value.
         */
        if (
            ($column['not_null'] ?? false) === true &&
            !array_key_exists('default', $column)
        ) {
            return false;
        }

        if (
            ($column['not_null'] ?? false) === true &&
            ($column['default'] ?? null) === null
        ) {
            return false;
        }

        return true;
    }

    private function rebuildTable(
        string $tableName,
        array $desiredTable
    ): void {
        $temporaryName =
            '__sync_new_' .
            preg_replace('/[^A-Za-z0-9_]/', '_', $tableName) .
            '_' .
            bin2hex(random_bytes(4));

        $createSql = $this->buildCreateTableSql(
            $temporaryName,
            $desiredTable
        );

        $this->pdo->exec($createSql);

        $actualColumns = $this->getTableColumns($tableName);
        $desiredColumns = $desiredTable['columns'];

        $insertColumns = [];
        $selectExpressions = [];

        foreach ($desiredColumns as $columnName => $definition) {
            $insertColumns[] = $this->quoteIdentifier($columnName);

            if (isset($actualColumns[$columnName])) {
                $selectExpressions[] = $this->buildCopyExpression(
                    $columnName,
                    $definition
                );
                continue;
            }

            if (array_key_exists('default', $definition)) {
                $selectExpressions[] = $this->desiredDefaultSql($definition);
                continue;
            }

            if (($definition['not_null'] ?? false) === true) {
                throw new SchemaException(
                    "Cannot rebuild {$tableName}: new NOT NULL column " .
                    "{$columnName} has no default value."
                );
            }

            $selectExpressions[] = 'NULL';
        }

        $copySql = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s',
            $this->quoteIdentifier($temporaryName),
            implode(', ', $insertColumns),
            implode(', ', $selectExpressions),
            $this->quoteIdentifier($tableName)
        );

        $this->pdo->exec($copySql);

        $this->pdo->exec(
            'DROP TABLE ' . $this->quoteIdentifier($tableName)
        );

        $this->pdo->exec(
            sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteIdentifier($temporaryName),
                $this->quoteIdentifier($tableName)
            )
        );

        $this->log("[REBUILD TABLE] {$tableName}");

        /*
         * Indexes disappeared when the original table was dropped.
         */
        $this->synchronizeIndexes($tableName, $desiredTable);
    }

    private function buildCopyExpression(
        string $columnName,
        array $definition
    ): string {
        $quotedColumn = $this->quoteIdentifier($columnName);
        $type = $this->normalizeType($definition['type']);

        $expression = match ($type) {
            'INTEGER' => "CAST({$quotedColumn} AS INTEGER)",
            'REAL' => "CAST({$quotedColumn} AS REAL)",
            'TEXT' => "CAST({$quotedColumn} AS TEXT)",
            'NUMERIC' => "CAST({$quotedColumn} AS NUMERIC)",
            'BLOB', 'ANY' => $quotedColumn,
            default => $quotedColumn,
        };

        if (
            ($definition['not_null'] ?? false) === true &&
            array_key_exists('default', $definition)
        ) {
            $expression = sprintf(
                'COALESCE(%s, %s)',
                $expression,
                $this->desiredDefaultSql($definition)
            );
        }

        return $expression;
    }

    private function synchronizeIndexes(
        string $tableName,
        array $desiredTable
    ): void {
        $desiredIndexes = $desiredTable['indexes'] ?? [];
        $actualIndexes = $this->getIndexes($tableName);

        $desiredNames = [];

        foreach ($desiredIndexes as $index) {
            $indexName = $index['name'];
            $desiredNames[$indexName] = true;

            $normalizedDesired = [
                'unique' => (bool) ($index['unique'] ?? false),
                'columns' => array_values($index['columns']),
            ];

            if (!isset($actualIndexes[$indexName])) {
                $this->createIndex($tableName, $index);
                continue;
            }

            $normalizedActual = [
                'unique' => $actualIndexes[$indexName]['unique'],
                'columns' => $actualIndexes[$indexName]['columns'],
            ];

            if (
                $this->canonicalJson($normalizedActual) !==
                $this->canonicalJson($normalizedDesired)
            ) {
                if ($this->destructive) {
                    $this->pdo->exec(
                        'DROP INDEX ' .
                        $this->quoteIdentifier($indexName)
                    );

                    $this->createIndex($tableName, $index);
                    $this->log("[RECREATE INDEX] {$indexName}");
                } else {
                    $this->log(
                        "[INDEX CHANGED] {$indexName}; skipped in safe mode."
                    );
                }
            }
        }

        foreach ($actualIndexes as $indexName => $actualIndex) {
            /*
             * SQLite-generated primary key and UNIQUE indexes begin with
             * sqlite_autoindex_ and cannot be dropped manually.
             */
            if (str_starts_with($indexName, 'sqlite_autoindex_')) {
                continue;
            }

            if (!isset($desiredNames[$indexName])) {
                if ($this->destructive) {
                    $this->pdo->exec(
                        'DROP INDEX ' .
                        $this->quoteIdentifier($indexName)
                    );

                    $this->log("[DROP INDEX] {$indexName}");
                } else {
                    $this->log(
                        "[EXTRA INDEX] {$indexName}; kept in safe mode."
                    );
                }
            }
        }
    }

    private function createIndex(
        string $tableName,
        array $index
    ): void {
        $columns = array_map(
            fn(string $column): string =>
                $this->quoteIdentifier($column),
            $index['columns']
        );

        $sql = sprintf(
            'CREATE %s INDEX %s ON %s (%s)',
            ($index['unique'] ?? false) ? 'UNIQUE' : '',
            $this->quoteIdentifier($index['name']),
            $this->quoteIdentifier($tableName),
            implode(', ', $columns)
        );

        $this->pdo->exec($sql);
        $this->log("[CREATE INDEX] {$index['name']}");
    }

    private function handleMissingTables(): void
    {
        $desiredTables = array_keys($this->schema['tables']);

        foreach ($this->getUserTableNames() as $actualTable) {
            if (in_array($actualTable, $desiredTables, true)) {
                continue;
            }

            if ($this->destructive && $this->dropMissingTables) {
                $this->pdo->exec(
                    'DROP TABLE ' .
                    $this->quoteIdentifier($actualTable)
                );

                $this->log("[DROP TABLE] {$actualTable}");
            } else {
                $this->log(
                    "[EXTRA TABLE] {$actualTable}; table was kept."
                );
            }
        }
    }

    private function buildCreateTableSql(
        string $tableName,
        array $table
    ): string {
        $definitions = [];

        foreach ($table['columns'] as $columnName => $column) {
            $definitions[] = $this->buildColumnDefinition(
                $columnName,
                $column,
                true
            );
        }

        foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
            $definitions[] =
                $this->buildForeignKeyDefinition($foreignKey);
        }

        $strict = ($table['strict'] ?? false) === true
            ? ' STRICT'
            : '';

        $withoutRowId = ($table['without_rowid'] ?? false) === true
            ? ' WITHOUT ROWID'
            : '';

        if ($strict !== '' && $withoutRowId !== '') {
            $tableOptions = ' STRICT, WITHOUT ROWID';
        } else {
            $tableOptions = $strict . $withoutRowId;
        }

        return sprintf(
            "CREATE TABLE %s (\n    %s\n)%s",
            $this->quoteIdentifier($tableName),
            implode(",\n    ", $definitions),
            $tableOptions
        );
    }

    private function buildColumnDefinition(
        string $columnName,
        array $column,
        bool $includeUnique
    ): string {
        $type = $this->normalizeType($column['type']);

        $parts = [
            $this->quoteIdentifier($columnName),
            $type,
        ];

        $primaryKey = (bool) ($column['primary_key'] ?? false);
        $autoincrement = (bool) ($column['autoincrement'] ?? false);

        if ($primaryKey) {
            $parts[] = 'PRIMARY KEY';

            if (
                isset($column['primary_key_order']) &&
                strtoupper($column['primary_key_order']) === 'DESC'
            ) {
                $parts[] = 'DESC';
            }
        }

        if ($autoincrement) {
            if (!$primaryKey || $type !== 'INTEGER') {
                throw new SchemaException(
                    "AUTOINCREMENT requires INTEGER PRIMARY KEY: " .
                    $columnName
                );
            }

            $parts[] = 'AUTOINCREMENT';
        }

        if (($column['not_null'] ?? false) === true) {
            $parts[] = 'NOT NULL';
        }

        if ($includeUnique && ($column['unique'] ?? false) === true) {
            $parts[] = 'UNIQUE';
        }

        if (array_key_exists('default', $column)) {
            $parts[] = 'DEFAULT ' . $this->desiredDefaultSql($column);
        }

        if (isset($column['check'])) {
            $parts[] = 'CHECK (' . $column['check'] . ')';
        }

        if (isset($column['collate'])) {
            $collation = strtoupper((string) $column['collate']);

            if (!preg_match('/^[A-Z0-9_]+$/', $collation)) {
                throw new SchemaException(
                    "Invalid collation for {$columnName}."
                );
            }

            $parts[] = 'COLLATE ' . $collation;
        }

        return implode(' ', $parts);
    }

    private function buildForeignKeyDefinition(array $foreignKey): string
    {
        $columns = array_map(
            fn(string $column): string =>
                $this->quoteIdentifier($column),
            $foreignKey['columns']
        );

        $referenceColumns = array_map(
            fn(string $column): string =>
                $this->quoteIdentifier($column),
            $foreignKey['references_columns']
        );

        $sql = sprintf(
            'FOREIGN KEY (%s) REFERENCES %s (%s)',
            implode(', ', $columns),
            $this->quoteIdentifier($foreignKey['references_table']),
            implode(', ', $referenceColumns)
        );

        if (isset($foreignKey['on_delete'])) {
            $sql .= ' ON DELETE ' .
                $this->validateForeignKeyAction(
                    $foreignKey['on_delete']
                );
        }

        if (isset($foreignKey['on_update'])) {
            $sql .= ' ON UPDATE ' .
                $this->validateForeignKeyAction(
                    $foreignKey['on_update']
                );
        }

        if (isset($foreignKey['match'])) {
            $match = strtoupper((string) $foreignKey['match']);

            if (!preg_match('/^[A-Z0-9_]+$/', $match)) {
                throw new SchemaException('Invalid MATCH clause.');
            }

            $sql .= ' MATCH ' . $match;
        }

        return $sql;
    }

    private function getTableColumns(string $tableName): array
    {
        $statement = $this->pdo->query(
            'PRAGMA table_info(' .
            $this->quoteSqlString($tableName) .
            ')'
        );

        $columns = [];

        foreach ($statement->fetchAll() as $column) {
            $columns[$column['name']] = [
                'type' => (string) $column['type'],
                'not_null' => (bool) $column['notnull'],
                'default' => $column['dflt_value'],
                'primary_key' => ((int) $column['pk']) > 0,
                'primary_key_position' => (int) $column['pk'],
            ];
        }

        return $columns;
    }

    private function getForeignKeys(string $tableName): array
    {
        $statement = $this->pdo->query(
            'PRAGMA foreign_key_list(' .
            $this->quoteSqlString($tableName) .
            ')'
        );

        $groups = [];

        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];

            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'columns' => [],
                    'references_table' => $row['table'],
                    'references_columns' => [],
                    'on_update' => strtoupper($row['on_update']),
                    'on_delete' => strtoupper($row['on_delete']),
                ];
            }

            $sequence = (int) $row['seq'];
            $groups[$id]['columns'][$sequence] = $row['from'];
            $groups[$id]['references_columns'][$sequence] = $row['to'];
        }

        $foreignKeys = array_values($groups);

        foreach ($foreignKeys as &$foreignKey) {
            ksort($foreignKey['columns']);
            ksort($foreignKey['references_columns']);

            $foreignKey['columns'] =
                array_values($foreignKey['columns']);

            $foreignKey['references_columns'] =
                array_values($foreignKey['references_columns']);
        }

        unset($foreignKey);

        usort(
            $foreignKeys,
            fn(array $a, array $b): int =>
                strcmp($this->canonicalJson($a), $this->canonicalJson($b))
        );

        return $foreignKeys;
    }

    private function normalizeDesiredForeignKeys(array $foreignKeys): array
    {
        $normalized = [];

        foreach ($foreignKeys as $foreignKey) {
            $normalized[] = [
                'columns' => array_values($foreignKey['columns']),
                'references_table' =>
                    $foreignKey['references_table'],
                'references_columns' =>
                    array_values($foreignKey['references_columns']),
                'on_update' => strtoupper(
                    $foreignKey['on_update'] ?? 'NO ACTION'
                ),
                'on_delete' => strtoupper(
                    $foreignKey['on_delete'] ?? 'NO ACTION'
                ),
            ];
        }

        usort(
            $normalized,
            fn(array $a, array $b): int =>
                strcmp($this->canonicalJson($a), $this->canonicalJson($b))
        );

        return $normalized;
    }

    private function getIndexes(string $tableName): array
    {
        $statement = $this->pdo->query(
            'PRAGMA index_list(' .
            $this->quoteSqlString($tableName) .
            ')'
        );

        $indexes = [];

        foreach ($statement->fetchAll() as $index) {
            $indexName = $index['name'];

            $columnStatement = $this->pdo->query(
                'PRAGMA index_info(' .
                $this->quoteSqlString($indexName) .
                ')'
            );

            $columns = [];

            foreach ($columnStatement->fetchAll() as $column) {
                $columns[(int) $column['seqno']] = $column['name'];
            }

            ksort($columns);

            $indexes[$indexName] = [
                'unique' => (bool) $index['unique'],
                'columns' => array_values($columns),
            ];
        }

        return $indexes;
    }

    private function tableExists(string $tableName): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM sqlite_schema
             WHERE type = 'table'
               AND name = :name
             LIMIT 1"
        );

        $statement->execute(['name' => $tableName]);

        return $statement->fetchColumn() !== false;
    }

    private function getUserTableNames(): array
    {
        $statement = $this->pdo->query(
            "SELECT name
             FROM sqlite_schema
             WHERE type = 'table'
               AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        );

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function validateForeignKeys(): void
    {
        $rows = $this->pdo
            ->query('PRAGMA foreign_key_check')
            ->fetchAll();

        if ($rows !== []) {
            throw new SchemaException(
                'Foreign-key validation failed: ' .
                json_encode(
                    $rows,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                )
            );
        }
    }

    private function validateSchema(): void
    {
        if (
            !isset($this->schema['tables']) ||
            !is_array($this->schema['tables'])
        ) {
            throw new SchemaException(
                'The JSON must contain a "tables" object.'
            );
        }

        foreach ($this->schema['tables'] as $tableName => $table) {
            $this->validateIdentifier($tableName, 'table');

            if (
                !isset($table['columns']) ||
                !is_array($table['columns']) ||
                $table['columns'] === []
            ) {
                throw new SchemaException(
                    "Table {$tableName} must contain columns."
                );
            }

            $primaryKeyCount = 0;

            foreach ($table['columns'] as $columnName => $column) {
                $this->validateIdentifier($columnName, 'column');

                if (!is_array($column) || !isset($column['type'])) {
                    throw new SchemaException(
                        "Column {$tableName}.{$columnName} needs a type."
                    );
                }

                $this->normalizeType($column['type']);

                if (($column['primary_key'] ?? false) === true) {
                    $primaryKeyCount++;
                }
            }

            if ($primaryKeyCount > 1) {
                throw new SchemaException(
                    "Table {$tableName} defines multiple column-level " .
                    'primary keys. Composite primary keys are not ' .
                    'supported by this example.'
                );
            }

            foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
                foreach (
                    [
                        'columns',
                        'references_table',
                        'references_columns',
                    ] as $required
                ) {
                    if (!array_key_exists($required, $foreignKey)) {
                        throw new SchemaException(
                            "Foreign key in {$tableName} is missing " .
                            "{$required}."
                        );
                    }
                }

                if (
                    count($foreignKey['columns']) !==
                    count($foreignKey['references_columns'])
                ) {
                    throw new SchemaException(
                        "Foreign-key column count mismatch in {$tableName}."
                    );
                }

                if (isset($foreignKey['on_delete'])) {
                    $this->validateForeignKeyAction(
                        $foreignKey['on_delete']
                    );
                }

                if (isset($foreignKey['on_update'])) {
                    $this->validateForeignKeyAction(
                        $foreignKey['on_update']
                    );
                }
            }

            foreach ($table['indexes'] ?? [] as $index) {
                if (
                    !isset($index['name'], $index['columns']) ||
                    !is_array($index['columns']) ||
                    $index['columns'] === []
                ) {
                    throw new SchemaException(
                        "Invalid index in table {$tableName}."
                    );
                }

                $this->validateIdentifier($index['name'], 'index');

                foreach ($index['columns'] as $columnName) {
                    if (!isset($table['columns'][$columnName])) {
                        throw new SchemaException(
                            "Index {$index['name']} references missing " .
                            "column {$tableName}.{$columnName}."
                        );
                    }
                }
            }
        }
    }

    private function loadSchema(string $schemaFile): array
    {
        if (!is_file($schemaFile)) {
            throw new SchemaException(
                "Schema file does not exist: {$schemaFile}"
            );
        }

        $json = file_get_contents($schemaFile);

        if ($json === false) {
            throw new SchemaException(
                "Cannot read schema file: {$schemaFile}"
            );
        }

        try {
            $schema = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new SchemaException(
                'Invalid JSON: ' . $exception->getMessage(),
                previous: $exception
            );
        }

        if (!is_array($schema)) {
            throw new SchemaException(
                'The root JSON value must be an object.'
            );
        }

        return $schema;
    }

    private function createBackup(): void
    {
        if (!is_file($this->databaseFile)) {
            return;
        }

        $backupFile = sprintf(
            '%s.backup-%s',
            $this->databaseFile,
            date('Ymd-His')
        );

        /*
         * Ensure pending WAL data is written before copying the database.
         */
        $this->pdo->exec('PRAGMA wal_checkpoint(FULL)');

        if (!copy($this->databaseFile, $backupFile)) {
            throw new SchemaException(
                "Could not create backup: {$backupFile}"
            );
        }

        $this->log("[BACKUP] {$backupFile}");
    }

    private function desiredDefaultSql(array $column): ?string
    {
        if (!array_key_exists('default', $column)) {
            return null;
        }

        if (($column['default_raw'] ?? false) === true) {
            $raw = trim((string) $column['default']);

            $allowedRawDefaults = [
                'CURRENT_TIME',
                'CURRENT_DATE',
                'CURRENT_TIMESTAMP',
            ];

            if (
                !in_array(
                    strtoupper($raw),
                    $allowedRawDefaults,
                    true
                ) &&
                !preg_match('/^\(.+\)$/s', $raw)
            ) {
                throw new SchemaException(
                    "Unsafe raw default expression: {$raw}"
                );
            }

            return $raw;
        }

        return $this->sqlLiteral($column['default']);
    }

    private function normalizeDefault(?string $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $default = trim($default);

        while (
            strlen($default) >= 2 &&
            $default[0] === '(' &&
            $default[strlen($default) - 1] === ')'
        ) {
            $default = trim(substr($default, 1, -1));
        }

        return strtoupper($default);
    }

    private function normalizeType(mixed $type): string
    {
        $type = strtoupper(trim((string) $type));

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new SchemaException(
                "Unsupported SQLite type: {$type}"
            );
        }

        return $type;
    }

    private function validateForeignKeyAction(mixed $action): string
    {
        $action = strtoupper(trim((string) $action));

        if (
            !in_array(
                $action,
                self::ALLOWED_FOREIGN_KEY_ACTIONS,
                true
            )
        ) {
            throw new SchemaException(
                "Invalid foreign-key action: {$action}"
            );
        }

        return $action;
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->pdo->quote((string) $value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function quoteSqlString(string $value): string
    {
        return $this->pdo->quote($value);
    }

    private function validateIdentifier(
        string $identifier,
        string $kind
    ): void {
        if ($identifier === '') {
            throw new SchemaException(
                ucfirst($kind) . ' name cannot be empty.'
            );
        }

        if (str_starts_with(strtolower($identifier), 'sqlite_')) {
            throw new SchemaException(
                "The {$kind} name {$identifier} uses SQLite's " .
                'reserved sqlite_ prefix.'
            );
        }
    }

    private function canonicalJson(array $value): string
    {
        $this->sortRecursively($value);

        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    private function sortRecursively(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }

        unset($item);
    }

    private function reportDifferences(
        string $tableName,
        array $differences
    ): void {
        foreach ($differences['missing_columns'] as $columnName) {
            $this->log(
                "[MISSING COLUMN] {$tableName}.{$columnName}"
            );
        }

        foreach ($differences['extra_columns'] as $columnName) {
            $this->log(
                "[EXTRA COLUMN] {$tableName}.{$columnName}"
            );
        }

        foreach (
            $differences['changed_columns'] as
            $columnName => $changes
        ) {
            $this->log(
                "[CHANGED COLUMN] {$tableName}.{$columnName}: " .
                implode(', ', $changes)
            );
        }

        if ($differences['foreign_keys_changed']) {
            $this->log(
                "[FOREIGN KEYS CHANGED] {$tableName}"
            );
        }
    }

    private function log(string $message): void
    {
        $this->messages[] = $message;
    }
}

/*
|--------------------------------------------------------------------------
| Command-line execution
|--------------------------------------------------------------------------
*/

$arguments = array_slice($argv, 1);

$destructive = in_array('--destructive', $arguments, true);

/*
 * Missing tables are dropped only when this explicit second flag is used.
 * This prevents an accidental temporary omission from deleting a full table.
 */
$dropMissingTables =
    $destructive &&
    in_array('--drop-missing-tables', $arguments, true);

$databaseFile = __DIR__ . '/database.sqlite';
$schemaFile = __DIR__ . '/schema.json';

try {
    $synchronizer = new SQLiteSchemaSynchronizer(
        databaseFile: $databaseFile,
        schemaFile: $schemaFile,
        destructive: $destructive,
        dropMissingTables: $dropMissingTables
    );

    $synchronizer->synchronize();
    $synchronizer->printReport();
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Synchronization failed: ' .
        $exception->getMessage() .
        PHP_EOL
    );

    exit(1);
}
