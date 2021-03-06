<?php namespace BladeOrm\Query;


/**
 * @see \BladeOrm\Test\Query\SqlBuilderTest
 */
class SqlBuilder
{
    const WHERE_AND = 'AND';
    const WHERE_OR  = 'OR';

    /**
     * @var callable
     */
    protected static $escapeMethod;

    private $tableName;
    private $fromAlias;
    private $label;
    private $select = [];
    private $join = [];
    private $where = [];
    private $order = [];
    private $groupBy = [];
    private $having;
    private $limit;
    private $offset;
    private $isInsert = false;
    private $isUpdate = false;
    private $isDelete = false;
    private $returnig;
    private $values = [];


    /**
     * Конструктор
     *
     * @param string $label - Название запроса, комментарий для лога
     */
    public function __construct($label = null)
    {
        if (!self::$escapeMethod) {
            throw new \RuntimeException(__CLASS__.": Escape method not set");
        }

        $this->label = $label;
    }


    /**
     * Статическое создание
     *
     * @param string $label - Название запроса, комментарий для лога
     * @return $this
     */
    public static function make($label = null)
    {
        $class = get_called_class();
        return new $class($label);
    }


    /**
     * Установить метод экранирования
     *
     * @param callable $escapeMethod
     */
    public static function setEscapeMethod(callable $escapeMethod)
    {
        self::$escapeMethod = $escapeMethod;
    }


    /**
     * Escape value
     *
     * @param  string $value
     * @return string mixed
     */
    public static function escape($value)
    {
        $method = self::$escapeMethod;
        return $method($value);
    }


    /**
     * LABEL
     *
     * @param string $label
     * @param bool   $onlyIfEmpty - Установить комментарий только, если он не указан ранее
     * @return $this
     */
    public function setLabel($label, $onlyIfEmpty = false)
    {
        if (!$onlyIfEmpty || !$this->label) {
            $this->label = $label;
        }
        return $this;
    }


    /**
     * INSERT
     *
     * @param  string $table
     * @return $this
     */
    public function insert($table = null)
    {
        if ($table) {
            $this->tableName = $table;
        }
        $this->isInsert = true;
        return $this;
    }

    /**
     * INSERT RETURNING
     *
     * @param  string $sqlPart - Any sql part valid for RETURNING
     * @return $this
     */
    public function returning($sqlPart)
    {
        $this->returnig = $sqlPart;
        return $this;
    }

    /**
     * UPDATE
     *
     * @param  string $table
     * @return $this
     */
    public function update($table = null)
    {
        if ($table) {
            $this->tableName = $table;
        }
        $this->isUpdate = true;
        return $this;
    }


    /**
     * DELETE
     *
     * @param  string $table
     * @return $this
     */
    public function delete($table = null)
    {
        if ($table) {
            $this->tableName = $table;
        }
        $this->isDelete = true;
        return $this;
    }


    /**
     * Значения полей для запроса
     *
     * @param array $values
     * @return $this
     */
    public function values(array $values)
    {
        $this->values = $values;
        return $this;
    }

    /**
     * @return array
     */
    public function getValues()
    {
        return $this->values;
    }


    /**
     * FROM
     *
     * @param string $table
     * @param string $alias
     * @return $this
     */
    public function from($table, $alias = null)
    {
        $this->tableName = $table;
        $this->fromAlias = $alias;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFromAlias()
    {
        return $this->fromAlias;
    }

    /**
     * @param mixed $fromAlias
     * @return $this
     */
    public function setFromAlias($fromAlias)
    {
        $this->fromAlias = $fromAlias;
        return $this;
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->tableName;
    }


    /**
     * JOIN
     *
     * @param  string $cond
     * @return $this
     */
    public function addJoin($cond)
    {
        $this->join[] = $cond;
        return $this;
    }


    /**
     * SELECT
     *
     * @param $cols
     * @return $this
     */
    public function select($cols)
    {
        $this->select = [$cols];
        return $this;
    }

    public function addSelect($cols)
    {
        $this->select[] = $cols;
        return $this;
    }

    public function count($fields = '*')
    {
        return $this->select(sprintf('count(%s)', $fields));
    }


    /**
     * Подставить значение колонки с алиасом таблицы
     *
     * @param string|array $column
     * @param string $tableAlias
     * @return string
     */
    public function col($column, $tableAlias = null)
    {
        $cols = (array) $column;

        if (!$tableAlias) {
            $tableAlias = $this->getFromAlias();
        }
        if ($tableAlias) {
            foreach ($cols as $key => $colName) {
                $cols[$key] = $tableAlias . '.' . $colName;
            }
        }
        return implode(', ', $cols);
    }


    /**
     * WHERE
     *
     * @param string $cond
     * @return $this
     */
    public function andWhere($cond)
    {
        return $this->_where(self::WHERE_AND, func_get_args());
    }

    public function orWhere($cond)
    {
        if (!$this->where) {
            throw new \InvalidArgumentException(__METHOD__.": Invalid first OR condition");
        }
        return $this->_where(self::WHERE_OR, func_get_args());
    }

    /**
     * @param string $op - тип операции self::WHERE_*
     * @param array $args
     * @return $this
     */
    private function _where($op, array $args)
    {
        $cond = $args[0];
        if ($cond instanceof SqlBuilder) {
            $cond = sprintf('(%s)', $cond->buildWhere(true));

        } else if (count($args) > 1) {
            $values = $args;
            array_shift($values);
            $values = array_map(self::$escapeMethod, $values);
            $cond = vsprintf($cond, $values);
        }

        if ($this->where) {
            $cond = $op . ' ' . $cond;
        }
        $this->where[] = $cond;
        return $this;
    }


    /**
     * WHERE IN ()
     *
     * @param string $field
     * @param array  $values
     * @return $this
     */
    public function andWhereIn($field, array $values, $equals = true)
    {
        if (!$values) {
            throw new \InvalidArgumentException(__METHOD__.": Expected not emplty list");
        }

        $values = array_map(self::$escapeMethod, $values);
        $this->andWhere(sprintf("%s%s IN ('%s')", $field, $equals?'':' NOT', implode("','", $values)));
        return $this;
    }

    /**
     * WHERE NOT IN
     *
     * @return $this
     */
    public function andWhereNotIn($field, array $values)
    {
        return $this->andWhereIn($field, $values, false);
    }


    /**
     * WHERE deleted_at IS NULL
     *
     * @return $this
     */
    public function andWhereNotDeleted()
    {
        return $this->andWhereDeleted(false);
    }

    /**
     * @param bool $isDeleted
     * @return $this
     */
    public function andWhereDeleted($isDeleted = true)
    {
        $not = $isDeleted ? ' NOT' : null;
        return $this->_where(self::WHERE_AND, [$this->col('deleted_at') . " IS{$not} NULL"]);
    }


    /**
     * ORDER BY
     *
     * @param string $cond
     * @return $this
     */
    public function orderBy($cond)
    {
        if (!$cond) {
            $this->order = [];
        } else {
            $this->order = [$cond];
        }
        return $this;
    }

    public function addOrder($cond)
    {
        $this->order[] = $cond;
        return $this;
    }


    /**
     * GROUP BY
     *
     * @param  string $cond
     * @return $this
     */
    public function groupBy($cond)
    {
        $this->groupBy[] = $cond;
        return $this;
    }


    /**
     * HAVING
     *
     * @param  string $cond
     * @return $this
     */
    public function having($cond)
    {
        $this->having = $cond;
        return $this;
    }


    /**
     * LIMIT
     *
     * @param int $limit
     * @return $this
     */
    public function limit($limit, $offset = null)
    {
        $this->limit = (int)$limit;
        if ($offset) {
            $this->offset = (int) $offset;
        }
        return $this;
    }


    /**
     * SQL
     *
     * @return string
     */
    public function __toString()
    {
        if ($this->isInsert) {
            return $this->_to_insert();
        } else if ($this->isUpdate) {
            return $this->_to_update();
        } else if ($this->isDelete) {
            return $this->_to_delete();
        } else {
            return $this->_to_select();
        }
    }


    /**
     * @return string - SQL SELECT
     */
    private function _to_select()
    {
        $label = null;
        if ($this->label) {
            $label = sprintf("/*%s*/\n", $this->label);
        }

        $select = '*';
        if ($this->select) {
            $select = implode(', ', $this->select);
        }
        $select = 'SELECT ' . $select;

        $from = PHP_EOL . 'FROM ' . $this->buildFrom();

        $order = null;
        if ($this->order) {
            $order = "\nORDER BY " . implode(', ', $this->order);
        }

        $groupBy = null;
        if ($this->groupBy) {
            $groupBy = "\nGROUP BY " . implode(', ', $this->groupBy);
        }

        $having = null;
        if ($this->having) {
            $having = "\nHAVING " . $this->having;
        }

        $limit = null;
        if ($this->limit) {
            $limit = "\nLIMIT " . $this->limit;
        }
        if ($this->offset) {
            $limit .= ' OFFSET ' . $this->offset;
        }

        return $label . $select . $from . $this->buildJoins() . $this->buildWhere() . $groupBy . $having . $order . $limit;
    }


    /**
     * @return string - SQL INSERT
     */
    private function _to_insert()
    {
        $values = [];
        foreach ($this->values as $key => $val) {
            $values[$key] = $this->_value($val);
        }

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->getTableName(), implode(', ', array_keys($values)), implode(', ', $values));

        if ($this->returnig) {
            $sql .= ' RETURNING ' . $this->returnig;
        }
        return $sql;
    }


    /**
     * @return string - SQL UPDATE
     */
    private function _to_update()
    {
        $values = [];
        foreach ($this->values as $key => $val) {
            $val = $this->_value($val);
            $values[] = sprintf('%s=%s', $key, $val);
        }

        $from = $this->getTableName();
        if ($this->getFromAlias()) {
            $from .= ' AS ' . $this->getFromAlias();
        }

        $sql = sprintf('UPDATE %s SET %s'.$this->buildWhere(), $from, implode(', ', $values));

        return $sql;
    }


    /**
     * @return string - SQL DELETE
     */
    private function _to_delete()
    {
        return sprintf('DELETE FROM %s'.$this->buildWhere(), $this->buildFrom());
    }

    public function buildFrom()
    {
        $from = $this->getTableName();
        if ($this->getFromAlias()) {
            $from .= ' AS ' . $this->getFromAlias();
            return $from;
        }

        return $from;
    }

    public function buildWhere($raw = false)
    {
        $where = null;
        if ($this->where) {
            $where = implode(' ', $this->where);
            if (!$raw) {
                $where = "\nWHERE " . $where;
            }
        }

        return $where;
    }

    public function buildJoins()
    {
        $join = null;
        if ($this->join) {
            $join = PHP_EOL . implode(PHP_EOL, $this->join);
        }
        return $join;
    }


    /**
     * @param $val
     * @return string
     */
    private function _value($val)
    {
        if (null === $val) {
            $val = 'NULL';
        } else if (is_int($val) || is_float($val) || $val instanceof SqlFunc) {
            // none
        } else if (is_bool($val)) {
            $val = (int)$val;
        } else {
            $val = sprintf("'%s'", self::escape($val));
        }
        return $val;
    }


    /**
     * Clone
     *
     * @return $this
     */
    public function copy()
    {
        return clone $this;
    }

}
