<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Common\Common;
use Haoa\CacheHub\Driver\BaseDriver;
use Haoa\CacheHub\Exception\Exception;
use Haoa\CacheHub\Locker\Locker;
use Haoa\CacheHub\Serializer\OriginalSerializer;

class CacheEngine
{

    protected Container $container;

    /** @var Locker|null */
    protected $locker;

    protected string $prefix;

    public function __construct(Container $container, $locker, string $prefix)
    {
        $this->container = $container;
        $this->locker = $locker;
        $this->prefix = $prefix;
    }

    protected function getKey(AbstractMultiCache $cache): string
    {
        if (empty($cache->key)) {
            throw new Exception("key is empty");
        }
        return $cache->key;
    }

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

    protected function cacheEmptyValue(AbstractMultiCache $cache, BaseDriver $driver, $key, $nullTtl)
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

    protected function checkEmptyValue(AbstractMultiCache $cache, $value)
    {
        if ($value === $cache->nullValue) {
            return true;
        }
        return false;
    }

    protected function parseCacheData(AbstractMultiCache $cache, $data)
    {
        if (!Common::checkEmpty($data) && $data !== '' && $this->checkDataVersion($cache, $data)) {
            return [true, $data];
        }

        if ($this->checkEmptyValue($cache, $data)) {
            return [true, null];
        }

        return [false, null];
    }

    protected function setBuildData(AbstractMultiCache $cache, BaseDriver $driver, $key, $data, $ttl, $nullTtl)
    {
        if ($data === '' || Common::checkEmpty($data)) {
            $data = null;
            return $this->cacheEmptyValue($cache, $driver, $key, $nullTtl);
        }

        $ttl = intval($ttl);
        if ($ttl == 0 || $ttl < 0) {
            $ttl = CacheHub::DEFAULT_TTL;
        }
        $data = $this->addDataVersion($cache, $data);
        return (bool)$driver->set($key, $data, $ttl);
    }

    protected function lockGetData(AbstractMultiCache $cache, BaseDriver $driver, $key, $keyParams, &$stack): array
    {
        if (!$cache->buildLock || empty($cache->buildWaitCount) || $cache->buildWaitCount <= 0) {
            return [false, null];
        }
        if (empty($this->locker)) {
            throw new Exception('locker is empty');
        }
        $lockKey = $this->locker->getLockKey($this->prefix, $this->getKey($cache), $keyParams);
        $lockExpireTime = (int)round($cache->buildWaitCount * $cache->buildWaitTime / 1000) + 10;

        if ($this->locker->tryLock($lockKey, 1, $lockExpireTime)) {
            Common::stackDefer($stack, function () use ($lockKey) {
                $this->locker->unLock($lockKey);
            });
            return [false, null];
        }

        for ($i = 0; $i < $cache->buildWaitCount; $i++) {
            $data = $driver->get($key);
            list($parseRet, $data) = $this->parseCacheData($cache, $data);
            if ($parseRet) {
                return [true, $data];
            }

            $isLocked = $this->locker->isLocked($lockKey);
            if (!$isLocked) {
                return [false, null];
            }
        }

        if ($cache->buildWaitMod == 2) {
            throw new Exception("build data timeout");
        }

        return [false, null];
    }

    /**
     * @return array{data: mixed, dataFrom: string}
     */
    public function get(AbstractMultiCache $cache, $keyParams = '', $refresh = false): array
    {
        if (empty($cache->getCacheList())) {
            throw new \Exception('cacheList is empty');
        }
        $setDrivers = [];
        $len = count($cache->getCacheList());
        $index = 0;
        $data = null;
        $get = false;
        $dataFrom = '';

        if (!$refresh) {
            foreach ($cache->getCacheList() as $v) {
                $index++;
                if (empty($v['driver'])) {
                    throw new \Exception('driver is empty');
                }
                $serializerClass = $v['serializer'] ?: OriginalSerializer::class;
                $driver = $this->container->getDriver($v['driver'], $serializerClass, $v['driver_handler'] ?? null);

                $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);

                $data = $driver->get($key);
                list($get, $data) = $this->parseCacheData($cache, $data);
                if ($get) {
                    $dataFrom = $v['driver'];
                    break;
                }

                // 最后一级缓存
                if ($index == $len && $driver->getCanLock()) {
                    $stack = new \SplStack();
                    list ($get, $data) = $this->lockGetData($cache, $driver, $key, $keyParams, $stack);
                    if ($get) {
                        $dataFrom = $v['driver'];
                        break;
                    }
                }

                $setDrivers[] = [
                    'driver_class' => $v['driver'],
                    'driver' => $driver,
                    'key' => $key,
                    'ttl' => $v['ttl'] ?? 0,
                    'null_ttl' => $v['null_ttl'] ?? 0,
                ];
            }
        } else {
            foreach ($cache->getCacheList() as $v) {
                if (empty($v['driver'])) {
                    throw new \Exception('driver is empty');
                }
                $serializerClass = $v['serializer'] ?: OriginalSerializer::class;
                $driver = $this->container->getDriver($v['driver'], $serializerClass, $v['driver_handler'] ?? null);
                $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);

                $setDrivers[] = [
                    'driver_class' => $v['driver'],
                    'driver' => $driver,
                    'key' => $key,
                    'ttl' => $v['ttl'] ?? 0,
                    'null_ttl' => $v['null_ttl'] ?? 0,
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
                /** @var BaseDriver $driver */
                $driver = $setDrivers[$i]['driver'];
                $key = $setDrivers[$i]['key'];
                $driverClass = $setDrivers[$i]['driver_class'];
                $ret = $this->setBuildData($cache, $driver, $key, $data, $setDrivers[$i]['ttl'], $setDrivers[$i]['null_ttl']);
                if (!$ret) {
                    $this->container->getLogger() and $this->container->getLogger()->error("key:{$key}, {$driverClass} fail to set");
                } else {
                    $this->container->getLogger() and $this->container->getLogger()->debug("key:{$key}, {$driverClass} set successfully");
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
        if (empty($cache->getCacheList())) {
            throw new \Exception('cacheList is empty');
        }

        $keyParamsArrTmp = $keyParamsArr;
        $result = [];
        $setDrivers = [];
        $dataFrom = [];
        foreach ($cache->getCacheList() as $v) {
            if (empty($v['driver'])) {
                throw new \Exception('driver is empty');
            }
            $serializerClass = $v['serializer'] ?: OriginalSerializer::class;
            $driver = $this->container->getDriver($v['driver'], $serializerClass, $v['driver_handler'] ?? null);

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
                list($get, $value) = $this->parseCacheData($cache, $value);
                if (!$get) {
                    $keyParamsArrTmp[] = $keyMap[$dKey];
                    $emptyKeyArr[$dKey] = $keyMap[$dKey];
                } else {
                    $result[$keyMap[$dKey]] = $value;
                    $dataFrom[$keyMap[$dKey]] = $v['driver'];
                }
            }

            if (!empty($emptyKeyArr)) {
                $setDrivers[] = [
                    'driver_class' => $v['driver'],
                    'driver' => $driver,
                    'key_arr' => $emptyKeyArr,
                    'ttl' => $v['ttl'] ?? 0,
                    'null_ttl' => $v['null_ttl'] ?? 0,
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
                /** @var BaseDriver $driver */
                $driver = $setDrivers[$i]['driver'];
                $keyArr = $setDrivers[$i]['key_arr'];

                $saveData = [];
                foreach ($keyArr as $key => $keyParams) {
                    $data = $result[$keyParams] ?? null;
                    if (!Common::checkEmpty($data)) {
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
        $data = $cache->build($keyParams);
        $successNum = 0;
        $cacheList = $cache->getCacheList();
        $cacheList = array_reverse($cacheList);
        foreach ($cacheList as $v) {
            if (empty($v['driver'])) {
                throw new \Exception('driver is empty');
            }
            $serializerClass = $v['serializer'] ?: OriginalSerializer::class;
            $driver = $this->container->getDriver($v['driver'], $serializerClass, $v['driver_handler'] ?? null);

            $key = $driver->buildKey($this->prefix, $this->getKey($cache), $keyParams);
            $ret = $this->setBuildData($cache, $driver, $key, $data, $v['ttl'] ?? 0, $v['null_ttl'] ?? 0);
            if (!$ret) {
                $this->container->getLogger() and $this->container->getLogger()->error("key:{$key}, " . $v['driver'] . " fail to set");
            } else {
                $successNum++;
                $this->container->getLogger() and $this->container->getLogger()->debug("key:{$key}, " . $v['driver'] . " set successfully");
            }
        }

        return $successNum;
    }

    /**
     * 当只有单级缓存时，透传驱动方法
     */
    public function callDriverMethod(AbstractMultiCache $cache, string $name, array $arguments)
    {
        if (count($cache->getCacheList()) == 1) {
            $cacheList = $cache->getCacheList();
            $v = reset($cacheList);
            $serializerClass = $v['serializer'] ?? OriginalSerializer::class;
            $driver = $this->container->getDriver($v['driver'], $serializerClass, $v['driver_handler'] ?? null);

            return call_user_func_array([$driver, $name], $arguments);
        }
        throw new \Exception("{$name} is unsupported");
    }

}
