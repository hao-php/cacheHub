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
     * @return bool
     */
    public function unLock(string $key): bool;

    /**
     * @param string $key 键
     * @return bool
     */
    public function isLocked(string $key): bool;

}
