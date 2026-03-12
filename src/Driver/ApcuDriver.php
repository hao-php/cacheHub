<?php
declare(strict_types=1);

namespace Haoa\CacheHub\Driver;

use Haoa\CacheHub\Common\Utils;

class ApcuDriver extends AbstractDriver
{

    protected bool $canLock = false;

    public function __construct()
    {
        if (!apcu_enabled()) {
            throw new \Exception('apcu is not enabled');
        }
    }

    public function get($key)
    {
        $value = apcu_fetch($key);
        if (Utils::isMiss($value)) {
            return null;
        }
        return $this->serializer->decode($value);
    }

    public function multiGet(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            $value = apcu_fetch($key);
            if (Utils::isMiss($value)) {
                $data[$key] = null;
            } else {
                $data[$key] = $this->serializer->decode($value);
            }
        }
        return $data;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $value = $this->serializer->encode($value);
        if (Utils::isMiss($value)) {
            return false;
        }
        return (bool)apcu_store($key, $value, (int)$ttl);
    }

    public function multiSet(array $items, int $ttl): bool
    {
        foreach ($items as $key => $value) {
            $value = $this->serializer->encode($value);
            if (!Utils::isMiss($value)) {
                apcu_store($key, $value, $ttl);
            }
        }
        return true;
    }

    public function delete(string $key): bool
    {
        return (bool)apcu_delete($key);
    }

    public function multiDelete(array $keys)
    {
        return apcu_delete($keys);
    }
}
