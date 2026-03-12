<?php
declare(strict_types=1);


use Haoa\CacheHub\AbstractMultiCache;
use Haoa\CacheHub\CacheLevel;
use Haoa\CacheHub\Driver\ApcuDriver;
use Haoa\CacheHub\Driver\RedisDriver;
use Haoa\CacheHub\Serializer\JsonSerializer;
use Haoa\CacheHub\Serializer\RawSerializer;

class TestCache extends AbstractMultiCache
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
                serializer: JsonSerializer::class,
                ttl: $this->ttl,
                nullTtl: $this->nullTtl,
                driverHandler: new RedisPool(),
            ),
        ];
    }
}

class TestCache2 extends AbstractMultiCache
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
                serializer: JsonSerializer::class,
                ttl: $this->ttl,
                nullTtl: $this->nullTtl,
                driverHandler: new RedisPool(),
            ),
        ];
    }
}

class TestRepeatedCache extends AbstractMultiCache
{

    public $key = 'test';
    public $cacheNull = true;
    public $nullValue = '';
    public $valueFunc;

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
                serializer: JsonSerializer::class,
                ttl: 300,
                nullTtl: 60,
            ),
        ];
    }

    public function build($params)
    {
        if (empty($this->valueFunc)) {
            return '';
        }
        return call_user_func($this->valueFunc, $params);
    }

}
