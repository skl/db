<?php namespace OrnoTest;

use Orno\Db\Driver\Oci8;

class Oci8IntegrationTest extends \PHPUnit_Framework_TestCase
{
    protected $config = [
        'database' => 'OCI8_DATABASE',
        'username' => 'OCI8_USERNAME',
        'password' => 'OCI8_PASSWORD'
    ];

    protected $driver;

    public function setUp()
    {
        if (! extension_loaded('oci8')) {
            $this->markTestSkipped('The OCI8 extension is not loaded and therefore cannot be integration tested');
        }

        foreach ($this->config as $key => $val) {
            if (! isset($GLOBALS[$val])) {
                $this->markTestSkipped('Missing required config variable ' . $val . ' from phpunit.xml');
            }

            $this->config[$key] = $GLOBALS[$val];
        }

        $this->driver = new Oci8($this->config);
    }

    public function tearDown()
    {
        if (extension_loaded('oci8')) {
            @$this->driver->prepareQuery('DROP TABLE test_data');
            @$this->driver->execute();
            @$this->driver->disconnect();
        }

        unset($this->driver);
    }

    public function testConnectsAndDisconnects()
    {
        $this->driver->connect();
        $this->assertTrue(is_resource($this->readAttribute($this->driver, 'connection')));
        $this->driver->disconnect();
        $this->assertFalse(is_resource($this->readAttribute($this->driver, 'connection')));
    }

    public function testConnectionFailsWithIncorrectCredentials()
    {
        $this->setExpectedException('Orno\Db\Exception\ConnectionException');
        $this->driver->connect(['database' => 'WRONG_DB_STRING']);
    }

    public function testPreparesQuery()
    {
        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->assertTrue(is_resource($this->readAttribute($this->driver, 'statement')));
        $this->driver->disconnect();
        $this->assertFalse(is_resource($this->readAttribute($this->driver, 'statement')));
    }

    public function testBindingThrowsExceptionWithoutStatement()
    {
        $this->setExpectedException('Orno\Db\Exception\NoResourceException');
        $this->driver->bind(':placeholder', 'value');
    }

    public function testBindingThrowsExceptionWithoutPlaceholder()
    {
        $this->setExpectedException('Orno\Db\Exception\BindingException');
        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->bind(':placeholder', 1);
    }

    public function testBindingParameter()
    {
        $this->driver->prepareQuery('SELECT * FROM test_data WHERE placeholder = :placeholder AND placeholder2 = :placeholder2');
        $this->assertSame($this->driver, $this->driver->bind(':placeholder', 'value'));
        $this->assertSame($this->driver, $this->driver->bind(':placeholder2', 'value'));
    }

    public function testExecuteThrowsExceptionWithoutStatement()
    {
        $this->setExpectedException('Orno\Db\Exception\NoResourceException');
        $this->driver->execute();
    }

    public function testAutoCommitExecutesAndFetchAll()
    {
        $this->driver->prepareQuery('CREATE TABLE test_data (username varchar(100), email varchar(100))');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $this->driver->prepareQuery('INSERT INTO test_data VALUES (:username, :email)');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->assertSame($this->getInitialData(), $this->driver->fetchAll());
    }

    public function testTransactionCommits()
    {
        $this->driver->transaction();

        $this->driver->prepareQuery('CREATE TABLE test_data (username varchar(100), email varchar(100))');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $this->driver->prepareQuery('INSERT INTO test_data VALUES (:username, :email)');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->commit();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->assertSame($this->getInitialData(), $this->driver->fetchAll());

        $this->driver->transaction();

        foreach ($this->getUpdatedData() as $data) {
            $this->driver->prepareQuery('UPDATE test_data SET username = :username WHERE email = :email');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->prepareQuery('DELETE FROM test_data WHERE username NOT LIKE :username');
        $this->driver->bind(':username', '%_updated');
        $this->driver->execute();

        $this->driver->commit();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->assertSame($this->getUpdatedData(), $this->driver->fetchAll());
    }

    public function testTransactionRollsBack()
    {
        $this->driver->transaction();

        $this->driver->prepareQuery('CREATE TABLE test_data (username varchar(100), email varchar(100))');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $this->driver->prepareQuery('INSERT INTO test_data VALUES (:username, :email)');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->commit();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->assertSame($this->getInitialData(), $this->driver->fetchAll());

        $this->driver->transaction();

        foreach ($this->getUpdatedData() as $data) {
            $this->driver->prepareQuery('UPDATE test_data SET username = :username WHERE email = :email');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->prepareQuery('DELETE FROM test_data WHERE username NOT LIKE :username');
        $this->driver->bind(':username', '%_updated');
        $this->driver->execute();

        $this->driver->rollback();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->assertSame($this->getInitialData(), $this->driver->fetchAll());
    }

    public function testFetchThrowsExceptionWithoutStatement()
    {
        $this->setExpectedException('Orno\db\Exception\NoResourceException');
        $this->driver->fetch();
    }

    public function testFetchBringsBackRowByRow()
    {
        $this->driver->transaction();

        $this->driver->prepareQuery('CREATE TABLE test_data (username varchar(100), email varchar(100))');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $this->driver->prepareQuery('INSERT INTO test_data VALUES (:username, :email)');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->commit();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $this->assertSame($data, $this->driver->fetch());
        }
    }

    public function testFetchAllThrowsExceptionWithoutStatement()
    {
        $this->setExpectedException('Orno\db\Exception\NoResourceException');
        $this->driver->fetchAll();
    }

    public function testFetchObjectThrowsExceptionWithoutStatement()
    {
        $this->setExpectedException('Orno\db\Exception\NoResourceException');
        $this->driver->fetchObject();
    }

    public function testFetchObjectBringsBackRowByRow()
    {
        $this->driver->transaction();

        $this->driver->prepareQuery('CREATE TABLE test_data (username varchar(100), email varchar(100))');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $this->driver->prepareQuery('INSERT INTO test_data VALUES (:username, :email)');
            $this->driver->bind(':username', $data['USERNAME']);
            $this->driver->bind(':email', $data['EMAIL']);
            $this->driver->execute();
        }

        $this->driver->commit();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        $this->driver->prepareQuery('SELECT * FROM test_data');
        $this->driver->execute();

        foreach ($this->getInitialData() as $data) {
            $row = $this->driver->fetchObject();
            $this->assertSame($data['USERNAME'], $row->USERNAME);
            $this->assertSame($data['EMAIL'], $row->EMAIL);
        }
    }

    public function getInitialData()
    {
        return [
            ['USERNAME' => 'pbenn', 'EMAIL' => 'pbenn@example.com'],
            ['USERNAME' => 'posbo', 'EMAIL' => 'posbo@example.com'],
            ['USERNAME' => 'mbard', 'EMAIL' => 'mbard@example.com'],
            ['USERNAME' => 'jfrye', 'EMAIL' => 'jfrye@example.com'],
            ['USERNAME' => 'slang', 'EMAIL' => 'slang@example.com']
        ];
    }

    public function getUpdatedData()
    {
        return [
            ['USERNAME' => 'pbenn_updated', 'EMAIL' => 'pbenn@example.com'],
            ['USERNAME' => 'posbo_updated', 'EMAIL' => 'posbo@example.com'],
            ['USERNAME' => 'mbard_updated', 'EMAIL' => 'mbard@example.com']
        ];
    }
}
