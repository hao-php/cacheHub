<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Exception\CacheException;
use Haoa\CacheHub\Locker\LockInterface;
use Psr\Log\LoggerInterface;

class CacheHub
{

    const DEFAULT_TTL = 300;

    const DEFAULT_NULL_TTL = 60;

    /** @var CacheProxy[] */
    protected $caches = [];

    private ?CacheEngine $engine = null;

    public function __construct(
        /** 用于构建缓存时的锁 */
        protected LockInterface $lock,
        /** 缓存前缀 */
        protected string $prefix = 'cachehub:',
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    protected function getEngine(): CacheEngine
    {
        if ($this->engine === null) {
            $this->engine = new CacheEngine($this->lock, $this->prefix, $this->logger);
        }
        return $this->engine;
    }

    public function getCache(string $cacheClass, bool $fresh = false): CacheProxy
    {
        if (!$fresh && isset($this->caches[$cacheClass])) {
            return $this->caches[$cacheClass];
        }
        $cache = new $cacheClass;
        if (!$cache instanceof AbstractMultiCache) {
            throw new CacheException("{$cacheClass} must be of type " . AbstractMultiCache::class);
        }
        $proxy = new CacheProxy($this->getEngine(), $cache);
        $this->caches[$cacheClass] = $proxy;
        return $proxy;
    }


}
