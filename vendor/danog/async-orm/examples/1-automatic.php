<?php declare(strict_types=1);

use Amp\Mysql\MysqlConfig;
use Amp\Postgres\PostgresConfig;
use Amp\Redis\RedisConfig;
use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbAutoProperties;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Settings;
use danog\AsyncOrm\Settings\MysqlSettings;
use danog\AsyncOrm\Settings\PostgresSettings;
use danog\AsyncOrm\Settings\RedisSettings;
use danog\AsyncOrm\ValueType;

require __DIR__ . '/../vendor/autoload.php';

// Any of the following database backends can be used,
// remove the ones you don't need.
$settings = new MysqlSettings(
    new MysqlConfig(
        host: "/var/run/mysqld/mysqld.sock",
        user: 'user',
        password: 'password',
        database: 'database'
    ),
    cacheTtl: 100
);
$settings = new PostgresSettings(
    new PostgresConfig(
        host: "127.0.0.1",
        user: "user",
        password: "password",
        database: "database"
    ),
    cacheTtl: 100
);
$settings = new RedisSettings(
    RedisConfig::fromUri("redis://127.0.0.1"),
    cacheTtl: 100
);

/**
 * An object stored in a database.
 */
class MyObject extends DbObject
{
    public function __construct(
        private string $value
    ) {
    }
    public function setValue(string $value): void
    {
        $this->value = $value;
    }
    public function getValue(): string
    {
        return $this->value;
    }
}

/**
 * Main class of your application.
 */
final class Application
{
    use DbAutoProperties;

    /**
     * This field is automatically connected to the database using the specified Settings.
     *
     * @var DbArray<string, MyObject>
     */
    #[OrmMappedArray(KeyType::STRING, ValueType::OBJECT)]
    private DbArray $dbProperty1;

    /**
     * This field is automatically connected to the database using the specified Settings.
     *
     * @var DbArray<string, int>
     */
    #[OrmMappedArray(KeyType::STRING, ValueType::INT)]
    private DbArray $dbProperty2;

    public function __construct(
        Settings $settings,
        string $tablePrefix
    ) {
        $this->initDbProperties($settings, $tablePrefix);
    }

    public function businessLogic(): void
    {
        $this->dbProperty1['someOtherKey'] = new MyObject("initialValue");

        // Can store integers, strings, arrays or objects depending on the specified ValueType
        $this->dbProperty2['someKey'] = 123;
        var_dump($this->dbProperty2['someKey']);
    }

    public function businessLogic2(string $value): void
    {
        $obj = $this->dbProperty1['someOtherKey'];
        $obj->setValue($value);
        $obj->save();
    }

    public function businessLogic3(): string
    {
        return $this->dbProperty1['someOtherKey']->getValue();
    }

    public function shutdown(): void
    {
        // Flush all database caches, saving all changes.
        $this->saveDbProperties();
    }
}

$app = new Application($settings, 'tablePrefix');
$app->businessLogic();
$app->businessLogic2("newValue");
var_dump($app->businessLogic3());
$app->shutdown();
