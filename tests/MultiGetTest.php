<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\Driver\ApcuDriver;
use Haoa\CacheHub\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;

class MultiGetTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = TestHelper::getRedis();
        $this->redis->flushDB();
        apcu_clear_cache();
    }

    /** 全部未命中时应 multiBuild 并回写 */
    public function testMultiGetAllMiss()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(MultiLevelCache::class);
        $cache->key = 'test_multi';
        $cache->multiBuildFunc = function ($params) {
            $data = [];
            foreach ($params as $key) {
                $data[$key] = [$key . '_data'];
            }
            return $data;
        };

        $data = $cache->multiGet(['a', 'b']);
        $source = $cache->getSource();

        $this->assertEquals(['a' => ['a_data'], 'b' => ['b_data']], $data);
        $this->assertEquals(['a' => 'build', 'b' => 'build'], $source);
    }

    /** 部分命中时，命中的从缓存取，未命中的走 multiBuild */
    public function testMultiGetPartialHit()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(MultiLevelCache::class);
        $cache->key = 'test_multi_partial';
        $cache->multiBuildFunc = function ($params) {
            $data = [];
            foreach ($params as $key) {
                $data[$key] = [$key . '_data'];
            }
            return $data;
        };

        // 先全量 build 写入缓存
        $cache->multiGet(['x', 'y']);
        $cache->clearSource();

        // 删除 APCu 中的 x，Redis 中仍有
        apcu_delete('unit_test:test_multi_partial:x');

        $data = $cache->multiGet(['x', 'y']);
        $source = $cache->getSource();

        $this->assertEquals(['x' => ['x_data'], 'y' => ['y_data']], $data);
        // y 从 APCu 命中，x 从 Redis 命中
        $this->assertEquals(RedisDriver::class, $source['x']);
        $this->assertEquals(ApcuDriver::class, $source['y']);
    }

}
