<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;

class NullValueTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
    }

    /** cacheNull=true 时，空数据应写入缓存，再次读取返回 null */
    public function testCacheNullEnabled()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_null_enabled';
        $cache->cacheNull = true;
        $cache->nullValue = '';
        $cache->nullTtl = 60;
        $cache->valueFunc = function ($params) {
            return '';
        };

        $key = 'unit_test:test_null_enabled';

        // 首次：build 返回空字符串，写入 nullValue
        $data = $cache->get();
        $this->assertEquals('', $data);
        $this->assertEquals('build', $cache->getSource());
        $ttl = $this->redis->ttl($key);
        $this->assertTrue($ttl > 0 && $ttl <= 60);

        $cache->clearSource();

        // 二次：命中缓存中的 nullValue，返回 null
        $data = $cache->get();
        $this->assertNull($data);
        $this->assertEquals(RedisDriver::class, $cache->getSource());
    }

    /** 自定义 nullValue 应正确写入缓存 */
    public function testCustomNullValue()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_null_custom';
        $cache->cacheNull = true;
        $cache->nullValue = 'cachehub_null';
        $cache->valueFunc = function ($params) {
            return '';
        };

        $key = 'unit_test:test_null_custom';
        $cache->get();

        $cacheData = $this->redis->get($key);
        $this->assertEquals('cachehub_null', $cacheData);
    }

    /** cacheNull=false 时，空数据不应写入缓存 */
    public function testCacheNullDisabled()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_null_disabled';
        $cache->cacheNull = false;
        $cache->valueFunc = function ($params) {
            return null;
        };

        $key = 'unit_test:test_null_disabled';

        $data = $cache->get();
        $this->assertNull($data);
        $this->assertFalse((bool)$this->redis->exists($key));
        $this->assertEquals('build', $cache->getSource());

        $cache->clearSource();

        // 再次请求仍走 build，因为没有缓存
        $data = $cache->get();
        $this->assertNull($data);
        $this->assertEquals('build', $cache->getSource());
    }

}
