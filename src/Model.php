<?php namespace BladeOrm;


/**
 * @see \BladeOrm\Test\Model\CreateTest
 * @see \BladeOrm\Test\Model\ForceSettersGettersTest
 * @see \BladeOrm\Test\Model\SetGetTest
 * @see \BladeOrm\Test\Model\IsModifiedTest
 * @see \BladeOrm\Test\Model\TransformTest
 * @see \BladeOrm\Test\Model\OthersTest
 */
class Model
{
    /**
     * @var array - Значения из БД
     */
    private $values = [];

    /**
     * @var array - Значения, которые были изменены
     */
    private $modifiedValuesNew = [];

    /**
     * @var array - Старые значения, которые были изменены
     */
    private $modifiedValuesOld = [];

    /**
     * @var array - Строковые копии вложенных объектов для сравнения изменений
     */
    private $objectSnapshots = [];

    /**
     * @var bool - Новый, нет в базе
     */
    private $isNew = true;


    // Настраиваемые свойства
    // ------------------------------------------------------------------------

    /**
     * @var array - Значения по умолчанию для создания нового объекта
     */
    protected $defaults = [];

    /**
     * @var bool - Разрешить "магический" доступ к геттерам
     */
    protected $allowGetterMagic = false;

    /**
     * Геттеры назначенные на указанные поля. Кидает исключение, если пытается получить свойство мимо геттера
     * Назначение: заставить явно использовать геттер (для важных полей или там где возможна путаница с названиями)
     * @see BaseModel::get()
     *
     * @var array - KEY => METHOD
     */
    protected $forceGetters = [];

    /**
     * Сеттеры назначенные на указанные поля.
     * Вызваются в set() и update()
     * @see BaseModel::_set_resolved()
     *
     * @var array - KEY => METHOD
     */
    protected $forceSetters = [];

    /**
     * Настройка преобразования входящих данных. Приведение к типу и другие проверки.
     * Назначение - для приведения типа и подготовки входящих данных из формы.
     * Можно указывать несколько обработчиков или callable.
     *
     * @var array
     *   'field' => 'int',
     *   'field' => 'float',
     *   'field' => 'trim',
     *   'field' => ['trim', 'lower'],
     *   'field' => ['ThisModelClass', '_set_col_callable'],
     *   'field' => ['trim', ['ThisModelClass', '_set_col_callable']],
     *   'field' => [\DateTime::class], // Может принимать значения только указанного класса
     *
     * все типы, см в  @see _set_transform()
     */
    protected $transformers = [];

    /**
     * callable-триггеры, которые вызываются при изменении какого-то поля
     * Назначение - изменить зависимые поля объекта при изменении выбранного поля.
     * Вызывается ДО сохранения. Это только подготовка объекта к сохранению.
     *
     * @deprecated
     *
     * @var array
     *   'field' => callable ($object, $key, $newValue, $oldValue)
     * @see Model::set
     */
    protected $updateTriggers = [];


    /**
     * Конструктор
     * 1. Принимает массив "подготовленных" свойств объекта - это значит, что все свойства прошли обработку
     *    после выборки из БД и имеют необходимый тип
     * 2. Трансформирует значения по правилам BaseModel::$transformers
     * 3. Помечает свойства как измененные, если они изменились после "трансформации"
     * 4. Если объект Новый, тогда подставляет дефолтные значения
     *
     * @param array $values - Значения из БД
     * @param bool  $isNew  - Новый, нет в базе
     */
    public function __construct(array $values = [], $isNew = true)
    {
        $this->isNew((bool)$isNew);
        if ($this->isNew() && $this->defaults) {
            $values = array_merge($this->defaults, $values);
        }
        $this->values = $values;
        foreach ($values as $key => $value) {
            // В конструктор не может прийти объект из БД,
            // а это выражение нужно только для того, чтобы "трансформировать" данные,
            // которые пришли из БД и пометить их isDirty
            if (is_object($value)) {
                $this->_set_transform($key, $value);
            } else {
                $this->_set_update($key, $value);
            }
        }
    }


    // GET
    // ------------------------------------------------------------------------

    /**
     * GET +исключение, если Ключ НЕ найден
     * Использовать в локальных геттерах
     *
     * @param  string $field
     * @return mixed
     */
    protected function _get_value($field)
    {
        if (!$this->has($field)) {
            // Если Новый, тогда не кидаем исключение и позволяем обращаться к любым полям
            if ($this->isNew()) {
                return null;
            }
            throw new \InvalidArgumentException($this->_error_mess("Field `{$field}` not found"));
        }

        return $this->values[$field];
    }

    /**
     * GET +использует геттер, если есть
     * Для внутреннего использования, чтобы не словить исключение при forceGetters
     *
     * @param  string $field
     * @return mixed
     */
    private function _get_resolved($field)
    {
        if ($this->forceGetters && !empty($this->forceGetters[$field])) {
            $method = $this->forceGetters[$field];

            return $this->$method();
        }

        return $this->_get_value($field);
    }

    /**
     * GET
     *
     * @param  string $field
     * @return mixed
     */
    public function get($field)
    {
        // Исключение, если есть геттер
        if ($this->forceGetters && !empty($this->forceGetters[$field])) {
            throw new \InvalidArgumentException($this->_error_mess("Forbidden! Use getter `{$this->forceGetters[$field]}`"));
        }

        return $this->_get_value($field);
    }

    /**
     * Магия - для обратной совместимости
     * +используется в Collection
     *
     * @param  string $field
     * @return mixed
     */
    public function __get($field)
    {
        // Исключение, если запрещена магия
        if (!$this->allowGetterMagic) {
            throw new \InvalidArgumentException($this->_error_mess("Magic is not allowed!"));
        }

        return $this->get($field);
    }

    /**
     * Вернуть массив всех значений
     *
     * @return array
     */
    public function toArray()
    {
        $result = $this->values;

        // Проверить геттеры
        if ($this->forceGetters) {
            foreach ($this->forceGetters as $key => $method) {
                if ($this->has($key)) {
                    $result[$key] = $this->_get_resolved($key);
                }
            }
        }

        return $result;
    }


    // SET
    // ------------------------------------------------------------------------

    /**
     * SET + проверка наличия сеттера
     *
     * @param string $field
     * @param mixed $value
     *
     * @return bool - Изменилось ли значение
     */
    public function set($field, $value)
    {
        return $this->_set_resolved($field, $value);
    }

    /**
     * SET use Setter
     *
     * @param string $field
     * @param mixed $value
     *
     * @return bool - Изменилось ли значение
     */
    private function _set_resolved($field, $value)
    {
        if ($this->forceSetters && array_key_exists($field, $this->forceSetters)) {
            $method = $this->forceSetters[$field];
            if (!$method) {
                throw new \RuntimeException($this->_error_mess("set('{$field}') is forbidden"));
            }
            $this->$method($value);
            return array_key_exists($field, $this->modifiedValuesNew);
        } else {
            return $this->_set_update($field, $value);
        }
    }

    /**
     * SET +isModified
     * Отмечает "измененные", дергает "modify_triggers"
     * Должен использоваться в кастомных сеттерах
     *
     * @param string $field
     * @param mixed  $value
     *
     * @return bool - Изменилось ли значение
     */
    protected function _set_update($field, $value)
    {
        // Проверка - реально ли были изменены значения
        if (array_key_exists($field, $this->modifiedValuesOld)) {
            // Если значение менялось многократно, сравниваем только самым первым, которое пришло из БД
            $oldValue = $this->modifiedValuesOld[$field];
        } else {
            // Ищем строковое значение объекта, чтобы сравнивать с ним, а не с самим объектом
            if (isset($this->objectSnapshots[$field])) {
                $oldValue = $this->objectSnapshots[$field];
            } else {
                $oldValue = $this->_get_value($field); // Надо сравнить данные из БД, а не то, что getter нафигачит
            }
        }
        $newValue = $this->_set_transform($field, $value);

        // Сравниваем как строки - потому что в базу все пишется как строки
        // Объекты приводятся к строкам, oldValue уже приведен к строке выше
        $strOldValue = $this->_snapshot_value($oldValue);
        $strNewValue = $this->_snapshot_value($newValue);

        // NULL и пустая строка - разные значения
        if (((null === $newValue || null === $oldValue) && $oldValue !== $newValue) || $strOldValue !== $strNewValue) {
            $this->modifiedValuesOld[$field] = $oldValue;
            $this->modifiedValuesNew[$field] = $newValue;

            if ( !empty($this->updateTriggers[$field])) {
                $this->updateTriggers[$field]($this, $field, $newValue, $oldValue);
            }
            return true;

        } else {
            // Если значение в итоге не поменялось после множественных правок
            if (array_key_exists($field, $this->modifiedValuesOld)) {
                unset($this->modifiedValuesOld[$field], $this->modifiedValuesNew[$field]);
            }
            return false;
        }
    }

    /**
     * Привести значение к строке для проверки isModified
     *
     * @param  mixed $value
     * @return string
     */
    private function _snapshot_value($value)
    {
        if (is_array($value)) {
            $result = var_export($value, true);
        } else {
            $result = (string)$value;
        }

        return $result;
    }

    /**
     * SET attribute value
     * Преобразует значения - set_mutators
     *
     * @param string $field
     * @param mixed $value
     * @return mixed - Новое значение после преобразования
     */
    private function _set_transform($field, $value)
    {
        // Трансформер игнорирует NULL
        // а таблица должна по типу IS NULL либо принять NULL, либо смапить в указанный тип
        if (null !== $value && !empty($this->transformers[$field])) {
            $types = (array)$this->transformers[$field];
            if (is_callable($types)) {
                $types = [$types];
            }
            foreach ($types as $type) {
                switch ($type) {

                    case 'trim':
                        $value = trim($value);
                        break;

                    case 'lower':
                        $value = mb_strtolower($value, 'UTF-8');
                        break;

                    case 'upper':
                        $value = mb_strtoupper($value, 'UTF-8');
                        break;

                    case 'ucfirst':
                        $firstChar = mb_substr($value, 0, 1, 'UTF-8');
                        $then      = mb_substr($value, 1, mb_strlen($value, 'UTF-8') - 1, 'UTF-8');
                        $value     = mb_strtoupper($firstChar, 'UTF-8') . mb_strtolower($then, 'UTF-8');
                        break;

                    case 'int':
                        $value = (int)$value;
                        break;

                    case 'float':
                        $value = (float)str_replace(',', '.', $value);
                        break;

                    case 'datetime':
                        if (!$value instanceof \DateTime) {
                            throw new \InvalidArgumentException($this->_error_mess("Expected DateTime for field `{$field}`"));
                        }
                        break;

                    case 'db_date':
                        if ($value instanceof \DateTime) {
                            $value = $value->format('Y-m-d');
                        }
                        break;

                    case 'bool':
                        $value = (bool)$value;
                        break;

                    case 'array':
                        if ( !is_array($value)) {
                            throw new \InvalidArgumentException($this->_error_mess("Expected array for `{$field}``"));
                        }
                        break;

                    case is_callable($type):
                        $value = $type($value);
                        break;

                    case class_exists($type):
                        if (!$value instanceof $type) {
                            throw new \InvalidArgumentException($this->_error_mess(": Expected {$type} for field `{$field}`"));
                        }
                        break;

                    default:
                        throw new \InvalidArgumentException($this->_error_mess("Unknown transformer `{$type}`"));
                        break;
                }

            }
        }

        $this->values[$field] = $value;

        // Сохранить строковое значения объекта, чтобы понимать, что изменилось,
        // так как объекты хранятся по ссылке и изменений не видно, поэтому надо сохранить изначальное состояние объекта
        if (is_object($value)) {
            $this->objectSnapshots[$field] = (string)$value;
        }

        return $value;
    }

    /**
     * Магия - запрещено
     * @deprecated
     *
     * @param  string $field
     * @return mixed
     */
    public function __set($field, $value)
    {
        throw new \RuntimeException($this->_error_mess("Deprecated!"));
    }

    /**
     * PUSH attribute value
     * Отдельный метод для публичного использования,
     * чтобы иметь возможность найти все варианты использования
     *
     * Сетит любое поле для без проверок на его существование и не отмечает как измененное.
     * Основное назначение - установить ID записи после инсерта, т.е. внесение правок в НЕ новый объект
     * НЕ использовать в других случаях!
     *
     * @param string $field
     * @param mixed $value
     */
    public function push($field, $value)
    {
        $this->_set_transform($field, $value);
        if (array_key_exists($field, $this->modifiedValuesNew)) {
            unset($this->modifiedValuesNew[$field], $this->modifiedValuesOld[$field]);
        }
    }

    /**
     * Изменить
     *
     * @param array $newValues
     */
    final public function update(array $newValues)
    {
        foreach ($newValues as $key => $value) {
            $this->set($key, $value);
        }
    }


    // HAS
    // ------------------------------------------------------------------------

    /**
     * @param $field
     * @return bool
     */
    public function __isset($field)
    {
        return $this->has($field);
    }

    /**
     * Присутствует ли в объекте указанное поле
     *
     * @param string $field
     * @param bool   $checkEmpty - Если найдено, то проверить, что не пустое
     * @return bool
     */
    public function has($field, $checkEmpty = false)
    {
        $found = array_key_exists($field, $this->values);
        if ($found) {
            if ($checkEmpty) {
                return (bool)$this->_get_value($field);
            }
            return true;
        }
        return false;
    }


    // Getters
    // ------------------------------------------------------------------------

    /**
     * Новый, нет в базе
     *
     * @param  bool $newValue
     * @return bool
     */
    public final function isNew($newValue = null)
    {
        if (null !== $newValue) {
            $result = $this->isNew = (bool) $newValue;

            // Проверить вложенные объекты
            foreach ($this->objectSnapshots as $key => $hash) {
                $obj = $this->_get_value($key);
                if ($obj instanceof Model) {
                    $obj->isNew($result);
                }
            }

            return $result;
        }

        return $this->isNew;
    }


    // Modified
    // ------------------------------------------------------------------------

    /**
     * Было ли изменено указанное поле
     *
     * @param  string $field
     * @return bool
     */
    public function isDirty($field)
    {
        // Проверить вложенные объекты
        if ($this->has($field) && isset($this->objectSnapshots[$field])) {
            $this->_check_snapshots($field);
        }

        return array_key_exists($field, $this->modifiedValuesNew);
    }


    /**
     * Проверить, изменили ли состояние вложенные объекты
     *
     * @param string $searchField
     */
    private function _check_snapshots($searchField = null)
    {
        if ($this->objectSnapshots) {
            if ($searchField && isset($this->objectSnapshots[$searchField])) {
                $fields = [$searchField];
            } else {
                $fields = array_keys($this->objectSnapshots);
            }
            foreach ($fields as $field) {
                $value = $this->_get_value($field);
                // Если вложенный объект изменил свое состояние, сетнуть его
                if ((string)$value !== $this->objectSnapshots[$field]) {
                    $this->_set_resolved($field, $value);
                }
            }
        }
    }


    /**
     * Получить список обновленных значений
     *
     * @param array $filterFields
     * @return array
     */
    public function getValuesUpdated(array $filterFields = [])
    {
        // Проверить вложенные объекты, так как они могут не уведомлять о своих изменениях
        $this->_check_snapshots();

        if ($filterFields) {
            return array_intersect_key($this->modifiedValuesNew, array_flip($filterFields));
        }

        return $this->modifiedValuesNew;
    }


    /**
     * Список предыдущих значений
     *
     * @param array $filterFields
     * @return array
     */
    public function getValuesOld(array $filterFields = [])
    {
        if ($filterFields) {
            return array_intersect_key($this->modifiedValuesOld, array_flip($filterFields));
        }

        return $this->modifiedValuesOld;
    }


    /**
     * Получить оригинальное значение поля
     *
     * @param  string $field
     * @return mixed
     */
    public function getValueOrig($field)
    {
        if (array_key_exists($field, $this->modifiedValuesOld)) {
            return $this->modifiedValuesOld[$field];
        } else {
            return $this->_get_value($field);
        }
    }


    /**
     * Сбросить isModified
     *
     * @param array $affectedValues - Сбросить только указанные значения
     */
    public function resetModified(array $affectedValues = null)
    {
        if (null !== $affectedValues) {
            // Если не пустой массив
            if ($affectedValues) {
                foreach ($affectedValues as $key => $val) {
                    // Очистка вложенных Моделей
                    if ($val instanceof Model) {
                        $resetValues = $affectedValues;
                        unset($resetValues[$key]);
                        $val->resetModified($resetValues);
                        // Не сбрасывать флаг, если сохранена только часть объекта
                        if ($val->getValuesUpdated()) {
                            continue;
                        }
                    }
                    if (array_key_exists($key, $this->modifiedValuesOld)) {
                        unset($this->modifiedValuesOld[$key]);
                    }
                    if (array_key_exists($key, $this->modifiedValuesNew)) {
                        unset($this->modifiedValuesNew[$key]);
                    }
                }
            }
        } else {
            $this->modifiedValuesOld = [];
            $this->modifiedValuesNew = [];
        }

    }


    /**
     * @param $message
     * @return string
     */
    private function _error_mess($message)
    {
        return sprintf('%s::%s: %s', get_class($this), debug_backtrace()[1]['function'], $message);
    }

}
