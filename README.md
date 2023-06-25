# MySQL replication check tool

This tool uses [CHECKSUM TABLE](http://dev.mysql.com/doc/en/checksum-table.html) to ensure that every table on a master and a slave MySQL / MariaDB server are in sync.
As opposed to several checksum tools, it is totally read-only: it does not write anything to the database, making it very safe.

This tool uses table locks. Depending on the size of your tables, the speed of your server, and/or the availability of a maintenance window during which these locks are acceptable, this can be a problem or not.
Locks are held for as little time as possible, and always one table at a time.

## Requirements

This tool requires PHP >= 5.4 and the [PDO_MYSQL](http://php.net/manual/en/ref.pdo-mysql.php) driver.

## Compatibility

This tool has been tested with MySQL 5.7 and MySQL 8.0, and MariaDB 10.5, but may work with other versions.
It works equally well with all types of replication: `STATEMENT`, `ROW` and `MIXED`, and all storage engines.

## Download

Download here: [mysql-replication-check.php](https://raw.githubusercontent.com/BenMorel/mysql-replication-check/master/mysql-replication-check.php)

## Usage

    php mysql-replication-check.php [options]

### Available options

| Option name         | Presence     | Description                                               |
| ------------------- | ------------ |-----------------------------------------------------------|
| `--master-host`     | **Required** | The master server hostname                                |
| `--master-port`     | Optional     | The master server port                                    |
| `--master-user`     | Optional     | The master server username                                |
| `--master-password` | Optional     | The master server password                                |
| `--master-ssl-ca`   | Optional     | The path to an SSL CA file for the master                 |
| `--slave-host`      | **Required** | The slave server hostname                                 |
| `--slave-port`      | Optional     | The slave server port                                     |
| `--slave-user`      | Optional     | The slave server username                                 |
| `--slave-password`  | Optional     | The slave server password                                 |
| `--slave-ssl-ca`    | Optional     | The path to an SSL CA file for the slave                  |
| `--tables`          | Optional     | A comma-separated list of tables to check                 |
| `--ignore-tables`   | Optional     | A comma-separated list of tables to ignore                |
| `--quiet`           | Optional     | Set this flag to only output something if the check fails |

### Filtering

By default, all tables from all non-system databases are checked.
Use the `--tables` to filter the tables to check. This parameter accepts a comma-separated list of tables,
optionally using the `*` placeholder. Each table is referenced with its database, in the `database.table` format.

### Example

    php mysql-replication-check.php \
        --master-host=localhost \
        --slave-host=replica.example.com \
        --tables="foo.*,bar.wp_*" \
        --ignore-tables="foo.bar,foo.baz"

## Output

Each checked table is output on a separate line, along with the current action being undertaken,
and finally the result of the sync check: `OK` or `ERR`.

             ML MB MC SW SL MU SC SU
    foo.user .  .  .  .  .  .  .  .  OK
    foo.post .  .  .  .  .  .  .  .  OK
    foo.tag  .  .  .  .  .  .  .  .  OK
    
    Total time: 5 seconds
    
    Total master lock time: 2 seconds
    Longest master lock time: 0.9 seconds
    
    Total slave lock time: 3 seconds
    Longest slave lock time: 1.5 seconds
    
    Tables in error: 0

Each column represents the current action being undertaken:

| Column | Target | Action                    |
| ----   | ------ | ------------------------- |
| `ML`   | Master | Lock table                |
| `MB`   | Master | Binlog position read      |
| `MC`   | Master | Checksum table            |
| `SW`   | Slave  | Wait for sync with master |
| `SL`   | Slave  | Lock table                |
| `MU`   | Master | Unlock table              |
| `SC`   | Slave  | Checksum table            |
| `SU`   | Slave  | Unlock table              |

The lock times give you an idea of the impact the tool had on the availability of the database.

## Cancelling the check

You can interrupt the check at any time with <kbd>Ctrl</kbd> + <kbd>C</kbd>.
The tool being read-only, there is no impact on the server.
Any locks will be released as soon as the current query is completed.
If a query such as `CHECKSUM TABLE` takes too long to complete, you can locate it with [SHOW PROCESSLIST](http://dev.mysql.com/doc/en/show-processlist.html) and safely [KILL](http://dev.mysql.com/doc/en/kill.html) it manually.

## License

This tool is released under the MIT license.
