<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\AbstractMultiCache;
use Haoa\CacheHub\CacheHub;
use Haoa\CacheHub\CacheLevel;
use Haoa\CacheHub\Driver\RedisDriver;
use Haoa\CacheHub\Serializer\RawSerializer;
use PHPUnit\Framework\TestCase;

/**
 * 使用 RawSerializer 的缓存类
 */
class RawSerializerCache extends AbstractMultiCache
{
    public $key = 'test';
    public $valueFunc;

    public function build($params)
    {
        return call_user_func($this->valueFunc, $params);
    }

    public function getLevels(): array
    {
        return [
            new CacheLevel(
                driver: RedisDriver::class,
                ttl: 60,
                serializer: RawSerializer::class,
                driverHandler: new RedisPool(),
            ),
        ];
    }
}

class RawSerializerTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
    }

    /** RawSerializer 应原样存储字符串，不添加 JSON 包装 */
    public function testRawSerializerStoresPlainString()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);
        $cacheHub = new CacheHub($lock, 'unit_test:');

        $cache = $cacheHub->getCache(RawSerializerCache::class);
        $cache->key = 'test_raw';
        $cache->valueFunc = function ($params) {
            return 'plain_string_data';
        };

        $cache->get();

        // 直接从 Redis 读取，验证没有 JSON 序列化包装
        $key = 'unit_test:test_raw';
        $rawValue = $this->redis->get($key);
        $this->assertEquals('plain_string_data', $rawValue);
    }

    /** RawSerializer 应正确处理非字符串数据（Redis 会自动序列化对象/数组） */
    public function testRawSerializerWithArrayData()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);
        $cacheHub = new CacheHub($lock, 'unit_test:');

        $cache = $cacheHub->getCache(RawSerializerCache::class);
        $cache->key = 'test_raw_array';
        $cache->valueFunc = function ($params) {
            return ['id' => 1, 'name' => 'test'];
        };

        $data = $cache->get();
        $this->assertEquals(['id' => 1, 'name' => 'test'], $data);
    }

}
