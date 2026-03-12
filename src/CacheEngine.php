<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Common\Utils;
use Haoa\CacheHub\Driver\AbstractDriver;
use Haoa\CacheHub\Exception\CacheException;
use Haoa\CacheHub\Locker\LockInterface;
use Haoa\CacheHub\Serializer\SerializerInterface;

class CacheEngine
{

    /** @var LockInterface|null */
    protected $locker;

    protected string $prefix;

    protected ?LoggerInterface $logger;

    protected array $driverObjs = [];

    protected array $serializerObjs = [];

    public function __construct($locker, string $prefix, ?LoggerInterface $logger = null)
    {
        $this->locker = $locker;
        $this->prefix = $prefix;
        $this->logger = $logger;
    }

    /**
     * 获取驱动实例（带缓存）
     * @param CacheLevel $level 缓存层级配置
     * @return AbstractDriver
     */
    protected function getDriver(CacheLevel $level): AbstractDriver
    {
        $class = $level->driver;
        if (!isset($this->driverObjs[$class])) {
            $this->driverObjs[$class] = new $class;
            if (!$this->driverObjs[$class] instanceof AbstractDriver) {
                throw new CacheException("driver[{$class}] must be of type " . AbstractDriver::class);
            }
            $this->driverObjs[$class]->setHandler($level->driverHandler);
            $this->driverObjs[$class]->setSerializer($this->getSerializer($level->serializer));
        }
        return $this->driverObjs[$class];
    }

    /**
     * 获取序列化器实例（带缓存）
     * @param string $class 序列化器类名
     * @return SerializerInterface
     */
    protected function getSerializer($class): SerializerInterface
    {
        if (!isset($this->serializerObjs[$class])) {
            $this->serializerObjs[$class] = new $class;
            if (!$this->serializerObjs[$class] instanceof SerializerInterface) {
                throw new CacheException("serializer[{$class}] must be of type " . SerializerInterface::class);
            }
        }
        return $this->serializerObjs[$class];
    }

    /**
     * 校验缓存层级配置：不能为空，不能有重复的 driver
     * @param CacheLevel[] $levels
     */
    protected function validateLevels(array $levels): void
    {
        if (empty($levels)) {
            throw new CacheException('levels is empty');
        }
        $driverClasses = array_column($levels, 'driver');
        if (count($driverClasses) !== count(array_unique($driverClasses))) {
            throw new CacheException('levels 中不能配置相同的 driver');
        }
    }

    /**
     * 获取缓存 key，为空时抛出异常
     * @return string
     */
    protected function getKey(AbstractMultiCache $cache): string
    {
        if (empty($cache->key)) {
            throw new CacheException("key is empty");
        }
        return $cache->key;
    }

    /**
     * 构建分布式锁的 key
     * @param string $prefix key 前缀
     * @param string $key 缓存 key
     * @param mixed $keyParams key 参数
     * @return string
     */
    protected function buildLockKey($prefix, $key, $keyParams): string
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
        return $key . '_lock';
    }

    /**
     * 校验缓存数据的版本号，版本不匹配则视为未命中
     * @param AbstractMultiCache $cache
     * @param mixed &$data 校验通过后会剥离版本包装，只保留原始数据
     * @return bool
     */
    protected function checkDataVersion(AbstractMultiCache $cache, &$data)
    {
        if (!$cache->addVersion) {
            return true;
        }
        if (isset($data['cachehub_version']) && $data['cachehub_version'] == $cache->version) {
            $data = $data['data'];
            return true;
        }
        return false;
    }

    /**
     * 给数据包装版本号
     * @param AbstractMultiCache $cache
     * @param mixed $data 原始数据
     * @return mixed 包装后的数据，或未开启版本时返回原数据
     */
    protected function addDataVersion(AbstractMultiCache $cache, $data)
    {
        if (!$cache->addVersion) {
            return $data;
        }
        return [
            'cachehub_version' => $cache->version,
            'data' => $data,
        ];
    }

    /**
     * 将空值标记（nullValue）写入缓存，防止缓存穿透
     * @return bool
     */
    protected function cacheNullValue(AbstractMultiCache $cache, AbstractDriver $driver, $key, $nullTtl)
    {
        if (!$cache->isCacheNull) {
            return true;
        }
        $nullTtl = intval($nullTtl);
        if (empty($nullTtl) || $nullTtl <= 0) {
            $nullTtl = CacheHub::DEFAULT_NULL_TTL;
        }
        return (bool)$driver->set($key, $cache->nullValue, $nullTtl);
    }

    /**
     * 判断缓存值是否为空值标记（nullValue）
     * @param AbstractMultiCache $cache
     * @param mixed $value 从缓存取出的值
     * @return bool
     */
    protected function isNullValue(AbstractMultiCache $cache, $value)
    {
        return $value === $cache->nullValue;
    }

    /**
     * 解析缓存数据，判定命中状态
     * @param AbstractMultiCache $cache
     * @param mixed $data 从缓存取出的原始数据
     * @return array{0: bool, 1: mixed} [是否命中, 解析后的数据]
     */
    protected function resolveCacheData(AbstractMultiCache $cache, $data)
    {
        if (!Utils::checkEmpty($data) && $data !== '' && $this->checkDataVersion($cache, $data)) {
            return [true, $data];
        }

        if ($this->isNullValue($cache, $data)) {
            return [true, null];
        }

        return [false, null];
    }

    /**
     * 将 build 结果写入缓存，空数据则写入空值标记
     * @return bool
     */
    protected function setBuildData(AbstractMultiCache $cache, AbstractDriver $driver, $key, $data, $ttl, $nullTtl)
    {
        if ($data === '' || Utils::checkEmpty($data)) {
            $data = null;
            return $this->cacheNullValue($cache, $driver, $key, $nullTtl);
        }

        $ttl = intval($ttl);
        if ($ttl == 0 || $ttl < 0) {
            $ttl = CacheHub::DEFAULT_TTL;
        }
        $data = $this->addDataVersion($cache, $data);
        return (bool)$driver->set($key, $data, $ttl);
    }

    /**
     * 加锁场景下尝试获取缓存数据：获取锁则放行 build，未获取锁则等待其他进程写入
     * @return array{0: bool, 1: mixed} [是否命中, 数据]
     */
    protected function lockGetData(AbstractMultiCache $cache, AbstractDriver $driver, $key, $keyParams, &$stack): array
    {
        if (!$cache->buildLock || empty($cache->buildWaitCount) || $cache->buildWaitCount <= 0) {
            return [false, null];
        }
        if (empty($this->locker)) {
            throw new CacheException('locker is empty');
        }
        $lockKey = $this->buildLockKey($this->prefix, $this->getKey($cache), $keyParams);
        $lockExpireTime = (int)round($cache->buildWaitCount * $cache->buildWaitTime / 1000) + 10;

        if ($this->locker->tryLock($lockKey, 1, $lockExpireTime)) {
            Utils::stackDefer($stack, function () use ($lockKey) {
                $this->locker->unLock($lockKey);
            });
            return [false, null];
        }

        for ($i = 0; $i < $cache->buildWaitCount; $i++) {
            $data = $driver->get($key);
            list($parseRet, $data) = $this->resolveCacheData($cache, $data);
            if ($parseRet) {
                return [true, $data];
            }

            $isLocked = $this->locker->isLocked($lockKey);
            if (!$isLocked) {
                return [false, null];
            }
        }

        if ($cache->buildWaitMod == 2) {
            throw new CacheException("build data timeout");
        }

        return [false, null];
    }

    /**
     * @return array{data: mixed, dataFrom: string}
     */
    public function get(AbstractMultiCache $cache, $keyParams = '', $refresh = false): array
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);
        $setDrivers = [];
        $len = count($levels);
        $index = 0;
        $data = null;
        $get = false;
        $dataFrom = '';

        if (!$refresh) {
            foreach ($levels as $level) {
                $index++;
                $driver = $this->getDriver($level);
                $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);

                $data = $driver->get($key);
                list($get, $data) = $this->resolveCacheData($cache, $data);
                if ($get) {
                    $dataFrom = $level->driver;
                    break;
                }

                // 最后一级缓存
                if ($index == $len && $driver->getCanLock()) {
                    $stack = new \SplStack();
                    list ($get, $data) = $this->lockGetData($cache, $driver, $key, $keyParams, $stack);
                    if ($get) {
                        $dataFrom = $level->driver;
                        break;
                    }
                }

                $setDrivers[] = [
                    'driver_class' => $level->driver,
                    'driver' => $driver,
                    'key' => $key,
                    'ttl' => $level->ttl,
                    'null_ttl' => $level->nullTtl,
                ];
            }
        } else {
            foreach ($levels as $level) {
                $driver = $this->getDriver($level);
                $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);

                $setDrivers[] = [
                    'driver_class' => $level->driver,
                    'driver' => $driver,
                    'key' => $key,
                    'ttl' => $level->ttl,
                    'null_ttl' => $level->nullTtl,
                ];
            }
        }

        if (!$get) {
            $data = $cache->build($keyParams);
            $dataFrom = 'build';
        }
        $setLen = count($setDrivers);
        if ($setLen > 0) {
            for ($i = $setLen - 1; $i >= 0; $i--) {
                /** @var AbstractDriver $driver */
                $driver = $setDrivers[$i]['driver'];
                $key = $setDrivers[$i]['key'];
                $driverClass = $setDrivers[$i]['driver_class'];
                $ret = $this->setBuildData($cache, $driver, $key, $data, $setDrivers[$i]['ttl'], $setDrivers[$i]['null_ttl']);
                if (!$ret) {
                    $this->logger and $this->logger->error("key:{$key}, {$driverClass} fail to set");
                } else {
                    $this->logger and $this->logger->debug("key:{$key}, {$driverClass} set successfully");
                }
            }
        }

        return ['data' => $cache->wrapData($data), 'dataFrom' => $dataFrom];
    }

    /**
     * @return array{data: array, dataFrom: array}
     */
    public function multiGet(AbstractMultiCache $cache, array $keyParamsArr): array
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);

        $keyParamsArrTmp = $keyParamsArr;
        $result = [];
        $setDrivers = [];
        $dataFrom = [];
        foreach ($levels as $level) {
            $driver = $this->getDriver($level);

            $keyArr = [];
            $keyMap = [];
            foreach ($keyParamsArrTmp as $keyParams) {
                $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);
                $keyArr[] = $key;
                $keyMap[$key] = $keyParams;
            }

            $keyParamsArrTmp = [];
            $dataArr = $driver->multiGet($keyArr);
            $emptyKeyArr = [];
            foreach ($dataArr as $dKey => $value) {
                list($get, $value) = $this->resolveCacheData($cache, $value);
                if (!$get) {
                    $keyParamsArrTmp[] = $keyMap[$dKey];
                    $emptyKeyArr[$dKey] = $keyMap[$dKey];
                } else {
                    $result[$keyMap[$dKey]] = $value;
                    $dataFrom[$keyMap[$dKey]] = $level->driver;
                }
            }

            if (!empty($emptyKeyArr)) {
                $setDrivers[] = [
                    'driver_class' => $level->driver,
                    'driver' => $driver,
                    'key_arr' => $emptyKeyArr,
                    'ttl' => $level->ttl,
                    'null_ttl' => $level->nullTtl,
                ];
            }
        }

        if (!empty($keyParamsArrTmp)) {
            $data = $cache->multiBuild($keyParamsArrTmp);
            foreach ($data as $keyParams => $vv) {
                $dataFrom[$keyParams] = 'build';
                $result[$keyParams] = $vv;
            }
        }

        $len = count($setDrivers);
        if ($len > 0) {
            for ($i = $len - 1; $i >= 0; $i--) {
                /** @var AbstractDriver $driver */
                $driver = $setDrivers[$i]['driver'];
                $keyArr = $setDrivers[$i]['key_arr'];

                $saveData = [];
                foreach ($keyArr as $key => $keyParams) {
                    $data = $result[$keyParams] ?? null;
                    if (!Utils::checkEmpty($data)) {
                        $saveData[$key] = $this->addDataVersion($cache, $data);
                    }
                }

                if (!empty($saveData)) {
                    $ttl = intval($setDrivers[$i]['ttl'] ?? 0);
                    if ($ttl == 0 || $ttl < 0) {
                        $ttl = CacheHub::DEFAULT_TTL;
                    }
                    $driver->multiSet($saveData, $ttl);
                }
            }
        }

        $arr = [];
        foreach ($keyParamsArr as $key) {
            if (isset($result[$key])) {
                $arr[$key] = $cache->wrapData($result[$key]);
            } else {
                $arr[$key] = null;
            }
        }
        return ['data' => $arr, 'dataFrom' => $dataFrom];
    }

    public function update(AbstractMultiCache $cache, $keyParams = ''): int
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);
        $data = $cache->build($keyParams);
        $successNum = 0;
        $levels = array_reverse($levels);
        foreach ($levels as $level) {
            $driver = $this->getDriver($level);
            $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);
            $ret = $this->setBuildData($cache, $driver, $key, $data, $level->ttl, $level->nullTtl);
            if (!$ret) {
                $this->logger and $this->logger->error("key:{$key}, " . $level->driver . " fail to set");
            } else {
                $successNum++;
                $this->logger and $this->logger->debug("key:{$key}, " . $level->driver . " set successfully");
            }
        }

        return $successNum;
    }

    /**
     * 当只有单级缓存时，透传驱动方法
     */
    public function callDriverMethod(AbstractMultiCache $cache, string $name, array $arguments)
    {
        $levels = $cache->getLevels();
        if (count($levels) == 1) {
            $driver = $this->getDriver($levels[0]);
            return call_user_func_array([$driver, $name], $arguments);
        }
        throw new \Exception("{$name} is unsupported");
    }

}
