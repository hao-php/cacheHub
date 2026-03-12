<?php
declare(strict_types=1);

use Haoa\CacheHub\AbstractMultiCache;
use Haoa\CacheHub\CacheLevel;
use Haoa\CacheHub\Driver\RedisDriver;
use Haoa\CacheHub\Serializer\JsonSerializer;

/**
 * 单级缓存（仅 Redis），用于基础功能测试
 */
class SingleLevelCache extends AbstractMultiCache
{

    public $key = 'test';
    public $cacheNull = true;
    public $nullValue = '';
    public $valueFunc;
    public $wrapFunc;

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

    public function getLevels(): array
    {
        return [
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
