<?php
/**
 * include/migrate.php — Automatic Database Migration Runner
 * ─────────────────────────────────────────────────────────
 * Runs numbered SQL migration files from /migrations/ in order.
 * Tracks applied migrations in a `db_migrations` table so each
 * script only executes once. Safe to call on every page load —
 * it exits quickly if nothing is pending.
 *
 * HOW TO ADD A NEW MIGRATION:
 *   1. Create a file in /migrations/ named NNN_description.sql
 *      (e.g. 004_add_something.sql)
 *   2. Deploy the file along with your code — it will run
 *      automatically on the next page load.
 *
 * @author  Jigme Dorji & Tshering
 */

function bbcc_run_migrations() {
    global $DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME;

    // ── Resolve paths ──────────────────────────────────────
    $migrationsDir = dirname(__DIR__) . '/migrations';
    if (!is_dir($migrationsDir)) {
        return; // No migrations folder — nothing to do
    }

    // ── Connect via PDO (needed for multi-statement & tracking) ──
    try {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
        $pdo = new PDO($dsn, $DB_USER, $DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        error_log("[BBCC Migrate] DB connection failed: " . $e->getMessage());
        return; // Don't break the site — just skip migrations
    }

    // ── Ensure the tracking table exists ────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `db_migrations` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `migration`  VARCHAR(255) NOT NULL UNIQUE,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── Get already-applied migrations ──────────────────────
    $applied = [];
    $stmt = $pdo->query("SELECT `migration` FROM `db_migrations`");
    while ($row = $stmt->fetch()) {
        $applied[$row['migration']] = true;
    }

    // ── Discover migration files (sorted by numeric prefix) ─
    $files = glob($migrationsDir . '/*.sql');
    if (!$files) {
        return;
    }
    sort($files); // Ensures 001 runs before 002, etc.

    // ── Run pending migrations ──────────────────────────────
    foreach ($files as $file) {
        $filename = basename($file);

        // Already applied? Skip
        if (isset($applied[$filename])) {
            continue;
        }

        $sql = file_get_contents($file);
        if (empty(trim($sql))) {
            continue;
        }

        try {
            // Some migrations use DELIMITER for stored procedures.
            // PDO::exec can't handle DELIMITER, so we use mysqli
            // for multi-statement execution when DELIMITER is present.
            if (stripos($sql, 'DELIMITER') !== false) {
                $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME);
                if ($mysqli->connect_error) {
                    throw new Exception("mysqli connect failed: " . $mysqli->connect_error);
                }
                $mysqli->set_charset('utf8mb4');

                // Execute the full SQL (mysqli handles DELIMITER natively
                // only via command-line, so we split manually)
                bbcc_exec_with_delimiter($mysqli, $sql);
                $mysqli->close();
            } else {
                // Simple migration — split on semicolons and run each statement
                $statements = bbcc_split_sql($sql);
                foreach ($statements as $statement) {
                    if (!empty(trim($statement))) {
                        $pdo->exec($statement);
                    }
                }
            }

            // Record successful migration
            $ins = $pdo->prepare("INSERT INTO `db_migrations` (`migration`) VALUES (?)");
            $ins->execute([$filename]);

            error_log("[BBCC Migrate] Applied: {$filename}");

        } catch (Exception $e) {
            error_log("[BBCC Migrate] FAILED on {$filename}: " . $e->getMessage());
            // Stop processing further migrations to keep order intact
            break;
        }
    }
}

/**
 * Split a SQL string on semicolons, ignoring semicolons inside strings.
 */
function bbcc_split_sql(string $sql): array {
    $statements = [];
    $current    = '';
    $inString   = false;
    $stringChar = '';
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        // Handle string literals
        if ($inString) {
            $current .= $char;
            if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = false;
            }
            continue;
        }

        if ($char === '\'' || $char === '"') {
            $inString   = true;
            $stringChar = $char;
            $current   .= $char;
            continue;
        }

        // Skip single-line comments
        if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
            $end = strpos($sql, "\n", $i);
            if ($end === false) break;
            $i = $end;
            continue;
        }

        // Statement terminator
        if ($char === ';') {
            $trimmed = trim($current);
            if (!empty($trimmed)) {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if (!empty($trimmed)) {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * Execute SQL that contains DELIMITER blocks via mysqli.
 * Splits the SQL into blocks separated by DELIMITER commands,
 * then executes each block with the appropriate delimiter.
 */
function bbcc_exec_with_delimiter(mysqli $mysqli, string $sql): void {
    // Normalise line endings
    $sql = str_replace("\r\n", "\n", $sql);
    $lines = explode("\n", $sql);

    $currentDelimiter = ';';
    $buffer = '';

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // DELIMITER directive
        if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
            // Execute any buffered SQL first (may contain multiple statements)
            $buf = trim($buffer);
            if (!empty($buf)) {
                bbcc_exec_multi($mysqli, $buf);
            }
            $buffer = '';
            $currentDelimiter = $m[1];
            continue;
        }

        // Check if line ends with current delimiter
        if ($currentDelimiter !== ';' && str_ends_with($trimmed, $currentDelimiter)) {
            $buffer .= substr($trimmed, 0, -strlen($currentDelimiter)) . "\n";
            $buf = trim($buffer);
            if (!empty($buf)) {
                if (!$mysqli->query($buf)) {
                    throw new Exception("SQL error: " . $mysqli->error . " — SQL: " . substr($buf, 0, 200));
                }
            }
            $buffer = '';
            continue;
        }

        $buffer .= $line . "\n";
    }

    // Execute any remaining SQL
    $buf = trim($buffer);
    if (!empty($buf)) {
        bbcc_exec_multi($mysqli, $buf);
    }
}

/**
 * Execute one or multiple SQL statements via mysqli.
 * Uses multi_query to handle semicolon-separated statements.
 */
function bbcc_exec_multi(mysqli $mysqli, string $sql): void {
    $sql = trim($sql);
    if (empty($sql)) return;

    if ($mysqli->multi_query($sql)) {
        // Consume all result sets
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
            if ($mysqli->errno) {
                throw new Exception("SQL error: " . $mysqli->error);
            }
        } while ($mysqli->next_result());

        // Check for error after all results consumed
        if ($mysqli->errno) {
            throw new Exception("SQL error: " . $mysqli->error);
        }
    } else {
        throw new Exception("SQL error: " . $mysqli->error . " — SQL: " . substr($sql, 0, 200));
    }
}

// Polyfill for PHP < 8.0
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
