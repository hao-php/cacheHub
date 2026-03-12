<?php
declare(strict_types=1);

use Haoa\CacheHub\CacheHub;
use Haoa\CacheHub\Locker\RedisLock;

class TestHelper
{

    /**
     * @return Redis
     */
    public static function getRedis()
    {
        $redis = new Redis();
        $redis->connect('redis');
        $redis->select(3);
        return $redis;
    }

    public static function getCacheHub($redis = null): CacheHub
    {
        if (empty($redis)) {
            $redis = self::getRedis();
        }

        $cacheHub = new CacheHub();
        $cacheHub->setPrefix('unit_test:');
        $locker = new RedisLock($redis);
        $cacheHub->setLocker($locker);
        return $cacheHub;
    }

}
