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

    public function multiGet(array $keyArr): array
    {
        $ret = $this->handler->mGet($keyArr);
        $len = count($keyArr);
        $data = [];
        for ($i = 0; $i < $len; $i++) {
            $data[$keyArr[$i]] = $this->serializer->decode($ret[$i] ?? null);
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

    public function multiSet(array $params, int $ttl = 0): bool
    {
        $redis = $this->handler->multi(\Redis::PIPELINE);
        foreach ($params as $key => &$v) {
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

    public function multiDelete(array $key)
    {
        return $this->handler->del(...$key);
    }

}
