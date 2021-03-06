<?php namespace BladeOrm;

/**
 * Запрос для работы с Таблицей
 *   - наследники сохраняют конкретные выборки для работы с базой
 */
class Query extends \BladeOrm\Query\SqlBuilder
{
    /**
     * @var Table
     */
    private $table;

    /**
     * @var Table
     */
    private $finder;

    /**
     * @var int
     */
    private $cacheMinutes;

    /**
     * Поля разрешенные для фильтрации
     *
     * @var array
     */
    protected $filtersAllowed = [];

    /**
     * @return Table - Таблица
     */
    public function getTable()
    {
        if (!$this->table) {
            throw new \RuntimeException(__METHOD__.": no table");
        }
        return $this->table;
    }

    /**
     * @param Table $table - Таблица
     * @return $this
     */
    public function setTable(Table $table)
    {
        $this->table = $table;
        if (!$this->finder) {
            $this->finder = $table;
        }
        return $this;
    }

    /**
     * @param mixed $finder
     */
    public function setFinder($finder)
    {
        $this->finder = $finder;
    }

    /**
     * @param $minutes
     * @return $this
     */
    public function withCache($minutes)
    {
        $this->cacheMinutes = (int)$minutes;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCacheMinutes()
    {
        return $this->cacheMinutes;
    }


    // Фильтры
    // ------------------------------------------------------------------------

    /**
     * findOneByPk
     *
     * @param  int $id
     * @return $this
     */
    public function findOneByPk($id)
    {
        $pk = $this->getTable()->getPrimaryKey();
        // Если в базе PK INT, а мы передаем текст, получим SQL-ошибку, надо привести к типу, который заявлен в таблице
        $id = $this->getTable()->mapToDb([$pk => $id])[$pk];

        return $this
            ->setLabel(get_class($this).'::'.__FUNCTION__, true)
            ->andWhere($this->col($pk)."='%s'", $id);
    }


    /**
     * findListByPk
     *
     * @param int[] $ids
     * @return $this
     */
    public function findListByPk(array $ids)
    {
        if (!$ids) {
            throw new \InvalidArgumentException(__METHOD__.": Expected not empty list");
        }
        return $this
            ->setLabel(get_class($this).'::'.__FUNCTION__, true)
            ->andWhereIn($this->col($this->getTable()->getPrimaryKey()), $ids);
    }


    /**
     * Добавить простые фильтры по полям основной таблицы
     *
     * @param array $filters
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function filterBy(array $filters)
    {
        $filtersKeys = array_keys($filters);
        if ($this->filtersAllowed) {
            $filtersDisalowedKeys = array_diff($filtersKeys, $this->filtersAllowed);
            if ($filtersDisalowedKeys) {
                throw new \InvalidArgumentException('Переданы неразрешенные фильтры: ' . implode(' ,', $filtersDisalowedKeys));
            }
            $filtersKeys = array_intersect($filtersKeys, $this->filtersAllowed);
        }
        foreach ($filtersKeys as $key) {
            $this->andWhere($this->col($key) . "='%s'", $filters[$key]);
        }
        return $this;
    }


    // Выборка из таблицы
    // ------------------------------------------------------------------------

    /**
     * Получить список объектов у Таблицы
     *
     * @return Model[]
     */
    public function fetchList($indexBy = null)
    {
        return $this->finder->findList($this, $indexBy);
    }

    /**
     * Получить один объект у Таблицы
     *
     * @return false|Model
     */
    public function fetchOne($exception = false)
    {
        return $this->finder->findOne($this, $exception);
    }

}
