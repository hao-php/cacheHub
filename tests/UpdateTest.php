<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use PHPUnit\Framework\TestCase;

class UpdateTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
    }

    /** update 应该重新 build 并写入缓存 */
    public function testUpdateWritesToCache()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_update';
        $cache->ttl = 300;
        $cache->valueFunc = function ($params) {
            return 'updated_value';
        };

        $key = 'unit_test:test_update';
        $this->assertFalse((bool)$this->redis->exists($key));

        $ret = $cache->update();
        $this->assertEquals(1, $ret);

        $redisValue = $this->redis->get($key);
        $this->assertEquals('updated_value', $redisValue);
        $ttl = $this->redis->ttl($key);
        $this->assertTrue($ttl > 0 && $ttl <= 300);
    }

    /** update 空数据时应写入空值标记 */
    public function testUpdateWithEmptyData()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = 'test_update_empty';
        $cache->cacheNull = true;
        $cache->valueFunc = function ($params) {
            return '';
        };

        $ret = $cache->update();
        $this->assertEquals(1, $ret);

        $key = 'unit_test:test_update_empty';
        $this->assertTrue((bool)$this->redis->exists($key));
    }

}
