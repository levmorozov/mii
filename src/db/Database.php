<?php declare(strict_types=1);

namespace mii\db;

use mii\core\Component;

/**
 * Database connection/query wrapper/helper.
 *
 *
 * @copyright  (c) 2015 Lev Morozov
 * @copyright  (c) 2008-2012 Kohana Team
 */
class Database extends Component
{
    // Query types
    public const SELECT = 1;
    public const INSERT = 2;
    public const UPDATE = 3;
    public const DELETE = 4;

    protected string $hostname = '127.0.0.1';
    protected string $username = '';
    protected ?string $password = '';
    protected string $database = '';
    protected int $port = 3306;

    protected ?string $charset = 'utf8';

    protected ?\mysqli $conn = null;

    /**
     * Connect to the database. This is called automatically when the first
     * query is executed.
     *
     *     $db->connect();
     *
     * @return  void
     * @throws  DatabaseException
     */
    public function connect(): void
    {
        try {
            $this->conn = \mysqli_connect(
                $this->hostname,
                $this->username,
                $this->password,
                $this->database,
                $this->port
            );
        } catch (\Exception $e) {
            // No connection exists
            $this->conn = null;
            $this->password = null;
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        $this->password = null;

        $this->conn->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

        if (!\is_null($this->charset)) {
            // Set the character set
            $this->conn->set_charset($this->charset);
        }
    }


    public function autoNativeTypes(bool $enable): bool
    {
        return $this->conn->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, $enable);
    }


    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Disconnect from the database. This is called automatically by [Database::__destruct].
     *
     * @return  boolean
     */
    public function disconnect()
    {
        try {
            // Database is assumed disconnected
            $status = true;

            if (\is_resource($this->conn) && $status = $this->conn->close()) {
                // Clear the connection
                $this->conn = null;
            }
        } catch (\Throwable $e) {
            // Database is probably not disconnected
            $status = !\is_resource($this->conn);
        }

        return $status;
    }


    public function __toString()
    {
        return 'db';
    }

    /**
     * Perform an SQL query of the given type.
     *
     *     // Make a SELECT query and use objects for results
     *     $db->query(Database::SELECT, 'SELECT * FROM groups', TRUE);
     *
     *     // Make a SELECT query and use "Model_User" for the results
     *     $db->query(Database::SELECT, 'SELECT * FROM users LIMIT 1', 'Model_User');
     *
     * @param integer $type Database::SELECT, Database::INSERT, etc
     * @param string  $sql SQL query
     * @param mixed   $as_object result object class string, TRUE for stdClass, FALSE for assoc array
     * @param array   $params object construct parameters for result class
     * @return  Result|null   Result for SELECT queries or null
     * @throws DatabaseException
     */
    public function query(?int $type, string $sql, $as_object = false, array $params = null): ?Result
    {
        // Make sure the database is connected
        !\is_null($this->conn) || $this->connect();

        \assert((config('debug') && ($benchmark = \mii\util\Profiler::start('Database', $sql))) || 1);

        // Execute the query
        $result = $this->conn->query($sql);

        if ($result === false || $this->conn->errno) {
            \assert((isset($benchmark) && \mii\util\Profiler::delete($benchmark)) || 1);

            throw new DatabaseException("{$this->conn->error} [ $sql ]", $this->conn->errno);
        }

        \assert((isset($benchmark) && \mii\util\Profiler::stop($benchmark)) || 1);

        if ($type === self::SELECT) {
            // Return an iterator of results
            return new Result($result, $as_object, $params);
        }

        return null;
    }


    public function multiQuery(string $sql): ?Result
    {
        $this->conn or $this->connect();
        \assert((config('debug') && ($benchmark = \mii\util\Profiler::start('Database', $sql))) || 1);

        // Execute the query
        $result = $this->conn->multi_query($sql);
        $affected_rows = 0;
        do {
            $affected_rows += $this->conn->affected_rows;
        } while ($this->conn->more_results() && $this->conn->next_result());

        \assert((isset($benchmark) && \mii\util\Profiler::stop($benchmark)) || 1);

        if ($result === false || $this->conn->errno) {
            throw new DatabaseException("{$this->conn->error} [ $sql ]", $this->conn->errno);
        }

        return null;
    }


    public function insertedId()
    {
        return $this->conn->insert_id;
    }


    public function affectedRows(): int
    {
        return $this->conn->affected_rows;
    }


    /**
     * Quote a value for an SQL query.
     *
     *     $db->quote(NULL);   // 'NULL'
     *     $db->quote(10);     // 10
     *     $db->quote('fred'); // 'fred'
     *
     * Objects passed to this function will be converted to strings.
     * [Expression] objects will be compiled.
     * [SelectQuery] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param mixed $value any value to quote
     * @return  string
     * @throws DatabaseException
     * @uses    Database::escape
     */
    public function quote($value): string
    {
        if (\is_null($value)) {
            return 'NULL';
        } elseif (\is_int($value)) {
            return (string) $value;
        } elseif ($value === true) {
            return "'1'";
        } elseif ($value === false) {
            return "'0'";
        } elseif (\is_float($value)) {
            // Convert to non-locale aware float to prevent possible commas
            return \sprintf('%F', $value);
        } elseif (\is_array($value)) {
            return '(' . \implode(', ', \array_map([$this, __FUNCTION__], $value)) . ')';
        } elseif (\is_object($value)) {
            if ($value instanceof SelectQuery) {
                // Create a sub-query
                return '(' . $value->compile($this) . ')';
            }

            if ($value instanceof Expression) {
                // Compile the expression
                return $value->compile($this);
            }

            // Convert the object to a string
            return $this->quote((string) $value);
        }

        return $this->escape($value);
    }

    /**
     * Sanitize a string by escaping characters that could cause an SQL
     * injection attack.
     *
     *     $value = $db->escape('any string');
     *
     * @param string $value value to quote
     * @return  string
     * @throws DatabaseException
     */
    public function escape($value): string
    {
        // Make sure the database is connected
        !\is_null($this->conn) or $this->connect();

        if (($value = $this->conn->real_escape_string((string) $value)) === false) {
            throw new DatabaseException($this->conn->error, $this->conn->errno);
        }

        // SQL standard is to use single-quotes for all values
        return "'$value'";
    }

    /**
     * Start a SQL transaction
     *
     *     // Start the transactions
     *     $db->begin();
     *
     *     try {
     *          DB::insert('users')->values($user1)...
     *          DB::insert('users')->values($user2)...
     *          // Insert successful commit the changes
     *          $db->commit();
     *     }
     *     catch (Database_Exception $e)
     *     {
     *          // Insert failed. Rolling back changes...
     *          $db->rollback();
     *      }
     *
     * @param string $mode transaction mode
     * @return  boolean
     * @throws DatabaseException
     */
    public function begin($mode = null): bool
    {
        // Make sure the database is connected
        $this->conn or $this->connect();

        if ($mode && !$this->conn->query("SET TRANSACTION ISOLATION LEVEL $mode")) {
            throw new DatabaseException($this->conn->error, $this->conn->errno);
        }

        return (bool) $this->conn->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction
     *
     *     // Commit the database changes
     *     $db->commit();
     *
     * @return  boolean
     * @throws DatabaseException
     */
    public function commit(): bool
    {
        // Make sure the database is connected
        $this->conn or $this->connect();

        return (bool) $this->conn->query('COMMIT');
    }

    /**
     * Abort the current transaction
     *
     *     // Undo the changes
     *     $db->rollback();
     *
     * @return  boolean
     * @throws DatabaseException
     */
    public function rollback(): bool
    {
        // Make sure the database is connected
        $this->conn or $this->connect();

        return (bool) $this->conn->query('ROLLBACK');
    }


    public function getLock($name, $timeout = 0): bool
    {
        return (bool) $this->query(
            static::SELECT,
            \strtr('SELECT GET_LOCK(:name, :timeout)', [
                ':name' => $this->quote($name),
                ':timeout' => (int) $timeout,
            ])
        )->scalar();
    }


    public function releaseLock($name): bool
    {
        return (bool) $this->query(
            static::SELECT,
            \strtr('SELECT RELEASE_LOCK(:name)', [
                ':name' => $this->quote($name),
            ])
        )->scalar();
    }


    /**
     * Quote a database column name and add the table prefix if needed.
     *
     *     $column = $db->quote_column($column);
     *
     * You can also use SQL methods within identifiers.
     *
     *     $column = $db->quote_column(DB::expr('COUNT(`column`)'));
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param mixed $column column name or array(column, alias)
     * @param null  $table
     * @return  string
     * @uses    Database::quoteIdentifier
     */
    public function quoteColumn($column, $table = null): string
    {
        if (\is_array($column)) {
            [$column, $alias] = $column;
            $alias = \str_replace('`', '``', $alias);
        }

        if (\is_object($column) && $column instanceof SelectQuery) {
            // Create a sub-query
            $column = '(' . $column->compile($this) . ')';
        } elseif (\is_object($column) && $column instanceof Expression) {
            // Compile the expression
            $column = $column->compile($this);
        } else {
            // Convert to a string
            $column = (string) $column;

            $column = \str_replace('`', '``', $column);

            if ($column === '*') {
                return $table ? "$table.$column" : $column;
            }

            if (\str_contains($column, '.')) {
                $parts = \explode('.', $column);

                foreach ($parts as &$part) {
                    if ($part !== '*') {
                        // Quote each of the parts
                        $part = "`$part`";
                    }
                }

                $column = \implode('.', $parts);
            } else {
                $column = $table === null
                    ? "`$column`"
                    : "$table.`$column`";
            }
        }

        if (isset($alias)) {
            $column .= " AS `$alias`";
        }

        return $column;
    }


    /**
     * Quote a database table name and adds the table prefix if needed.
     *
     *     $table = $db->quote_table($table);
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param mixed $table table name or array(table, alias)
     * @return  string
     * @uses    Database::quoteIdentifier
     */
    public function quoteTable($table): string
    {
        if (\is_array($table)) {
            [$table, $alias] = $table;
            $alias = \str_replace('`', '``', $alias);
        }

        if ($table instanceof SelectQuery) {
            // Create a sub-query
            $table = '(' . $table->compile($this) . ')';
        } elseif ($table instanceof Expression) {
            // Compile the expression
            $table = $table->compile($this);
        } else {
            // Convert to a string
            $table = (string) $table;

            $table = \str_replace('`', '``', $table);

            if (\str_contains($table, '.')) {
                $parts = \explode('.', $table);

                foreach ($parts as &$part) {
                    // Quote each of the parts
                    $part = "`$part`";
                }

                $table = \implode('.', $parts);
            } else {
                // Add the table prefix
                $table = "`$table`";
            }
        }

        if (isset($alias)) {
            $table .= " AS `$alias`";
        }

        return $table;
    }

    /**
     * Quote a database identifier
     *
     * Objects passed to this function will be converted to strings.
     * [Database_Expression] objects will be compiled.
     * [Database_Query] objects will be compiled and converted to a sub-query.
     * All other objects will be converted using the `__toString` method.
     *
     * @param mixed $value any identifier
     * @return  string
     */
    public function quoteIdentifier($value): string
    {
        if (\is_object($value) && $value instanceof SelectQuery) {
            // Create a sub-query
            $value = '(' . $value->compile($this) . ')';
        } elseif ($value instanceof Expression) {
            // Compile the expression
            $value = $value->compile($this);
        } else {
            // Convert to a string
            $value = (string) $value;

            $value = \str_replace('`', '``', $value);

            if (\str_contains($value, '.')) {
                $parts = \explode('.', $value);

                foreach ($parts as &$part) {
                    // Quote each of the parts
                    $part = "`$part`";
                }

                $value = \implode('.', $parts);
            } else {
                $value = "`$value`";
            }
        }

        return $value;
    }
}
