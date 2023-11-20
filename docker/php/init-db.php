<?php

/**
 * This script is executed on container start. It:
 *
 * - sets up replication,
 * - initializes the master,
 * - waits for the slave to catch up,
 * - modifies some data on the slave.
 */

const MASTER_HOST = 'mysql-master';
const MASTER_USER = 'root';
const MASTER_PASSWORD = 'master-password';

const SLAVE_HOST = 'mysql-slave';
const SLAVE_USER = 'root';
const SLAVE_PASSWORD = 'slave-password';

$masterSQL = [
    'CREATE DATABASE test',
    'USE test',
    'CREATE TABLE in_sync (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        PRIMARY KEY (id)
    )',
    'CREATE TABLE different_checksum (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        PRIMARY KEY (id)
    )',
    "INSERT INTO in_sync (name) VALUES ('a'), ('b'), ('c')",
    "INSERT INTO different_checksum (name) VALUES ('a'), ('b'), ('c')",
];

$slaveSQL = [
    'USE test',
    'INSERT INTO different_checksum(name) VALUES ("d")',
];

// connect to MySQL

$master = connect(MASTER_HOST, MASTER_USER, MASTER_PASSWORD);
$slave = connect(SLAVE_HOST, SLAVE_USER, SLAVE_PASSWORD);

// check if already initialized (in case of a restart)

$slaveStatus = $slave->query('SHOW SLAVE STATUS')->fetch();

if ($slaveStatus !== false) {
    echo "Already initialized.\n";
    exit(0);
}

// set up replication

$statement = $slave->prepare('CHANGE MASTER TO MASTER_HOST = ?, MASTER_USER = ?, MASTER_PASSWORD = ?');
$statement->execute([
    MASTER_HOST,
    MASTER_USER,
    MASTER_PASSWORD,
]);
$slave->exec('START SLAVE');

// initialize master

foreach ($masterSQL as $sql) {
    $master->exec($sql);
}

// wait for slave to catch up

$statement = $master->query('SHOW MASTER STATUS');

/** @var array{File: string, Position: string} $masterPos */
$masterPos = $statement->fetch(PDO::FETCH_ASSOC);

$statement = $slave->prepare('SELECT MASTER_POS_WAIT(?, ?, 60)');
$statement->execute([
    $masterPos['File'],
    $masterPos['Position'],
]);
$result = $statement->fetchColumn();

if ($result !== '1') {
    echo "Slave failed to catch up\n";
    exit(1);
}

// initialize slave

foreach ($slaveSQL as $sql) {
    $slave->exec($sql);
}

/**
 * @param string $host
 * @param string $user
 * @param string $password
 * @return PDO
 */
function connect($host, $user, $password)
{
    for ($i = 0; $i < 60; $i++) {
        try {
            $pdo = new PDO("mysql:host=$host", $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch (PDOException $e) {
            sleep(1);
        }
    }

    echo "Cannot connect to MySQL Server on $host\n";
    exit(1);
}
