<?php
declare(strict_types=1);

namespace Haoa\CacheHub\Locker;

class RedisLock implements LockInterface
{

    /**
     * @var \Redis
     */
    private $redis;

    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    public function tryLock(string $key, $value, int $expire): bool
    {
        return $this->redis->set($key, $value, ['nx', 'ex' => $expire]);
    }

    public function unlock(string $key): bool
    {
        return (bool)$this->redis->del($key);
    }

    public function isLocked(string $key): bool
    {
        return !empty($this->redis->get($key));
    }

}
