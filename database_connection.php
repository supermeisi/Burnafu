<?php

declare(strict_types=1);

/**
 * Create a PDO connection to MySQL using environment variables.
 *
 * Supported variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_CHARSET
 */
function createDatabaseConnection(
    int $maximumAttempts = 1,
    int $retryDelaySeconds = 1
): PDO {
    if ($maximumAttempts < 1) {
        throw new InvalidArgumentException(
            'maximumAttempts must be at least 1.'
        );
    }

    $host = databaseEnvironmentValue('DB_HOST', 'db');
    $port = databaseEnvironmentValue('DB_PORT', '3306');
    $name = databaseEnvironmentValue('DB_NAME', 'wiki');
    $user = databaseEnvironmentValue('DB_USER', 'wiki');
    $password = requiredDatabaseEnvironmentValue('DB_PASSWORD');
    $charset = databaseEnvironmentValue('DB_CHARSET', 'utf8mb4');

    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('DB_PORT must be a valid TCP port.');
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException(
            'DB_NAME may contain only letters, numbers, and underscores.'
        );
    }

    if (!in_array($charset, ['utf8mb4', 'utf8', 'ascii'], true)) {
        throw new RuntimeException('Unsupported DB_CHARSET value.');
    }

    $dataSourceName = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        (int) $port,
        $name,
        $charset
    );

    $lastException = null;

    for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
        try {
            return new PDO(
                $dataSourceName,
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $exception) {
            $lastException = $exception;

            if ($attempt < $maximumAttempts && $retryDelaySeconds > 0) {
                sleep($retryDelaySeconds);
            }
        }
    }

    throw new RuntimeException(
        sprintf(
            'Could not connect to MySQL after %d attempt(s): %s',
            $maximumAttempts,
            $lastException?->getMessage() ?? 'unknown error'
        ),
        0,
        $lastException
    );
}

function databaseEnvironmentValue(string $name, string $default): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function requiredDatabaseEnvironmentValue(string $name): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        throw new RuntimeException(
            "Required database environment variable {$name} is not set."
        );
    }

    return $value;
}
