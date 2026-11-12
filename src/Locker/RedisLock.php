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

    public function unlock(string $key, string $token): bool
    {
        // 原子校验所有权，避免删除锁过期后其他请求重新获取的锁。
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;
        return (bool)$this->redis->eval($script, [$key, $token], 1);
    }

    public function isLocked(string $key): bool
    {
        return !empty($this->redis->get($key));
    }

}
