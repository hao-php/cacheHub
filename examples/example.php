<?php

use Haoa\CacheHub\AbstractMultiCache;
use Haoa\CacheHub\CacheHub;
use Haoa\CacheHub\CacheLevel;
use Haoa\CacheHub\Driver\ApcuDriver;
use Haoa\CacheHub\Driver\RedisDriver as RedisDriver;
use Haoa\CacheHub\Locker\RedisLock;
use Haoa\CacheHub\Serializer\JsonSerializer;

require __DIR__ . '/autoload.php';

class Logger implements \Haoa\CacheHub\LoggerInterface
{


    public function debug(string $msg): void
    {
        echo "debug: {$msg}\n";
    }

    public function error(string $msg): void
    {
        echo "error: {$msg}\n";
    }
}

class ExTest extends AbstractMultiCache
{

    public $key = "ex_test";
    public $lockEnabled = true;

    public $cacheNull = true;

    public function getLevels(): array
    {
        $redis = new \Redis();
        $redis->connect('redis');
        $redis->select(3);
        return [
            new CacheLevel(
                driver: ApcuDriver::class,
                ttl: 5,
            ),
            new CacheLevel(
                driver: RedisDriver::class,
                serializer: JsonSerializer::class,
                ttl: 300,
                nullTtl: 60,
                driverHandler: $redis,
            ),
        ];
    }

    /**
     * 生成数据
     */
    public function build($params)
    {
        return '';
        // return 'ex_data';
    }

    public function multiBuild(array $params): array
    {
        $data = [];
        foreach ($params as $key) {
            $data[$key] = [$key . '_data'];
        }
        return $data;
    }

    /**
     * 包装数据
     */
    public function wrapData($data)
    {
        return $data;
    }
}

class AppCacheHub
{
    /** 测试用 */
    const EX_TEXT = 'ex_test';

    /**
     * 获取cacheHub对象, 自行处理单例, 初始化
     */
    public static function getCacheHub(): CacheHub
    {
        $redis = new \Redis();
        $redis->connect('redis');
        $redis->select(3);

        $cacheHub = new CacheHub();

        // 设置key的前缀
        $cacheHub->setPrefix('ex:');

        // 注入redis锁
        $locker = new RedisLock($redis);
        $cacheHub->setLocker($locker);
        $cacheHub->setLogger(new Logger());

        return $cacheHub;
    }

    public static function test()
    {
        $cacheHub = self::getCacheHub();
        $cache = $cacheHub->getCache(ExTest::class);
        $cache = $cacheHub->getCache(ExTest::class, true);

        // 批量获取数据
        $data = $cache->multiGet(['test_1', 'test_2']);
        $from = $cache->getSource();
        var_dump($data, $from);

        apcu_delete('ex:ex_test:test_1');

        $data = $cache->multiGet(['test_1', 'test_2']);
        $from = $cache->getSource();
        var_dump($data, $from);
        //
        // $data = $cache->multiGet(['test_1', 'test_2']);
        // $from = $cache->getSource();
        // var_dump($data, $from);

        // 获取数据
        // $data = $cache->get();
        // $from = $cache->getSource();
        // var_dump($data, $from);
        //
        // $data = $cache->get();
        // $from = $cache->getSource();
        // var_dump($data, $from);

        // 强制刷新, 获取数据
        // $data = $cache->get('', true);
        // var_dump($data);

        //
        // // 更新数据
        // $ret = $cache->update('');
        // var_dump($ret);
        //

        // 只有一级缓存时, 调用原生驱动的方法
        // $cache->lPush('test', 1);
    }

}

AppCacheHub::test();

// apcu_store("ex:test", "test", 300);
// $ret = apcu_fetch("ex:test1");
// var_dump($ret);