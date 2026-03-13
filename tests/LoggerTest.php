<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\CacheHub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Logger 测试
 */
class LoggerTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = new RedisPool();
        $this->redis->flushDB();
    }

    /** 没有 logger 时，缓存 get 操作应正常执行不报错 */
    public function testGetWithoutLogger()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);
        // 不传入 logger
        $cacheHub = new CacheHub($lock, 'unit_test:');

        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->valueFunc = function ($params) {
            return 'test_data';
        };

        // 不应抛出异常
        $data = $cache->get();
        $this->assertEquals('test_data', $data);
    }

    /** 没有 logger 时，缓存 update 操作应正常执行不报错 */
    public function testUpdateWithoutLogger()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);
        // 不传入 logger
        $cacheHub = new CacheHub($lock, 'unit_test:');

        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->valueFunc = function ($params) {
            return 'updated_data';
        };

        // 先写入数据
        $cache->get();

        // update 不应抛出异常
        $result = $cache->update();
        $this->assertGreaterThan(0, $result);
    }

    /** 没有 logger 时，写入失败的情况不应报错 */
    public function testSetFailureWithoutLogger()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);
        // 不传入 logger
        $cacheHub = new CacheHub($lock, 'unit_test:');

        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->valueFunc = function ($params) {
            return 'test_data';
        };

        // 正常执行，不应抛出异常
        $data = $cache->get();
        $this->assertEquals('test_data', $data);
    }

    /**
     * get 成功时会触发 logger->debug
     *
     * createMock 创建模拟对象，用于验证 logger 的调用行为：
     *  - 代替真实 Logger，测试更快且不依赖外部服务
     *  - expects($this->once()) 验证方法被调用 1 次
     *  - with($this->stringContains('[update]')) 验证参数包含特定字符串
     */
    public function testGetSuccessTriggersLoggerDebug()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);

        // 创建 mock logger，模拟 LoggerInterface
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('set successfully'));

        $cacheHub = new CacheHub($lock, 'unit_test:', $logger);

        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->valueFunc = function ($params) {
            return 'test_data';
        };

        $cache->get();
    }

    /**
     * update 成功时会触发 logger->debug
     *
     * createMock 创建模拟对象，用于验证 logger 的调用行为：
     * - 代替真实 Logger，测试更快且不依赖外部服务
     * - expects($this->once()) 验证方法被调用 1 次
     * - with($this->stringContains('[update]')) 验证参数包含特定字符串
     */
    public function testUpdateSuccessTriggersLoggerDebug()
    {
        $lock = new \Haoa\CacheHub\Locker\RedisLock($this->redis);

        // 创建 mock logger，模拟 LoggerInterface
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('[update]'));

        $cacheHub = new CacheHub($lock, 'unit_test:', $logger);

        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->valueFunc = function ($params) {
            return 'test_data';
        };

        $cache->update();
    }

}
