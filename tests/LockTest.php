<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\Driver\RedisDriver;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\WaitGroup;
use function Swoole\Coroutine\run;

class LockTest extends TestCase
{

    private $redis;

    protected function setUp(): void
    {
        $this->redis = new RedisPool();
        $this->redis->flushDB();
        apcu_clear_cache();
    }

    /** 开启锁时，并发请求只有 1 个走 build，其余从缓存获取 */
    public function testLockOnlyOneBuild()
    {
        $cacheHub = TestHelper::getCacheHub($this->redis);
        $sourceArr = [];

        \Swoole\Runtime::enableCoroutine();
        run(function () use (&$sourceArr, $cacheHub) {
            $wg = new WaitGroup(5);
            for ($i = 0; $i < 5; $i++) {
                \Swoole\Coroutine::create(function () use ($wg, &$sourceArr, $cacheHub) {
                    $cache = $cacheHub->getCache(MultiLevelCache::class, true);
                    $cache->lockEnabled = true;
                    $cache->lockTimeoutMode = 1;
                    $cache->lockRetryInterval = 10;
                    $cache->lockRetryCount = 5;
                    $cache->valueFunc = function ($params) {
                        return 'locked_data';
                    };

                    $data = $cache->get();
                    $this->assertEquals('locked_data', $data);
                    $sourceArr[] = $cache->getSource();
                    $wg->done();
                });
            }
            $wg->wait();
        });

        $buildCount = count(array_filter($sourceArr, fn($s) => $s === 'build'));
        $cacheCount = count(array_filter($sourceArr, fn($s) => $s === RedisDriver::class));
        $this->assertEquals(1, $buildCount);
        $this->assertEquals(4, $cacheCount);
    }

    /** 未开启锁时，并发请求大部分走 build */
    public function testWithoutLockMultipleBuild()
    {
        $cacheHub = TestHelper::getCacheHub($this->redis);
        $sourceArr = [];

        \Swoole\Runtime::enableCoroutine();
        run(function () use (&$sourceArr, $cacheHub) {
            $wg = new WaitGroup(5);
            for ($i = 0; $i < 5; $i++) {
                \Swoole\Coroutine::create(function () use ($wg, &$sourceArr, $cacheHub) {
                    $cache = $cacheHub->getCache(MultiLevelCache::class, true);
                    $cache->lockEnabled = false;
                    $cache->valueFunc = function ($params) {
                        return 'no_lock_data';
                    };

                    $cache->get();
                    $sourceArr[] = $cache->getSource();
                    $wg->done();
                });
            }
            $wg->wait();
        });

        $buildCount = count(array_filter($sourceArr, fn($s) => $s === 'build'));
        $this->assertTrue($buildCount > 3);
    }

    /** build 耗时超过锁等待时间，lockTimeoutMode=1 时放行到 build */
    public function testLockTimeoutPassthrough()
    {
        $cacheHub = TestHelper::getCacheHub($this->redis);
        $sourceArr = [];

        run(function () use (&$sourceArr, $cacheHub) {
            $wg = new WaitGroup();
            for ($i = 0; $i < 2; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($wg, &$sourceArr, $cacheHub) {
                    $cache = $cacheHub->getCache(MultiLevelCache::class, true);
                    $cache->lockEnabled = true;
                    $cache->lockTimeoutMode = 1;
                    $cache->lockRetryInterval = 10;
                    $cache->lockRetryCount = 5;
                    $cache->valueFunc = function ($params) {
                        sleep(1);
                        return 'slow_data';
                    };

                    $cache->get();
                    $sourceArr[] = $cache->getSource();
                    $wg->done();
                });
            }
            $wg->wait();
        });

        $buildCount = count(array_filter($sourceArr, fn($s) => $s === 'build'));
        $this->assertEquals(2, $buildCount);
    }

    /** build 耗时超过锁等待时间，lockTimeoutMode=2 时抛出异常 */
    public function testLockTimeoutException()
    {
        $cacheHub = TestHelper::getCacheHub($this->redis);
        $sourceArr = [];
        $timeoutCount = 0;

        run(function () use (&$sourceArr, &$timeoutCount, $cacheHub) {
            $wg = new WaitGroup();
            for ($i = 0; $i < 2; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($wg, &$sourceArr, &$timeoutCount, $cacheHub) {
                    try {
                        $cache = $cacheHub->getCache(MultiLevelCache::class, true);
                        $cache->lockEnabled = true;
                        $cache->lockTimeoutMode = 2;
                        $cache->lockRetryInterval = 10;
                        $cache->lockRetryCount = 5;
                        $cache->valueFunc = function ($params) {
                            sleep(1);
                            return 'slow_data';
                        };

                        $cache->get();
                        $sourceArr[] = $cache->getSource();
                    } catch (\Exception $e) {
                        if ($e->getMessage() == 'build data timeout') {
                            $timeoutCount++;
                        }
                    }
                    $wg->done();
                });
            }
            $wg->wait();
        });

        $buildCount = count(array_filter($sourceArr, fn($s) => $s === 'build'));
        $this->assertEquals(1, $buildCount);
        $this->assertEquals(1, $timeoutCount);
    }

}
