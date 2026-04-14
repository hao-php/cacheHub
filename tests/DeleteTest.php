<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\Driver\ApcuDriver;
use Haoa\CacheHub\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;

class DeleteTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
    }

    /** 多级缓存 delete 应从所有层级删除 */
    public function testMultiLevelDelete()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(MultiLevelCache::class);
        $cache->key = 'test_multi_level_delete';
        $cache->valueFunc = function ($params) {
            return 'data';
        };

        // 先写入缓存（会写入 APCu 和 Redis 两级）
        $cache->get();

        // 验证两级缓存都存在
        $apcuKey = 'unit_test:test_multi_level_delete';
        $redisKey = 'unit_test:test_multi_level_delete';
        $this->assertTrue(apcu_exists($apcuKey));
        $this->assertTrue((bool)$this->redis->exists($redisKey));

        // delete 应从所有层级删除
        $ret = $cache->delete();
        $this->assertEquals(2, $ret);

        $this->assertFalse(apcu_exists($apcuKey));
        $this->assertFalse((bool)$this->redis->exists($redisKey));
    }

    /** 多级缓存 delete 带 keyParams */
    public function testMultiLevelDeleteWithParams()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(MultiLevelCache::class);
        $cache->key = 'test_delete_params';
        $cache->valueFunc = function ($params) {
            return 'data_' . $params;
        };

        $cache->get(123);

        $apcuKey = 'unit_test:test_delete_params:123';
        $redisKey = 'unit_test:test_delete_params:123';
        $this->assertTrue(apcu_exists($apcuKey));
        $this->assertTrue((bool)$this->redis->exists($redisKey));

        $ret = $cache->delete(123);
        $this->assertEquals(2, $ret);

        $this->assertFalse(apcu_exists($apcuKey));
        $this->assertFalse((bool)$this->redis->exists($redisKey));
    }

    /** 多级缓存 multiDelete 应从所有层级批量删除 */
    public function testMultiLevelMultiDelete()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(MultiLevelCache::class);
        $cache->key = 'test_multi_level_multi_delete';
        $cache->valueFunc = function ($params) {
            return 'data_' . $params;
        };

        // 写入多个 key
        $cache->get('a');
        $cache->get('b');
        $cache->get('c');

        // 验证两级缓存都存在
        $keys = ['a', 'b', 'c'];
        foreach ($keys as $k) {
            $apcuKey = 'unit_test:test_multi_level_multi_delete:' . $k;
            $redisKey = 'unit_test:test_multi_level_multi_delete:' . $k;
            $this->assertTrue(apcu_exists($apcuKey));
            $this->assertTrue((bool)$this->redis->exists($redisKey));
        }

        // 批量删除
        $ret = $cache->multiDelete(['a', 'b']);
        $this->assertEquals(2, $ret[ApcuDriver::class]);
        $this->assertEquals(2, $ret[RedisDriver::class]);

        // a, b 应被删除
        foreach (['a', 'b'] as $k) {
            $apcuKey = 'unit_test:test_multi_level_multi_delete:' . $k;
            $redisKey = 'unit_test:test_multi_level_multi_delete:' . $k;
            $this->assertFalse(apcu_exists($apcuKey));
            $this->assertFalse((bool)$this->redis->exists($redisKey));
        }

        // c 应仍存在
        $apcuKey = 'unit_test:test_multi_level_multi_delete:c';
        $redisKey = 'unit_test:test_multi_level_multi_delete:c';
        $this->assertTrue(apcu_exists($apcuKey));
        $this->assertTrue((bool)$this->redis->exists($redisKey));
    }

    /** delete 不存在的 key 应返回 0 */
    public function testDeleteNonExistent()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_delete_non_existent';

        $ret = $cache->delete();
        $this->assertEquals(0, $ret);
    }

}