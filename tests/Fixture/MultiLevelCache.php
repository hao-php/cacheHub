<?php
declare(strict_types=1);

use Haoa\CacheHub\AbstractMultiCache;
use Haoa\CacheHub\CacheLevel;
use Haoa\CacheHub\Driver\ApcuDriver;
use Haoa\CacheHub\Driver\RedisDriver;
use Haoa\CacheHub\Serializer\JsonSerializer;

/**
 * 多级缓存（APCu + Redis），用于多级回写、批量操作等测试
 */
class MultiLevelCache extends AbstractMultiCache
{

    public $key = 'test';
    public $cacheNull = true;
    public $nullValue = '';
    public $valueFunc;
    public $wrapFunc;
    public $multiBuildFunc;

    public $ttl = 60;
    public $nullTtl = 60;

    public function build($params)
    {
        if (empty($this->valueFunc)) {
            return '';
        }
        return call_user_func($this->valueFunc, $params);
    }

    public function wrapData($data)
    {
        if (empty($this->wrapFunc)) {
            return $data;
        }
        return call_user_func($this->wrapFunc, $data);
    }

    public function multiBuild(array $params): array
    {
        return call_user_func($this->multiBuildFunc, $params);
    }

    public function getLevels(): array
    {
        return [
            new CacheLevel(
                driver: ApcuDriver::class,
                ttl: 5,
                nullTtl: 5,
            ),
            new CacheLevel(
                driver: RedisDriver::class,
                ttl: $this->ttl,
                serializer: JsonSerializer::class,
                nullTtl: $this->nullTtl,
                driverHandler: new RedisPool(),
            ),
        ];
    }
}
