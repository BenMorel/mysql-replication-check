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

    printf(PHP_EOL);
    printf('Example: ' . PHP_EOL);
    printf('    php %s \\' . PHP_EOL, $GLOBALS['argv'][0]);
    printf('        --master-host=localhost \\' . PHP_EOL);
    printf('        --slave-host=replica.example.com \\' . PHP_EOL);
    printf('        --tables="foo.*,bar.*"' . PHP_EOL);

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
    'tables:'
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

try {
    $master = createPDO($options['master']);
    $slave  = createPDO($options['slave']);

    $databases = [];

    $statement = $master->query('SHOW DATABASES');
    while (false !== $database = $statement->fetchColumn()) {
        $databases[] = $database;
    }

    $tables = [];

    foreach ($databases as $database) {
        $statement = $master->query('SHOW TABLES FROM ' . $database);
        while (false !== $table = $statement->fetchColumn()) {
            $tables[] = $database . '.' . $table;
        }
    }

    if (isset($options['tables'])) {
        $filters = explode(',', $options['tables']);
        foreach ($filters as $key => $filter) {
            $filter = str_replace('.', '\.', $filter);
            $filter = str_replace('*', '.*', $filter);
            $filter = '/^' . $filter . '$/';
            $filters[$key] = $filter;
        }

        foreach ($tables as $key => $table) {
            foreach ($filters as $filter) {
                if (preg_match($filter, $table)) {
                    continue 2;
                }
            }

            unset($tables[$key]);
        }
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

    $totalLockTime = 0.0;
    $startTime = microtime(true);

    foreach ($tables as $table) {
        echo str_pad($table, $maxTableNameLength + 1, ' ', STR_PAD_RIGHT);

        $master->query('LOCK TABLES ' . $table . ' READ');
        echo $check;

        $lockStartTime = microtime(true);

        $statement = $master->query('SHOW MASTER STATUS');
        $status = $statement->fetch(PDO::FETCH_ASSOC);
        $binlogFile = $status['File'];
        $binlogPosition = $status['Position'];
        echo $check;

        $statement = $master->query('CHECKSUM TABLE ' . $table);
        $checksum = $statement->fetch(PDO::FETCH_ASSOC);
        $masterChecksum = $checksum['Checksum'];
        echo $check;

        $statement = $slave->prepare('SELECT MASTER_POS_WAIT(?, ?)');
        $statement->execute([$binlogFile, $binlogPosition]);
        $statement->fetch();
        echo $check;

        $slave->query('LOCK TABLES ' . $table . ' READ');
        echo $check;

        $master->query('UNLOCK TABLES');
        echo $check;

        $lockEndTime = microtime(true);
        $totalLockTime += ($lockEndTime - $lockStartTime);

        $statement = $slave->query('CHECKSUM TABLE ' . $table);
        $checksum = $statement->fetch(PDO::FETCH_ASSOC);
        $slaveChecksum = $checksum['Checksum'];
        echo $check;

        $slave->query('UNLOCK TABLES');
        echo $check;

        echo ($slaveChecksum === $masterChecksum) ? 'OK' : 'ERR';
        echo PHP_EOL;
    }

    $endTime = microtime(true);
    $totalTime = ($endTime - $startTime);

    echo PHP_EOL;
    printf('Total time: %.0f seconds.' . PHP_EOL, $totalTime);
    printf('Total master lock time: %.0f seconds.' . PHP_EOL, $totalLockTime);

    exit(0);
} catch (\PDOException $e) {
    echo 'PDO exception: ', $e->getMessage(), PHP_EOL;
    exit(1);
}
