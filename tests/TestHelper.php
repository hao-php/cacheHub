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
        $configFile = __DIR__ . '/config.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException('测试配置文件不存在，请先复制 config.example.php 为 config.php');
        }

        $config = require $configFile;
        $redisConfig = $config['redis'];

        $redis = new Redis();
        $redis->connect($redisConfig['host'], $redisConfig['port']);
        $redis->select($redisConfig['db']);
        return $redis;
    }

    public static function getCacheHub($redis = null): CacheHub
    {
        if (empty($redis)) {
            $redis = self::getRedis();
        }

        $lock = new RedisLock($redis);
        $cacheHub = new CacheHub($lock, 'unit_test:');
        return $cacheHub;
    }

}
