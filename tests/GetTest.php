<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;

class GetTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
    }

    /** 首次 get 应该走 build，结果写入缓存 */
    public function testGetFromBuild()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_get_build';
        $cache->valueFunc = function ($params) {
            return 'hello';
        };

        $data = $cache->get();
        $this->assertEquals('hello', $data);
        $this->assertEquals('build', $cache->getSource());

        $key = 'unit_test:test_get_build';
        $this->assertTrue((bool)$this->redis->exists($key));
        $ttl = $this->redis->ttl($key);
        $this->assertTrue($ttl > 0 && $ttl <= 60);
    }

    /** 第二次 get 应该命中缓存 */
    public function testGetFromCache()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_get_cache';
        $cache->valueFunc = function ($params) {
            return 'hello';
        };

        $cache->get();
        $cache->clearSource();

        $data = $cache->get();
        $this->assertEquals('hello', $data);
        $this->assertEquals(RedisDriver::class, $cache->getSource());
    }

    /** refresh=true 应该强制走 build */
    public function testGetWithRefresh()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_get_refresh';
        $cache->valueFunc = function ($params) {
            return 'hello';
        };

        $cache->get();
        $cache->clearSource();

        $data = $cache->get('', true);
        $this->assertEquals('hello', $data);
        $this->assertEquals('build', $cache->getSource());
    }

    /** 带 keyParams 的 get */
    public function testGetWithParams()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_get_params';
        $cache->valueFunc = function ($params) {
            return 'data_' . $params;
        };

        $data = $cache->get(1);
        $this->assertEquals('data_1', $data);

        $key = 'unit_test:test_get_params:1';
        $this->assertTrue((bool)$this->redis->exists($key));
    }

    /** wrapData 应该在返回前包装数据 */
    public function testGetWithWrapData()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_get_wrap';
        $cache->valueFunc = function ($params) {
            return 'raw';
        };
        $cache->wrapFunc = function ($data) {
            return $data . '_wrapped';
        };

        $data = $cache->get();
        $this->assertEquals('raw_wrapped', $data);

        // 从缓存读取也应经过 wrapData
        $cache->clearSource();
        $data = $cache->get();
        $this->assertEquals('raw_wrapped', $data);
        $this->assertEquals(RedisDriver::class, $cache->getSource());
    }

    /** build 返回数组时应正确序列化和反序列化 */
    public function testGetArrayData()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_get_array';
        $cache->valueFunc = function ($params) {
            return ['name' => 'test', 'id' => 1];
        };

        $data = $cache->get();
        $this->assertEquals(['name' => 'test', 'id' => 1], $data);

        $cache->clearSource();
        $data = $cache->get();
        $this->assertEquals(['name' => 'test', 'id' => 1], $data);
        $this->assertEquals(RedisDriver::class, $cache->getSource());
    }

    /** 单级缓存应支持 delete 方法透传 */
    public function testDelete()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_delete';
        $cache->valueFunc = function ($params) {
            return 'to_be_deleted';
        };

        $cache->get();
        $key = 'unit_test:test_delete';
        $this->assertTrue((bool)$this->redis->exists($key));

        $cache->delete($key);
        $this->assertFalse((bool)$this->redis->exists($key));
    }

    /** 单级缓存应支持 multiDelete 方法透传 */
    public function testMultiDelete()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_multi_delete';
        $cache->valueFunc = function ($params) {
            return 'data';
        };

        // 写入两个 key
        $cache->get('a');
        $cache->get('b');
        $key1 = 'unit_test:test_multi_delete:a';
        $key2 = 'unit_test:test_multi_delete:b';
        $this->assertTrue((bool)$this->redis->exists($key1));
        $this->assertTrue((bool)$this->redis->exists($key2));

        // 批量删除
        $cache->multiDelete([$key1, $key2]);
        $this->assertFalse((bool)$this->redis->exists($key1));
        $this->assertFalse((bool)$this->redis->exists($key2));
    }

}
