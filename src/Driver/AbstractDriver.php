<?php
declare(strict_types=1);

namespace Haoa\CacheHub\Driver;

use Haoa\CacheHub\Exception\CacheException;
use Haoa\CacheHub\Serializer\SerializerInterface;

abstract class AbstractDriver
{

    protected $handler;

    /** @var SerializerInterface */
    protected $serializer;

    /** @var bool 是否可以加锁等待数据 */
    protected bool $canLock = false;

    abstract public function get($key);

    abstract public function set($key, $value, $ttl = null): bool;

    abstract public function delete(string $key): bool;

    abstract public function multiGet(array $keys): array;

    abstract public function multiSet(array $items, int $ttl): bool;

    /** @return int 删除成功的数量 */
    abstract public function multiDelete(array $keys): int;

    /** 构建最终的缓存 key */
    public function makeKey(string $prefix, string $key, $keyParams = ''): string
    {
        $str = '';
        if (!empty($keyParams)) {
            if (!is_array($keyParams)) {
                $keyParams = [$keyParams];
            }
            $str = implode('_', $keyParams);
        }
        $key = $prefix . $key;
        if (!empty($str)) {
            $key .= ':' . $str;
        }
        return $key;
    }

    public function getHandler()
    {
        if (empty($this->handler)) {
            throw new CacheException('handler is empty');
        }
        return $this->handler;
    }

    public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    public function canLock(): bool
    {
        return $this->canLock;
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->handler, $name], $arguments);
    }

}
