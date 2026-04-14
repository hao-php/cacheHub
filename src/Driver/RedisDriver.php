<?php
declare(strict_types=1);

namespace Haoa\CacheHub\Driver;

use Haoa\CacheHub\Common\Utils;

class RedisDriver extends AbstractDriver
{

    protected bool $canLock = true;

    /** @var \Redis */
    protected $handler;

    public function get($key)
    {
        $value = $this->handler->get($key);
        if (Utils::isMiss($value)) {
            return null;
        }
        return $this->serializer->decode($value);
    }

    public function multiGet(array $keys): array
    {
        $ret = $this->handler->mGet($keys);
        $len = count($keys);
        $data = [];
        for ($i = 0; $i < $len; $i++) {
            $data[$keys[$i]] = $this->serializer->decode($ret[$i] ?? null);
        }
        return $data;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $value = $this->serializer->encode($value);
        if (Utils::isMiss($value)) {
            return false;
        }
        return (bool)$this->handler->setex($key, $ttl, $value);
    }

    public function multiSet(array $items, int $ttl = 0): bool
    {
        $redis = $this->handler->multi(\Redis::PIPELINE);
        foreach ($items as $key => &$v) {
            $v = $this->serializer->encode($v);
            $redis->setex($key, $ttl, $v);
        }
        $redis->exec();
        return true;
    }

    public function delete(string $key): bool
    {
        return (bool)$this->handler->del($key);
    }

    public function multiDelete(array $keys): int
    {
        return (int)$this->handler->del(...$keys);
    }

}
