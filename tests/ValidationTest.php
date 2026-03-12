<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/autoload.php';

use Haoa\CacheHub\AbstractMultiCache;
use Haoa\CacheHub\CacheLevel;
use Haoa\CacheHub\Driver\ApcuDriver;
use Haoa\CacheHub\Exception\CacheException;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{

    /** key 为空时应抛出异常 */
    public function testEmptyKeyThrows()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache = $cacheHub->getCache(SingleLevelCache::class);
        $cache->key = '';

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('key is empty');
        $cache->get();
    }

    /** levels 中配置重复 driver 时应抛出异常 */
    public function testDuplicateDriverThrows()
    {
        $duplicateCache = new class extends AbstractMultiCache {
            public $key = 'test_dup';

            public function getLevels(): array
            {
                return [
                    new CacheLevel(driver: ApcuDriver::class, ttl: 5),
                    new CacheLevel(driver: ApcuDriver::class, ttl: 10),
                ];
            }

            public function build($params)
            {
                return 'data';
            }
        };

        $cacheHub = TestHelper::getCacheHub();

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('levels 中不能配置相同的 driver');

        $ref = new ReflectionClass($cacheHub);
        $method = $ref->getMethod('getEngine');
        $method->setAccessible(true);
        $engine = $method->invoke($cacheHub);
        $engine->get($duplicateCache);
    }

    /** getCache 传入非 AbstractMultiCache 子类应抛出异常 */
    public function testInvalidCacheClassThrows()
    {
        $cacheHub = TestHelper::getCacheHub();

        $this->expectException(CacheException::class);
        $cacheHub->getCache(\stdClass::class);
    }

    /** getCache fresh=true 应返回新实例 */
    public function testGetCacheFreshInstance()
    {
        $cacheHub = TestHelper::getCacheHub();
        $cache1 = $cacheHub->getCache(SingleLevelCache::class);
        $cache2 = $cacheHub->getCache(SingleLevelCache::class);
        $cache3 = $cacheHub->getCache(SingleLevelCache::class, true);

        $this->assertSame($cache1, $cache2);
        $this->assertNotSame($cache1, $cache3);
    }

}
