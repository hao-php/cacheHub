<?php
declare(strict_types=1);

namespace Haoa\CacheHub\Locker;

interface LockInterface
{

    /**
     * @param string $key 键
     * @param mixed $value 值
     * @param int $expire 过期时间, 秒
     * @return bool
     */
    public function tryLock(string $key, $value, int $expire): bool;

    /**
     * @param string $key 键
     * @param string $token 本次获取锁时使用的唯一标识
     * @return bool 是否成功释放自己持有的锁
     */
    public function unlock(string $key, string $token): bool;

    /**
     * @param string $key 键
     * @return bool
     */
    public function isLocked(string $key): bool;

}
