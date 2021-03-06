<?php namespace BladeOrm\Test;

use BladeOrm\Model;
use BladeOrm\Table;
use BladeOrm\EventListenerInterface;



class Item extends Model
{
    protected $allowGetterMagic = true;
}

class BaseTableSaveTestTable extends Table
{
    const TABLE = 'test';
    protected $availableFields = ['code', 'name'];
}

class BaseTableSaveEventListener implements EventListenerInterface {
    private $logger;
    private $type;

    public function __construct($type, BaseTableSaveEventLogger $logger)
    {
        $this->type = $type;
        $this->logger = $logger;
    }

    public function process(Model $model)
    {
        $this->logger->log .= $this->type . ' ';
    }
}

class BaseTableSaveEventLogger {
    public $log = '';
}

/**
 * @see Table
 */
class TableSaveTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var BaseTableSaveTestTable
     */
    private $table;

    private $eventLogger;

    public function setUp()
    {
        $this->eventLogger = new BaseTableSaveEventLogger;
        $this->table = new BaseTableSaveTestTable(new TestDbAdapter());
        $this->table->addListener(Table::EVENT_PRE_SAVE,    new BaseTableSaveEventListener('pre_save', $this->eventLogger));
        $this->table->addListener(Table::EVENT_POST_SAVE,   new BaseTableSaveEventListener('post_save', $this->eventLogger));
        $this->table->addListener(Table::EVENT_PRE_INSERT,  new BaseTableSaveEventListener('pre_insert', $this->eventLogger));
        $this->table->addListener(Table::EVENT_POST_INSERT, new BaseTableSaveEventListener('post_insert', $this->eventLogger));
        $this->table->addListener(Table::EVENT_PRE_UPDATE,  new BaseTableSaveEventListener('pre_update', $this->eventLogger));
        $this->table->addListener(Table::EVENT_POST_UPDATE, new BaseTableSaveEventListener('post_update', $this->eventLogger));
    }


    /**
     * INSERT
     */
    public function testInsert()
    {
        $item = new Item([
            'code' => 'Code',
            'name' => 'Name',
            'unknown' => 123,
        ]);

        $db = $this->table->getAdapter();
        $db->returnRows = ['id' =>$id = 555];
        $this->table->insert($item);
        $this->assertEquals($id, $item->get('id'), 'Присвоен ID');
        $this->assertFalse($item->isNew(), 'Отмечен как сохранен');

        $this->assertSame("INSERT INTO test (code, name) VALUES ('Code', 'Name') RETURNING id", (string)$db->lastQuery);

        $this->assertEquals('pre_insert pre_save post_insert post_save ', $this->eventLogger->log);
    }


    /**
     * UPDATE
     */
    public function testUpdate()
    {
        $item = $this->table->makeModel([
            'id'   => 556,
            'code' => 'Code',
            'name' => 'Name',
            'unknown' => 123,
        ]);
        $item->set('code', 'New Code');
        $item->set('unknown', 22);

        $db = $this->table->getAdapter();
        $this->table->update($item);
        $this->assertFalse($item->isNew(), 'Отмечен как сохранен');
        $this->assertSame(['unknown'=>22], $item->getValuesUpdated(), 'Обнулены isModified');

        $this->assertSame("UPDATE test SET code='New Code'\nWHERE id='556'", (string)$db->lastQuery);

        $this->assertEquals('pre_update pre_save post_update post_save ', $this->eventLogger->log);
    }

}
