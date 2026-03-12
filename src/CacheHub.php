<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Exception\CacheException;
use Haoa\CacheHub\Locker\LockInterface;

class CacheHub
{

    const DEFAULT_TTL = 300;

    const DEFAULT_NULL_TTL = 60;

    /** @var CacheProxy[] */
    protected $cacheObjs = [];

    /** @var LockInterface|null 用于构建缓存时的锁 */
    protected $locker;

    /** @var LoggerInterface|null */
    protected $logger;

    /** 缓存前缀 */
    protected $prefix = 'cachehub:';

    private ?CacheEngine $engine = null;


    /**
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @param string $prefix
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
        $this->engine = null;
    }

    public function setLocker(LockInterface $locker)
    {
        $this->locker = $locker;
        $this->engine = null;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->engine = null;
    }

    protected function getEngine(): CacheEngine
    {
        if ($this->engine === null) {
            $this->engine = new CacheEngine($this->locker, $this->prefix, $this->logger);
        }
        return $this->engine;
    }

    public function getCache(string $cacheClass, bool $isNew = false): CacheProxy
    {
        if (!$isNew && isset($this->cacheObjs[$cacheClass])) {
            return $this->cacheObjs[$cacheClass];
        }
        $cache = new $cacheClass;
        if (!$cache instanceof AbstractMultiCache) {
            throw new CacheException("{$cacheClass} must be of type " . AbstractMultiCache::class);
        }
        $proxy = new CacheProxy($this->getEngine(), $cache);
        $this->cacheObjs[$cacheClass] = $proxy;
        return $proxy;
    }


}
