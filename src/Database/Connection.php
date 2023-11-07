<?php

namespace Uepg\LaravelSybase\Database;

use Closure;
use Doctrine\DBAL\Driver\PDOSqlsrv\Driver as DoctrineDriver;
use Exception;
use Illuminate\Database\Connection as IlluminateConnection;
use Illuminate\Database\Query\Builder;
use PDO;
use Uepg\LaravelSybase\Database\Query\Grammar as QueryGrammar;
use Uepg\LaravelSybase\Database\Query\Processor;
use Uepg\LaravelSybase\Database\Schema\Blueprint;
use Uepg\LaravelSybase\Database\Schema\Grammar as SchemaGrammar;

class Connection extends IlluminateConnection
{
    /**
     * All types without quotes in Sybase's query.
     *
     * @var array
     */
    private $withoutQuotes = [
        'int',
        'numeric',
        'bigint',
        'integer',
        'smallint',
        'tinyint',
        'decimal',
        'double',
        'float',
        'real',
        'bit',
        'binary',
        'varbinary',
        'timestamp',
        'money',
    ];

    /**
     * Execute a Closure within a transaction.
     *
     * @param  \Closure  $callback
     * @return mixed
     *
     * @throws \Exception
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        if ($this->getDriverName() === 'sqlsrv') {
            return parent::transaction($callback);
        }

        $this->pdo->exec('BEGIN TRAN');

        // We'll simply execute the given callback within a try / catch block
        // and if we catch any exception we can rollback the transaction
        // so that none of the changes are persisted to the database.
        try {
            $result = $callback($this);

            $this->pdo->exec('COMMIT TRAN');
        }

            // If we catch an exception, we will roll back so nothing gets messed
            // up in the database. Then we'll re-throw the exception so it can
            // be handled how the developer sees fit for their applications.
        catch (Exception $e) {
            $this->pdo->exec('ROLLBACK TRAN');

            throw $e;
        }

        return $result;
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Uepg\LaravelSybase\Database\Query\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Uepg\LaravelSybase\Database\Schema\Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Uepg\LaravelSybase\Database\Query\Processor
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }

    /**
     * Get the Doctrine DBAL Driver.
     *
     * @return \Doctrine\DBAL\Driver\PDOSqlsrv\Driver
     */
    protected function getDoctrineDriver()
    {
        return new DoctrineDriver;
    }

    /**
     * Compile the bindings for select/insert/update/delete.
     *
     * @param  \Illuminate\Database\Query\Builder  $builder
     * @return array
     */
    private function compile(Builder $builder)
    {
        $arrTables = [];

        array_push($arrTables, $builder->from);
        if (! empty($builder->joins)) {
            foreach ($builder->joins as $join) {
                array_push($arrTables, $join->table);
            }
        }

        $wheres = [];

        foreach ($builder->wheres as $w) {
            switch ($w['type']) {
                case 'Nested':
                    $wheres += $w['query']->wheres;
                    break;
                default:
                    array_push($wheres, $w);
                    break;
            }
        }

        foreach ($arrTables as $tables) {
            preg_match (
                "/(?:(?'table'.*)(?: as )(?'alias'.*))|(?'tables'.*)/",
                strtolower($tables),
                $alias
            );

            if (empty($alias['alias'])) {
                $tables = $alias['tables'];
            } else {
                $tables = $alias['table'];
            }


            $queryString = $this->queryString($tables);
            $queryRes = $this->getPdo()->query($queryString);

            $aux = $queryRes->fetchAll(PDO::FETCH_NAMED);
            foreach ($aux as &$row) {
                $types[strtolower($row['name'])] = $row['type'];
                $types[strtolower($tables.'.'.$row['name'])] = $row['type'];

                if (! empty($alias['alias'])) {
                    $types[
                    strtolower($alias['alias'].'.'.$row['name'])
                    ] = $row['type'];
                }
            }
        }

        $convert = function($column, $v) use($types) {
            if (is_null($v)) return null;

            $variable_type = $types[strtolower($column)];

            if (in_array($variable_type, $this->withoutQuotes)) {
                return $v / 1;
            } else {
                return (string) $v;
            }
        };

        $keys = [];

        if (isset($builder->values)) {
            foreach ($builder->values as $value) {
                foreach ($value as $k => $v) {
                    $keys[] = $convert($k, $v);
                }
            }
        }

        if (isset($builder->set)) {
            foreach ($builder->set as $k => $v) {
                $keys[] = $convert($k, $v);
            }
        }

        foreach ($wheres as $w) {
            if ($w['type'] == 'Basic') {
                $keys[] = $convert($w['column'], $w['value']);
            } elseif ($w['type'] == 'In' || $w['type'] == 'NotIn') {
                foreach ($w['values'] as $v) {
                    $keys[] = $convert($w['column'], $v);
                }
            }
        }

        return $keys;
    }

    /**
     * Query string.
     *
     * @param  string  $tables
     * @return string
     */
    private function queryString($tables)
    {
        $explicitDB = explode('..', $tables);

        if (isset($explicitDB[1])) {
            return "
                SELECT
                    a.name,
                    b.name AS customtype,
                    st.name AS type
                FROM
                    {$explicitDB[0]}..syscolumns a,
                    {$explicitDB[0]}..systypes b,
                    {$explicitDB[0]}..systypes s,
                    {$explicitDB[0]}..systypes st
                WHERE
                    a.usertype = b.usertype AND
                    s.usertype = a.usertype AND
                    s.type = st.type AND
                    st.name NOT IN (
                        'timestamp',
                        'sysname',
                        'longsysname',
                        'nchar',
                        'nvarchar'
                    ) AND
                    st.usertype < 100 AND
                    object_name (
                        a.id,
                        db_id ('{$explicitDB[0]}')
                    ) = '{$explicitDB[1]}'";
        } else {
            return "
                SELECT
                    a.name,
                    st.name AS type
                FROM
                    syscolumns a,
                    systypes b,
                    systypes s,
                    systypes st
                WHERE
                    a.usertype = b.usertype AND
                    s.usertype = a.usertype AND
                    s.type = st.type AND
                    st.name NOT IN (
                        'timestamp',
                        'sysname',
                        'longsysname',
                        'nchar',
                        'nvarchar'
                    ) AND
                    st.usertype < 100 AND
                    object_name (a.id) = '{$tables}'";
        }
    }

    /**
     * Set new bindings with specified column types to Sybase.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed  $newBinds
     */
    private function compileBindings($query, $bindings)
    {
        if (count($bindings) == 0) {
            return [];
        }

        $bindings = $this->prepareBindings($bindings);
        $builder = $this->queryGrammar->getBuilder();

        if ($builder != null) {
            return $this->compile($builder);
        } else {
            return $bindings;
        }
    }

    /**
     * Query string for compile bindings.
     *
     * @param  string  $table
     * @return string
     */
    private function queryStringForCompileBindings($table)
    {
        $explicitDB = explode('..', $table);

        if (isset($explicitDB[1])) {
            return "
                SELECT
                    a.name,
                    b.name AS customtype,
                    st.name AS type
                FROM
                    {$explicitDB[0]}..syscolumns a,
                    {$explicitDB[0]}..systypes b,
                    {$explicitDB[0]}..systypes s,
                    {$explicitDB[0]}..systypes st
                WHERE
                    a.usertype = b.usertype AND
                    s.usertype = a.usertype AND
                    s.type = st.type AND
                    st.name NOT IN (
                        'timestamp',
                        'sysname',
                        'longsysname',
                        'nchar',
                        'nvarchar'
                    ) AND
                    st.usertype < 100 AND
                    object_name (
                        a.id,
                        db_id ('{$explicitDB[0]}')
                    ) = '{$explicitDB[1]}'";
        } else {
            return "
                SELECT
                    a.name,
                    st.name AS type
                FROM
                    syscolumns a,
                    systypes b,
                    systypes s,
                    systypes st
                WHERE
                    a.usertype = b.usertype AND
                    s.usertype = a.usertype AND
                    s.type = st.type AND
                    st.name NOT IN (
                        'timestamp',
                        'sysname',
                        'longsysname',
                        'nchar',
                        'nvarchar'
                    ) AND
                    st.usertype < 100 AND
                    object_name(a.id) = '{$table}'";
        }
    }

    /**
     * Set new bindings with specified column types to Sybase.
     *
     * It could compile again from bindings using PDO::PARAM, however, it has
     * no constants that deal with decimals, so the only way would be to put
     * PDO::PARAM_STR, which would put quotes.
     *
     * @link http://stackoverflow.com/questions/2718628/pdoparam-for-type-decimal
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return string $query
     */
    private function compileNewQuery($query, $bindings)
    {
        $newQuery = '';

        $bindings = $this->compileBindings($query, $bindings);

        $partQuery = explode('?', $query);

        for ($i = 0; $i < count($partQuery); $i++) {
            $newQuery .= $partQuery[$i];

            if ($i < count($bindings)) {
                if (is_string($bindings[$i])) {
                    $bindings[$i] = str_replace("'", "''", $bindings[$i]);

                    $newQuery .= "'".$bindings[$i]."'";
                } else {
                    if (! is_null($bindings[$i])) {
                        $newQuery .= $bindings[$i];
                    } else {
                        $newQuery .= 'null';
                    }
                }
            }
        }

        $newQuery = str_replace('[]', '', $newQuery);

        return $newQuery;
    }

    /**
     * Compile offset.
     *
     * @param  int  $offset
     * @param  string  $query
     * @param  array  $bindings
     * @param  \Uepg\LaravelSybase\Database\Connection  $me
     * @return string
     */
    public function compileOffset($offset, $query, $bindings = [], $me)
    {
        $limit = $this->queryGrammar->getBuilder()->limit;

        $from = explode(' ', $this->queryGrammar->getBuilder()->from)[0];

        if (! isset($limit)) {
            $limit = 999999999999999999999999999;
        }

        $queryString = $this->queryStringForIdentity($from);

        $identity = $this->getPdo()->query($queryString)->fetchAll(
            $me->getFetchMode()
        )[0];

        if (count((array) $identity) === 0) {
            $queryString = $this->queryStringForPrimaries($from);

            $primaries = $this->getPdo()->query($queryString)->fetchAll(
                $me->getFetchMode()
            );

            foreach ($primaries as $primary) {
                $newArr[] = $primary->primary_key.'+0 AS '.
                    $primary->primary_key;

                $whereArr[] = '#tmpPaginate.'.$primary->primary_key.
                    ' = #tmpTable.'.$primary->primary_key;
            }

            $resPrimaries = implode(', ', $newArr);

            $wherePrimaries = implode(' AND ', $whereArr);
        } else {
            $resPrimaries = $identity->column.'+0 AS '.$identity->column;

            $wherePrimaries = '#tmpPaginate.'.$identity->column.
                ' = #tmpTable.'.$identity->column;

            // Offset operation
            $this->getPdo()->query(str_replace(
                ' from ',
                ' into #tmpPaginate from ',
                $this->compileNewQuery($query, $bindings)
            ));

            $this->getPdo()->query('
                SELECT
                    '.$resPrimaries.',
                    idTmp=identity(18)
                INTO
                    #tmpTable
                FROM
                    #tmpPaginate');

            return $this->getPdo()->query('
                SELECT
                    #tmpPaginate.*,
                    #tmpTable.idTmp
                FROM
                    #tmpTable
                INNER JOIN
                    #tmpPaginate
                ON
                    '.$wherePrimaries.'
                WHERE
                    #tmpTable.idTmp BETWEEN '.($offset + 1).' AND
                    '.($offset + $limit).'
                ORDER BY
                    #tmpTable.idTmp ASC')->fetchAll($me->getFetchMode());
        }
    }

    /**
     * Query string for identity.
     *
     * @param  string  $from
     * @return string
     */
    private function queryStringForIdentity($from)
    {
        $explicitDB = explode('..', $from);

        if (isset($explicitDB[1])) {
            return "
                SELECT
                    b.name AS 'column'
                FROM
                    ".$explicitDB[0].'..syscolumns AS b
                INNER JOIN
                    '.$explicitDB[0]."..sysobjects AS a
                ON
                    a.id = b.id
                WHERE
                    status & 128 = 128 AND
                    a.name = '".$explicitDB[1]."'";
        } else {
            return "
                SELECT
                    name AS 'column'
                FROM
                    syscolumns
                WHERE
                    status & 128 = 128 AND
                    object_name (id) = '".$from."'";
        }
    }

    /**
     * Query string for primaries.
     *
     * @param  string  $from
     * @return string
     */
    private function queryStringForPrimaries($from)
    {
        $explicitDB = explode('..', $from);

        if (isset($explicitDB[1])) {
            return '
                SELECT
                    index_col (
                        '.$from.',
                        i.indid,
                        c.colid
                    ) AS primary_key
                FROM
                    '.$explicitDB[0].'..sysindexes i,
                    '.$explicitDB[0]."..syscolumns c
                WHERE
                    i.id = c.id AND
                    c.colid <= i.keycnt AND
                    i.id = object_id ('".$from."')";
        } else {
            return '
                SELECT
                    index_col (
                        '.$from.",
                        i.indid,
                        c.colid
                    ) AS primary_key
                FROM
                    sysindexes i,
                    syscolumns c
                WHERE
                    i.id = c.id AND
                    c.colid <= i.keycnt AND
                    i.id = object_id ('".$from."')";
        }
    }

    /**
     * Run a select statement against the database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function (
            $query,
            $bindings
        ) {
            if ($this->pretending()) {
                return [];
            }

            if ($this->queryGrammar->getBuilder() != null) {
                $offset = $this->queryGrammar->getBuilder()->offset;
            } else {
                $offset = 0;
            }

            if ($offset > 0) {
                return $this->compileOffset($offset, $query, $bindings, $this);
            } else {
                $result = [];

                $statement = $this->getPdo()->query($this->compileNewQuery(
                    $query,
                    $bindings
                ));

                do {
                    $result += $statement->fetchAll($this->getFetchMode());
                } while ($statement->nextRowset());

                return $result;
            }
        });
    }

    /**
     * Get the statement.
     *
     * @param  string  $query
     * @param  mixed|array   $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            return $this->getPdo()->query($this->compileNewQuery(
                $query,
                $bindings
            ));
        });
    }

    /**
     * Affecting statement.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            return $this->getPdo()->query($this->compileNewQuery(
                $query,
                $bindings
            ))->rowCount();
        });
    }

    /**
     * Get the default fetch mode for the connection.
     *
     * @return int
     */
    public function getFetchMode()
    {
        return $this->fetchMode;
    }

    /**
     * Get SchemaBuilder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        $builder = new Builder($this);

        $builder->blueprintResolver(function ($table, $callback) {
            return new Blueprint($table, $callback);
        });

        return $builder;
    }
}
