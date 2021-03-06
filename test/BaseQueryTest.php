<?php namespace BladeOrm\Test;

use BladeOrm\Query;
use BladeOrm\Table;


/**
 * Тестовая таблица
 */
class BaseQueryTestTable extends Table
{
    const TABLE = 'table';
    const ALIAS = 't';
    protected $query = BaseQueryTestQuery::class;
}

class BaseQueryTestQuery extends Query
{
}


/**
 * @see Query
 */
class BaseQueryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var BaseQueryTestTable
     */
    private $table;

    /**
     * @var TestDbAdapter
     */
    private $db;

    /**
     * SetUp
     */
    protected function setUp()
    {
        $this->db = new TestDbAdapter;
        $this->table = new BaseQueryTestTable($this->db);
        Table\CacheRepository::clear();
    }

    /**
     * Init test
     */
    public function testInit()
    {
        $sql = $this->table->sql($label = 'label');
        $this->assertInstanceOf(BaseQueryTestQuery::class, $sql);
        $this->assertEquals($this->table->getTableName(), $sql->getTableName());
        $this->assertSame($this->table, $sql->getTable());
        $this->assertEquals("/*label*/\nSELECT *\nFROM table AS t", (string)$sql);

        $this->assertNotSame($sql, $this->table->sql($label), 'Каждый раз создает новый объект');
    }

    /**
     * FindOneByPk
     */
    public function testFindOneByPk()
    {
        $sql = $this->table->sql()->findOneByPk($id=55);
        $q = "SELECT *\nFROM table AS t\nWHERE t.id='{$id}'";
        $this->assertEquals("/*".get_class($sql)."::findOneByPk*/\n".$q, (string)$sql);
        $this->table->findOneByPk($id, false);

        $label = get_class($this->table) . '::findOneByPk';
        $this->assertEquals("/*{$label}*/\n".$q."\nLIMIT 1", (string)$this->db->lastQuery);
    }


    /**
     * FindListByPk
     */
    public function testFindListByPk()
    {
        $this->db->returnRows = [['id'=>44], ['id'=>55]];
        $sql = $this->table->sql()->findListByPk($ids = [44,55]);
        $q = "SELECT *\nFROM table AS t\nWHERE t.id IN ('44','55')";
        $this->assertEquals("/*".get_class($sql)."::findListByPk*/\n".$q, (string)$sql, 'созданный SQL');

        // Запрос
        $this->table->findListByPk($ids);
        $label = get_class($this->table) . '::findListByPk';
        $this->assertEquals("/*{$label}*/\n".$q, (string)$this->db->lastQuery, 'отправленный SQL');

        // Кеширование выборки
        $this->table->findListByPk([44, 77]);
        $q = "SELECT *\nFROM table AS t\nWHERE t.id IN ('77')";
        $this->assertEquals("/*{$label}*/\n".$q, (string)$this->db->lastQuery, '44 - уже не выбирается из базы');
    }

    /**
     * FilterBy
     */
    public function testFilterBy()
    {
        $filters = ['filter' => 'val'];
        $sql = $this->table->sql()->filterBy($filters);
        $this->assertEquals($q = "SELECT *\nFROM table AS t\nWHERE t.filter='val'", (string)$sql);
    }

}
