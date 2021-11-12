<?php

function usage()  {
    printf('Usage: php %s [options]' . PHP_EOL, $GLOBALS['argv'][0]);

    printf(PHP_EOL);
    printf('Available options: ' . PHP_EOL);

    printf('    --master-host        Required    The master server hostname' . PHP_EOL);
    printf('    --master-port        Optional    The master server port' . PHP_EOL);
    printf('    --master-user        Optional    The master server username' . PHP_EOL);
    printf('    --master-password    Optional    The master server password' . PHP_EOL);
    printf('    --master-ssl-ca      Optional    The path to an SSL CA file for the master' . PHP_EOL);

    printf('    --slave-host         Required    The slave server hostname' . PHP_EOL);
    printf('    --slave-port         Optional    The slave server port' . PHP_EOL);
    printf('    --slave-user         Optional    The slave server username' . PHP_EOL);
    printf('    --slave-password     Optional    The slave server password' . PHP_EOL);
    printf('    --slave-ssl-ca       Optional    The path to an SSL CA file for the slave' . PHP_EOL);

    printf('    --tables             Optional    A comma-separated list of tables to check' . PHP_EOL);
    printf('    --ignore-tables      Optional    A comma-separated list of tables to ignore' . PHP_EOL);

    printf(PHP_EOL);
    printf('Example: ' . PHP_EOL);
    printf('    php %s \\' . PHP_EOL, $GLOBALS['argv'][0]);
    printf('        --master-host=localhost \\' . PHP_EOL);
    printf('        --slave-host=replica.example.com \\' . PHP_EOL);
    printf('        --tables="foo.*,bar.*"' . PHP_EOL);
    printf('        --ignore-tables="foo.bar,foo.baz"' . PHP_EOL);

    exit(1);
}

$options = getopt('', [
    'master-host:',
    'master-port:',
    'master-user:',
    'master-password:',
    'master-ssl-ca:',
    'slave-host:',
    'slave-port:',
    'slave-user:',
    'slave-password:',
    'slave-ssl-ca:',
    'tables:',
    'ignore-tables:'
]);

foreach ($options as $key => $value) {
    if (strpos($key, '-') !== false) {
        list ($type, $subkey) = explode('-', $key, 2);
        $options[$type][$subkey] = $value;
        unset($options[$key]);
    }
}

if (! isset($options['master']['host'])) {
    usage();
}

if (! isset($options['slave']['host'])) {
    usage();
}

function createPDO(array $values) {
    $dsn = 'mysql:host=' . $values['host'];

    if (isset($values['port'])) {
        $dsn .= ';port=' . $values['port'];
    }

    $user     = isset($values['user']) ? $values['user'] : '';
    $password = isset($values['password']) ? $values['password'] : '';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ];

    if (isset($values['ssl-ca'])) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $values['ssl-ca'];
    }

    return new PDO($dsn, $user, $password, $options);
}

function loadTables(PDO $master) {
    $databases = [];

    $statement = $master->query('SHOW DATABASES');
    while (false !== $database = $statement->fetchColumn()) {
        $databases[] = $database;
    }

    $tables = [];

    foreach ($databases as $database) {
        $statement = $master->query("SHOW FULL TABLES FROM $database WHERE Table_Type != 'VIEW'");
        while (false !== $table = $statement->fetchColumn()) {
            $tables[] = $database . '.' . $table;
        }
    }

    return $tables;
}

/**
 * @param string[] $tables
 * @param string $pattern
 * @param bool $inverse
 *
 * @return string[]
 */
function filterTables(array $tables, $pattern, $inverse) {
    $filters = explode(',', $pattern);
    foreach ($filters as $key => $filter) {
        $filter = str_replace('.', '\.', $filter);
        $filter = str_replace('*', '.*', $filter);
        $filter = '/^' . $filter . '$/';
        $filters[$key] = $filter;
    }

    $result = [];

    foreach ($tables as $table) {
        $match = false;
        foreach ($filters as $filter) {
            if (preg_match($filter, $table) === 1) {
                $match = true;
                break;
            }
        }

        if ($match !== $inverse) {
            $result[] = $table;
        }
    }

    return $result;
}

/**
 * @param string $name
 *
 * @return string
 */
function quoteIdentifier($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

try {
    $master = createPDO($options['master']);
    $slave  = createPDO($options['slave']);

    $tables = loadTables($master);

    if (isset($options['tables'])) {
        $tables = filterTables($tables, $options['tables'], false);
    }
    if (isset($options['ignore']['tables'])) {
        $tables = filterTables($tables, $options['ignore']['tables'], true);
    }

    if (! $tables) {
        echo 'Nothing to do.', PHP_EOL;
        exit(0);
    }

    $maxTableNameLength = 0;

    foreach ($tables as $table) {
        $length = strlen($table);
        if ($length > $maxTableNameLength) {
            $maxTableNameLength = $length;
        }
    }

    echo str_repeat(' ', $maxTableNameLength + 1);
    echo 'ML MB MC SW SL MU SC SU', PHP_EOL;

    $check = '.  ';

    $totalErrors = 0;

    $totalMasterLockTime = 0.0;
    $totalSlaveLockTime  = 0.0;

    $longestMasterLockTime = 0.0;
    $longestSlaveLockTime  = 0.0;

    $startTime = microtime(true);

    foreach ($tables as $table) {
        echo str_pad($table, $maxTableNameLength + 1, ' ', STR_PAD_RIGHT);

        $master->query('LOCK TABLES ' . quoteIdentifier($table) . ' READ');
        echo $check;

        $masterLockStartTime = microtime(true);

        $statement = $master->query('SHOW MASTER STATUS');
        $status = $statement->fetch(PDO::FETCH_ASSOC);
        $binlogFile = $status['File'];
        $binlogPosition = $status['Position'];
        echo $check;

        $statement = $master->query('CHECKSUM TABLE ' . quoteIdentifier($table));
        $checksum = $statement->fetch(PDO::FETCH_ASSOC);
        $masterChecksum = $checksum['Checksum'];
        echo $check;

        $statement = $slave->prepare('SELECT MASTER_POS_WAIT(?, ?)');
        $statement->execute([$binlogFile, $binlogPosition]);
        $statement->fetch();
        echo $check;

        $slave->query('LOCK TABLES ' . quoteIdentifier($table) . ' READ');
        echo $check;

        $slaveLockStartTime = microtime(true);

        $master->query('UNLOCK TABLES');
        echo $check;

        $masterLockEndTime = microtime(true);
        $masterLockTime = $masterLockEndTime - $masterLockStartTime;
        $totalMasterLockTime += $masterLockTime;

        if ($masterLockTime > $longestMasterLockTime) {
            $longestMasterLockTime = $masterLockTime;
        }

        $statement = $slave->query('CHECKSUM TABLE ' . quoteIdentifier($table));
        $checksum = $statement->fetch(PDO::FETCH_ASSOC);
        $slaveChecksum = $checksum['Checksum'];
        echo $check;

        $slave->query('UNLOCK TABLES');
        echo $check;

        $slaveLockEndTime = microtime(true);
        $slaveLockTime = $slaveLockEndTime - $slaveLockStartTime;
        $totalSlaveLockTime += $slaveLockTime;

        if ($slaveLockTime > $longestSlaveLockTime) {
            $longestSlaveLockTime = $slaveLockTime;
        }

        if ($slaveChecksum === $masterChecksum) {
            echo 'OK';
        } else {
            echo 'ERR';
            $totalErrors++;
        }

        echo PHP_EOL;
    }

    $endTime = microtime(true);
    $totalTime = ($endTime - $startTime);

    echo PHP_EOL;
    printf('Total time: %.0f seconds.' . PHP_EOL, $totalTime);

    echo PHP_EOL;
    printf('Total master lock time: %.0f seconds.' . PHP_EOL, $totalMasterLockTime);
    printf('Longest master lock time: %.1f seconds.' . PHP_EOL, $longestMasterLockTime);

    echo PHP_EOL;
    printf('Total slave lock time: %.0f seconds.' . PHP_EOL, $totalSlaveLockTime);
    printf('Longest slave lock time: %.1f seconds.' . PHP_EOL, $longestSlaveLockTime);

    echo PHP_EOL;
    printf('Tables in error: %d.' . PHP_EOL, $totalErrors);

    exit(0);
} catch (\PDOException $e) {
    echo 'PDO exception: ', $e->getMessage(), PHP_EOL;
    exit(1);
}
