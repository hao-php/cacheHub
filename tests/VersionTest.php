<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
    }

    /** 开启版本后，缓存数据应包含版本号包装 */
    public function testVersionedDataFormat()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->versioned = true;
        $cache->version = 1;
        $cache->key = 'test_version_format';
        $cache->valueFunc = function ($params) {
            return 'v1_data';
        };

        $cache->get();
        $key = 'unit_test:test_version_format';
        $cacheData = $this->redis->get($key);
        $this->assertEquals('cachehub_json:{"cachehub_version":1,"data":"v1_data"}', $cacheData);
    }

    /** 版本匹配时应命中缓存 */
    public function testVersionMatch()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->versioned = true;
        $cache->version = 1;
        $cache->key = 'test_version_match';
        $cache->valueFunc = function ($params) {
            return 'v1_data';
        };

        $cache->get();
        $cache->clearSource();

        $data = $cache->get();
        $this->assertEquals('v1_data', $data);
        $this->assertEquals(RedisDriver::class, $cache->getSource());
    }

    /** 版本号变更后应视为未命中，重新 build */
    public function testVersionMismatch()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->versioned = true;
        $cache->version = 1;
        $cache->key = 'test_version_mismatch';
        $cache->valueFunc = function ($params) {
            return 'v1_data';
        };

        $cache->get();
        $cache->clearSource();

        // 升级版本
        $cache->version = 2;
        $cache->valueFunc = function ($params) {
            return 'v2_data';
        };

        $data = $cache->get();
        $this->assertEquals('v2_data', $data);
        $this->assertEquals('build', $cache->getSource());

        $key = 'unit_test:test_version_mismatch';
        $cacheData = $this->redis->get($key);
        $this->assertEquals('cachehub_json:{"cachehub_version":2,"data":"v2_data"}', $cacheData);
    }

}
