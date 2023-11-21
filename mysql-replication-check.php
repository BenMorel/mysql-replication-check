<?php

/**
 * This script checks if the data in MySQL master and slave servers are in sync.
 *
 * You need PHP 5.4+ and the PDO_MYSQL driver to run this script.
 *
 * https://github.com/BenMorel/mysql-replication-check
 */

$app = new App($argv);
$app->run();

final class App
{
    /** @var string */
    private $programName;

    /** @var Options */
    private $options;
    
    /** @var Output */
    private $output;

    /**
     * @param string[] $argv
     */
    public function __construct(array $argv)
    {
        $this->programName = $argv[0];
        $this->options = $this->getOptions();
        $this->output = new Output(! $this->options->quiet);
    }

    /**
     * @return never
     */
    public function run()
    {
        try {
            $this->doRun();
        } catch (Exception $e) {
            $this->output->writeln();
            $this->output->writeln(sprintf('%s: %s', get_class($e), $e->getMessage()));
            exit(1);
        }
    }

    /**
     * @return never
     */
    private function doRun()
    {
        $master = new Database($this->options->master);
        $slave  = new Database($this->options->slave);

        $masterTables = $master->getTables();
        $slaveTables = $slave->getTables();

        if ($this->options->tables !== null) {
            $masterTables = $this->filterTables($masterTables, $this->options->tables, false);
        }
        if ($this->options->ignoreTables !== null) {
            $masterTables = $this->filterTables($masterTables, $this->options->ignoreTables, true);
        }

        if (! $masterTables) {
            $this->output->writeln('Nothing to do.');
            exit(1);
        }

        $maxTableNameLength = 0;

        foreach ($masterTables as $table) {
            $length = strlen($table->database) + strlen($table->table) + 1;
            if ($length > $maxTableNameLength) {
                $maxTableNameLength = $length;
            }
        }

        $this->output->writeVerbose(str_repeat(' ', $maxTableNameLength + 1));
        $this->output->writelnVerbose('ML MB MC SW SL MU SC SU');

        $check = '.  ';

        $tablesInError = [];

        $totalMasterLockTime = 0.0;
        $totalSlaveLockTime  = 0.0;

        $longestMasterLockTime = 0.0;
        $longestSlaveLockTime  = 0.0;

        $startTime = microtime(true);

        foreach ($masterTables as $table) {
            $this->output->writeVerbose(str_pad($table->database . '.' . $table->table, $maxTableNameLength + 1));

            if (! in_array($table, $slaveTables)) { // by-value comparison!
                $this->output->writelnVerbose(str_repeat($check, 8) . 'ERR - Table not found');

                $tablesInError[] = $table;

                continue;
            }

            $master->lockTable($table);
            $this->output->writeVerbose($check);

            $masterLockStartTime = microtime(true);

            $masterPosition = $master->getMasterBinlogPosition();
            $this->output->writeVerbose($check);

            $masterChecksum = $master->checksumTable($table);
            $this->output->writeVerbose($check);

            $slave->waitForMaster($masterPosition);
            $this->output->writeVerbose($check);

            $slave->lockTable($table);
            $this->output->writeVerbose($check);

            $slaveLockStartTime = microtime(true);

            $master->unlockTables();
            $this->output->writeVerbose($check);

            $masterLockEndTime = microtime(true);
            $masterLockTime = $masterLockEndTime - $masterLockStartTime;
            $totalMasterLockTime += $masterLockTime;

            if ($masterLockTime > $longestMasterLockTime) {
                $longestMasterLockTime = $masterLockTime;
            }

            $slaveChecksum = $slave->checksumTable($table);
            $this->output->writeVerbose($check);

            $slave->unlockTables();
            $this->output->writeVerbose($check);

            $slaveLockEndTime = microtime(true);
            $slaveLockTime = $slaveLockEndTime - $slaveLockStartTime;
            $totalSlaveLockTime += $slaveLockTime;

            if ($slaveLockTime > $longestSlaveLockTime) {
                $longestSlaveLockTime = $slaveLockTime;
            }

            if ($slaveChecksum === $masterChecksum) {
                $this->output->writeVerbose('OK');
            } else {
                $this->output->writeVerbose('ERR - Checksum');
                $tablesInError[] = $table;
            }

            $this->output->writelnVerbose();
        }

        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime);

        $this->output->writelnVerbose();
        $this->output->writelnVerbose(sprintf('Total time: %.0f seconds', $totalTime));

        $this->output->writelnVerbose();
        $this->output->writelnVerbose(sprintf('Total master lock time: %.0f seconds', $totalMasterLockTime));
        $this->output->writelnVerbose(sprintf('Longest master lock time: %.1f seconds', $longestMasterLockTime));

        $this->output->writelnVerbose();
        $this->output->writelnVerbose(sprintf('Total slave lock time: %.0f seconds', $totalSlaveLockTime));
        $this->output->writelnVerbose(sprintf('Longest slave lock time: %.1f seconds', $longestSlaveLockTime));

        $this->output->writelnVerbose();

        if (! $this->options->quiet || $tablesInError) {
            $this->output->writeln(sprintf('Tables in error: %d', count($tablesInError)));
        }

        foreach ($tablesInError as $table) {
            $this->output->writeln(sprintf(' - %s.%s', $table->database, $table->table));
        }

        exit($tablesInError ? 1 : 0);
    }

    /**
     * @param Table[] $tables
     * @param string $pattern
     * @param bool $inverse
     * @return Table[]
     */
    private function filterTables(array $tables, $pattern, $inverse)
    {
        $filters = explode(',', $pattern);

        $result = [];

        foreach ($tables as $table) {
            $match = false;
            foreach ($filters as $filter) {
                if ($this->tableMatchesFilter($table, $filter)) {
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
     * @param string $filter
     * @return bool
     */
    private function tableMatchesFilter(Table $table, $filter)
    {
        $databaseAndTablePatterns = explode('.', $filter);

        if (count($databaseAndTablePatterns) !== 2) {
            $this->output->writeln("Invalid filter: $filter");
            $this->output->writeln('Please use this format: database.table');
            $this->output->writeln('You can use a wildcard * at any position.');
            exit(1);
        }

        $databaseAndTablePatterns = array_map(function($pattern) {
            return '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
        }, $databaseAndTablePatterns);

        list ($databasePattern, $tablePattern) = $databaseAndTablePatterns;

        return preg_match($databasePattern, $table->database) === 1
            && preg_match($tablePattern, $table->table) === 1;
    }

    /**
     * @return Options
     */
    private function getOptions()
    {
        /**
         * @var array{
         *     master-host?: string,
         *     master-port?: string,
         *     master-user?: string,
         *     master-password?: string,
         *     master-ssl-ca?: string,
         *     slave-host?: string,
         *     slave-port?: string,
         *     slave-user?: string,
         *     slave-password?: string,
         *     slave-ssl-ca?: string,
         *     tables?: string,
         *     ignore-tables?: string,
         *     quiet?: false,
         * } $options
         */
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
            'ignore-tables:',
            'quiet'
        ]);

        if (! isset($options['master-host']) || ! isset($options['slave-host'])) {
            $this->showUsageAndExit();
        }

        $master = new Server(
            $options['master-host'],
            isset($options['master-port']) ? $options['master-host'] : null,
            isset($options['master-user']) ? $options['master-user'] : null,
            isset($options['master-password']) ? $options['master-password'] : null,
            isset($options['master-ssl-ca']) ? $options['master-ssl-ca'] : null
        );

        $slave = new Server(
            $options['slave-host'],
            isset($options['slave-port']) ? $options['slave-host'] : null,
            isset($options['slave-user']) ? $options['slave-user'] : null,
            isset($options['slave-password']) ? $options['slave-password'] : null,
            isset($options['slave-ssl-ca']) ? $options['slave-ssl-ca'] : null
        );

        return new Options(
            $master,
            $slave,
            isset($options['tables']) ? $options['tables'] : null,
            isset($options['ignore-tables']) ? $options['ignore-tables'] : null,
            isset($options['quiet'])
        );
    }

    /**
     * @return never
     */
    private function showUsageAndExit() {
        $this->output->writeln(sprintf(
<<<EOF
Usage: php %s [options]

Available options: 
    --master-host        Required    The master server hostname
    --master-port        Optional    The master server port
    --master-user        Optional    The master server username
    --master-password    Optional    The master server password
    --master-ssl-ca      Optional    The path to an SSL CA file for the master
    
    --slave-host         Required    The slave server hostname
    --slave-port         Optional    The slave server port
    --slave-user         Optional    The slave server username
    --slave-password     Optional    The slave server password
    --slave-ssl-ca       Optional    The path to an SSL CA file for the slave
    
    --tables             Optional    A comma-separated list of tables to check
    --ignore-tables      Optional    A comma-separated list of tables to ignore
    --quiet              Optional    Set this flag to only output something if the check fails
    
Example:
    php %s \
        --master-host=localhost \
        --slave-host=replica.example.com \
        --tables="foo.*,bar.*" \
        --ignore-tables="foo.bar,foo.baz"
EOF
        , $this->programName, $this->programName));

        exit(1);
    }
}

final class Database
{
    /** @var PDO */
    private $pdo;

    /**
     * @param Server $server
     */
    public function __construct(Server $server)
    {
        $dsn = 'mysql:host=' . $server->host;

        if ($server->port !== null) {
            $dsn .= ';port=' . $server->port;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];

        if ($server->sslCa !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $server->sslCa;
        }

        $this->pdo = new PDO($dsn, $server->user, $server->password, $options);
    }

    /**
     * @return Table[]
     */
    public function getTables()
    {
        $statement = $this->pdo->query('SHOW DATABASES');

        /** @var string[] $databases */
        $databases = $statement->fetchAll(PDO::FETCH_COLUMN);

        $databases = array_filter($databases, function($database) {
            return ! $this->isInternalDatabase($database);
        });

        $tables = [];

        foreach ($databases as $database) {
            $statement = $this->pdo->query(sprintf(
                "SHOW FULL TABLES FROM %s WHERE Table_Type != 'VIEW'",
                $this->quoteIdentifier($database)
            ));

            /** @var string[] $databaseTables */
            $databaseTables = $statement->fetchAll(PDO::FETCH_COLUMN);

            foreach ($databaseTables as $table) {
                $tables[] = new Table($database, $table);
            }
        }

        return $tables;
    }

    /**
     * @return void
     */
    public function lockTable(Table $table)
    {
        $this->pdo->exec(sprintf('LOCK TABLES %s READ', $this->quoteTableName($table)));
    }

    /**
     * @return void
     */
    public function unlockTables()
    {
        $this->pdo->exec('UNLOCK TABLES');
    }

    /**
     * @return BinlogPosition
     */
    public function getMasterBinlogPosition()
    {
        $statement = $this->pdo->query('SHOW MASTER STATUS');

        /** @var array{File: string, Position: string}|false $result */
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            throw new Exception('SHOW MASTER STATUS returned no result; is binary logging enabled on the master server?');
        }

        return new BinlogPosition(
            $result['File'],
            $result['Position']
        );
    }

    /**
     * @return void
     */
    public function waitForMaster(BinlogPosition $binlogPosition)
    {
        $statement = $this->pdo->prepare('SELECT MASTER_POS_WAIT(?, ?)');
        $statement->execute([
            $binlogPosition->file,
            $binlogPosition->position
        ]);

        $result = $statement->fetchColumn();

        if ($result === null) {
            throw new Exception('MASTER_POS_WAIT() failed; is replication started on the slave server?');
        }
    }

    /**
     * @return string
     */
    public function checksumTable(Table $table)
    {
        $statement = $this->pdo->query(sprintf('CHECKSUM TABLE %s', $this->quoteTableName($table)));

        /** @var array{Checksum: string} $result */
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return $result['Checksum'];
    }

    /**
     * @return string
     */
    private function quoteTableName(Table $table)
    {
        return sprintf(
            '%s.%s',
            $this->quoteIdentifier($table->database),
            $this->quoteIdentifier($table->table)
        );
    }

    /**
     * @param string $name
     * @return string
     */
    private function quoteIdentifier($name)
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isInternalDatabase($name)
    {
        return in_array($name, [
            'mysql',
            'information_schema',
            'performance_schema',
            'sys',
        ], true);
    }
}

final class Output
{
    /** @var bool */
    private $verbose;

    /**
     * @param bool $verbose
     */
    public function __construct($verbose)
    {
        $this->verbose = $verbose;
    }

    /**
     * @param string $message
     * @return void
     */
    public function write($message)
    {
        echo $message;
    }

    /**
     * @param string $message
     * @return void
     */
    public function writeln($message = '')
    {
        echo $message, PHP_EOL;
    }

    /**
     * @param string $message
     * @return void
     */
    public function writeVerbose($message)
    {
        if ($this->verbose) {
            $this->write($message);
        }
    }

    /**
     * @param string $message
     * @return void
     */
    public function writelnVerbose($message = '')
    {
        if ($this->verbose) {
            $this->writeln($message);
        }
    }
}

/**
 * @psalm-immutable
 */
final class Options
{
    /** @var Server */
    public $master;

    /** @var Server */
    public $slave;

    /** @var string|null */
    public $tables;

    /** @var string|null */
    public $ignoreTables;

    /** @var bool */
    public $quiet;

    /**
     * @param string|null $tables
     * @param string|null $ignoreTables
     * @param bool $quiet
     */
    public function __construct(
        Server $master,
        Server $slave,
        $tables,
        $ignoreTables,
        $quiet
    ) {
        $this->master = $master;
        $this->slave = $slave;
        $this->tables = $tables;
        $this->ignoreTables = $ignoreTables;
        $this->quiet = $quiet;
    }
}

/**
 * @psalm-immutable
 */
final class Server
{
    /** @var string */
    public $host;

    /** @var string|null */
    public $port;

    /** @var string|null */
    public $user;

    /** @var string|null */
    public $password;

    /** @var string|null */
    public $sslCa;

    /**
     * @param string $host
     * @param string|null $port
     * @param string|null $user
     * @param string|null $password
     * @param string|null $sslCa
     */
    public function __construct(
        $host,
        $port,
        $user,
        $password,
        $sslCa
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->sslCa = $sslCa;
    }
}

/**
 * @psalm-immutable
 */
final class Table
{
    /** @var string */
    public $database;

    /** @var string */
    public $table;

    /**
     * @param string $database
     * @param string $table
     */
    public function __construct($database, $table)
    {
        $this->database = $database;
        $this->table = $table;
    }
}

/**
 * @psalm-immutable
 */
final class BinlogPosition
{
    /** @var string */
    public $file;

    /** @var string */
    public $position;

    /**
     * @param string $file
     * @param string $position
     */
    public function __construct($file, $position)
    {
        $this->file = $file;
        $this->position = $position;
    }
}
