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
        foreach ($filters as & $filter) {
            $filter = str_replace('.', '\.', $filter);
            $filter = str_replace('*', '.+', $filter);
            $filter = '/^' . $filter . '$/';
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

    $errorTables = [];

    foreach ($tables as $table) {
        echo 'Locking ', $table, ' on master', PHP_EOL;
        $master->query('LOCK TABLES ' . $table . ' READ');

        echo 'Checking master binlog position', PHP_EOL;

        $statement = $master->query('SHOW MASTER STATUS');
        $status = $statement->fetch(PDO::FETCH_ASSOC);
        $binlogFile = $status['File'];
        $binlogPosition = $status['Position'];

        echo 'Wait until slave keeps up with master', PHP_EOL;

        $statement = $slave->prepare('SELECT MASTER_POS_WAIT(?, ?)');
        $statement->execute([$binlogFile, $binlogPosition]);
        $statement->fetch();

        echo 'Locking ', $table, ' on slave', PHP_EOL;
        $slave->query('LOCK TABLES ' . $table . ' READ');

        echo 'Checking ', $table, ' on master', PHP_EOL;

        $statement = $master->query('CHECKSUM TABLE ' . $table);
        $checksum = $statement->fetch(PDO::FETCH_ASSOC);
        $masterChecksum = $checksum['Checksum'];

        echo 'Result: ', $masterChecksum, PHP_EOL;

        echo 'Unlocking ', $table, ' on master', PHP_EOL;

        $master->query('UNLOCK TABLES');

        echo 'Checking ', $table, ' on slave', PHP_EOL;

        $statement = $slave->query('CHECKSUM TABLE ' . $table);
        $checksum = $statement->fetch(PDO::FETCH_ASSOC);
        $slaveChecksum = $checksum['Checksum'];

        echo 'Result: ', $slaveChecksum, PHP_EOL;

        echo 'Unlocking ', $table, ' on slave', PHP_EOL;

        $slave->query('UNLOCK TABLES');

        echo 'Result for ', $table, ': ';

        if ($masterChecksum == $slaveChecksum) {
            echo 'OK';
        } else {
            echo 'ERROR';
            $errorTables[] = $table;
        }

        echo PHP_EOL;
    }

    if ($errorTables) {
        echo 'Done. Checked ', count($tables), ' tables, ', count($errorTables), ' errors:', PHP_EOL;

        foreach ($errorTables as $table) {
            echo $table, PHP_EOL;
        }
    } else {
        echo 'Done. Checked ', count($tables), ' tables, no errors.', PHP_EOL;
    }
} catch (\PDOException $e) {
    echo 'PDO exception: ', $e->getMessage(), PHP_EOL;
    exit(1);
}
