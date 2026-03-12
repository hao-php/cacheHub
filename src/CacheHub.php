<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Exception\Exception;
use Haoa\CacheHub\Locker\Locker;

class CacheHub
{

    const DEFAULT_TTL = 300;

    const DEFAULT_NULL_TTL = 60;

    /** @var CacheProxy[] */
    protected $cacheObjs = [];

    /** @var Locker 用于构建缓存时的锁 */
    protected $locker;

    protected Container $container;

    /** 缓存前缀 */
    protected $prefix = 'cachehub:';

    private ?CacheEngine $engine = null;


    public function __construct()
    {
        $this->container = new Container();
    }

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

    public function setLocker(Locker $locker)
    {
        $this->locker = $locker;
        $this->engine = null;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->container->setLogger($logger);
    }

    protected function getEngine(): CacheEngine
    {
        if ($this->engine === null) {
            $this->engine = new CacheEngine($this->container, $this->locker, $this->prefix);
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
            throw new Exception("{$cacheClass} must be of type " . AbstractMultiCache::class);
        }
        $proxy = new CacheProxy($this->getEngine(), $cache);
        $this->cacheObjs[$cacheClass] = $proxy;
        return $proxy;
    }


}
