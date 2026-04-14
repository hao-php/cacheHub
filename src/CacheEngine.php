<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Common\Utils;
use Haoa\CacheHub\Driver\AbstractDriver;
use Haoa\CacheHub\Exception\CacheException;
use Haoa\CacheHub\Locker\LockInterface;
use Haoa\CacheHub\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;

class CacheEngine
{

    /** @var LockInterface|null */
    protected $lock;

    protected string $prefix;

    protected ?LoggerInterface $logger;

    protected array $drivers = [];

    protected array $serializers = [];

    public function __construct($lock, string $prefix, ?LoggerInterface $logger = null)
    {
        $this->lock = $lock;
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
        if (!isset($this->drivers[$class])) {
            $this->drivers[$class] = new $class;
            if (!$this->drivers[$class] instanceof AbstractDriver) {
                throw new CacheException("driver[{$class}] must be of type " . AbstractDriver::class);
            }
            $this->drivers[$class]->setHandler($level->driverHandler);
            $this->drivers[$class]->setSerializer($this->getSerializer($level->serializer));
        }
        return $this->drivers[$class];
    }

    /**
     * 获取序列化器实例（带缓存）
     * @param string $class 序列化器类名
     * @return SerializerInterface
     */
    protected function getSerializer($class): SerializerInterface
    {
        if (!isset($this->serializers[$class])) {
            $this->serializers[$class] = new $class;
            if (!$this->serializers[$class] instanceof SerializerInterface) {
                throw new CacheException("serializer[{$class}] must be of type " . SerializerInterface::class);
            }
        }
        return $this->serializers[$class];
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
     * 判断数据是否为空（null、false、空字符串）
     * @param mixed $data
     * @return bool
     */
    protected function isEmptyData($data): bool
    {
        return $data === '' || $data === null || $data === false;
    }

    /**
     * 构建分布式锁的 key
     * @param string $prefix key 前缀
     * @param string $key 缓存 key
     * @param mixed $keyParams key 参数
     * @return string
     */
    protected function makeLockKey($prefix, $key, $keyParams): string
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
     * 校验并剥离版本号包装，版本不匹配则视为未命中
     * @param AbstractMultiCache $cache
     * @param mixed &$data 校验通过后会剥离版本包装，只保留原始数据
     * @return bool
     */
    protected function stripDataVersion(AbstractMultiCache $cache, &$data)
    {
        if (!$cache->versioned) {
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
    protected function wrapDataVersion(AbstractMultiCache $cache, $data)
    {
        if (!$cache->versioned) {
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
    protected function cacheNullValue(AbstractMultiCache $cache, AbstractDriver $driver, $key, int $nullTtl)
    {
        if (!$cache->cacheNull) {
            return true;
        }
        if ($nullTtl <= 0) {
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
        if (!$this->isEmptyData($data) && $this->stripDataVersion($cache, $data)) {
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
    protected function saveBuildResult(AbstractMultiCache $cache, AbstractDriver $driver, $key, $data, int $ttl, int $nullTtl)
    {
        if ($this->isEmptyData($data)) {
            return $this->cacheNullValue($cache, $driver, $key, $nullTtl);
        }

        if ($ttl <= 0) {
            $ttl = CacheHub::DEFAULT_TTL;
        }
        $data = $this->wrapDataVersion($cache, $data);
        return (bool)$driver->set($key, $data, $ttl);
    }

    /**
     * 加锁场景下尝试获取缓存数据：获取锁则放行 build，未获取锁则等待其他进程写入
     * @return array{0: bool, 1: mixed} [是否命中, 数据]
     */
    protected function tryLockAndWait(AbstractMultiCache $cache, AbstractDriver $driver, $key, $keyParams, &$stack): array
    {
        if (!$cache->lockEnabled || $cache->lockRetryCount <= 0) {
            return [false, null];
        }
        if (empty($this->lock)) {
            throw new CacheException('lock is empty');
        }
        $lockKey = $this->makeLockKey($this->prefix, $this->getKey($cache), $keyParams);
        $lockExpireTime = (int)round($cache->lockRetryCount * $cache->lockRetryInterval / 1000) + 10;

        if ($this->lock->tryLock($lockKey, 1, $lockExpireTime)) {
            Utils::scopeDefer($stack, function () use ($lockKey) {
                $this->lock->unlock($lockKey);
            });
            return [false, null];
        }

        $sleepUs = $cache->lockRetryInterval * 1000;
        for ($i = 0; $i < $cache->lockRetryCount; $i++) {
            usleep($sleepUs);

            $data = $driver->get($key);
            list($hit, $data) = $this->resolveCacheData($cache, $data);
            if ($hit) {
                return [true, $data];
            }

            if (!$this->lock->isLocked($lockKey)) {
                return [false, null];
            }
        }

        if ($cache->lockTimeoutMode == 2) {
            throw new CacheException("build data timeout");
        }

        return [false, null];
    }

    /**
     * 获取缓存数据，未命中时自动 build 并回写
     * @param int $skipLevels 跳过前面几级缓存，0=不跳过，等于层级数时直接走 build
     * @return array{data: mixed, source: string}
     */
    public function get(AbstractMultiCache $cache, $keyParams = '', int $skipLevels = 0): array
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);
        $len = count($levels);
        $data = null;
        $hit = false;
        $source = '';

        // 跳过的层级需要回写，先构建跳过的层级列表
        $startIndex = min($skipLevels, $len);
        $skippedWrites = [];
        for ($i = 0; $i < $startIndex; $i++) {
            $level = $levels[$i];
            $driver = $this->getDriver($level);
            $key = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);
            $skippedWrites[] = [
                'driver_class' => $level->driver,
                'driver' => $driver,
                'key' => $key,
                'ttl' => $level->ttl,
                'nullTtl' => $level->nullTtl,
            ];
        }

        // 从 startIndex 开始遍历，检查缓存
        $pendingWrites = [];
        for ($i = $startIndex; $i < $len; $i++) {
            $level = $levels[$i];
            $driver = $this->getDriver($level);
            $key = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);

            $data = $driver->get($key);
            list($hit, $data) = $this->resolveCacheData($cache, $data);
            if ($hit) {
                $source = $level->driver;
                break;
            }

            // 最后一级缓存，尝试加锁
            if ($i == $len - 1 && $driver->canLock()) {
                $stack = new \SplStack();
                list($hit, $data) = $this->tryLockAndWait($cache, $driver, $key, $keyParams, $stack);
                if ($hit) {
                    $source = $level->driver;
                    break;
                }
            }

            $pendingWrites[] = [
                'driver_class' => $level->driver,
                'driver' => $driver,
                'key' => $key,
                'ttl' => $level->ttl,
                'nullTtl' => $level->nullTtl,
            ];
        }

        // 合并：跳过的层级在前面，保证回写顺序正确（从最后一级往前）
        $pendingWrites = array_merge($skippedWrites, $pendingWrites);

        if (!$hit) {
            $data = $cache->build($keyParams);
            $source = 'build';
        }

        for ($i = count($pendingWrites) - 1; $i >= 0; $i--) {
            $pw = $pendingWrites[$i];
            $ret = $this->saveBuildResult($cache, $pw['driver'], $pw['key'], $data, $pw['ttl'], $pw['nullTtl']);
            if (!$ret) {
                $this->logger and $this->logger->error("[get] key:{$pw['key']}, driver:{$pw['driver_class']}, failed to set");
            } else {
                $this->logger and $this->logger->debug("[get] key:{$pw['key']}, driver:{$pw['driver_class']}, set successfully");
            }
        }

        return ['data' => $cache->wrapData($data), 'source' => $source];
    }

    /**
     * 批量获取缓存数据，未命中的自动 multiBuild 并回写
     * @return array{data: array, source: array}
     */
    public function multiGet(AbstractMultiCache $cache, array $keyParamsArr): array
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);

        $remaining = $keyParamsArr;  // 尚未命中的 keyParams，逐级递减
        $result = [];                // keyParams => data，已命中的结果
        $source = [];                // keyParams => driver class|'build'，数据来源
        $pendingWrites = [];         // 需要回写的层级信息

        // 逐级查询，命中的记入 result，未命中的传给下一级
        foreach ($levels as $level) {
            $driver = $this->getDriver($level);

            // cacheKey => keyParams 映射，用于反查
            $keyMap = [];
            foreach ($remaining as $keyParams) {
                $cacheKey = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);
                $keyMap[$cacheKey] = $keyParams;
            }

            $remaining = [];
            $missedKeys = [];  // cacheKey => keyParams，本级未命中的
            foreach ($driver->multiGet(array_keys($keyMap)) as $cacheKey => $value) {
                list($hit, $value) = $this->resolveCacheData($cache, $value);
                if ($hit) {
                    $result[$keyMap[$cacheKey]] = $value;
                    $source[$keyMap[$cacheKey]] = $level->driver;
                } else {
                    $remaining[] = $keyMap[$cacheKey];
                    $missedKeys[$cacheKey] = $keyMap[$cacheKey];
                }
            }

            if (!empty($missedKeys)) {
                $pendingWrites[] = [
                    'driver_class' => $level->driver,
                    'driver' => $driver,
                    'missed_keys' => $missedKeys,
                    'ttl' => $level->ttl,
                    'nullTtl' => $level->nullTtl,
                ];
            }
        }

        // 剩余未命中的，调用 multiBuild 构建
        if (!empty($remaining)) {
            $builtData = $cache->multiBuild($remaining);
            foreach ($builtData as $keyParams => $value) {
                $result[$keyParams] = $value;
                $source[$keyParams] = 'build';
            }
        }

        // 从最后一级往前回写
        for ($i = count($pendingWrites) - 1; $i >= 0; $i--) {
            $pw = $pendingWrites[$i];
            /** @var AbstractDriver $driver */
            $driver = $pw['driver'];

            $writeItems = [];
            foreach ($pw['missed_keys'] as $cacheKey => $keyParams) {
                $data = $result[$keyParams] ?? null;
                if (!$this->isEmptyData($data)) {
                    $writeItems[$cacheKey] = $this->wrapDataVersion($cache, $data);
                }
            }

            if (!empty($writeItems)) {
                $ttl = $pw['ttl'];
                if ($ttl <= 0) {
                    $ttl = CacheHub::DEFAULT_TTL;
                }
                $driver->multiSet($writeItems, $ttl);
            }
        }

        // 按原始顺序组装输出，未命中的填 null
        $output = [];
        foreach ($keyParamsArr as $keyParams) {
            $output[$keyParams] = isset($result[$keyParams]) ? $cache->wrapData($result[$keyParams]) : null;
        }
        return ['data' => $output, 'source' => $source];
    }

    /**
     * 主动更新缓存：重新 build 并写入所有层级
     * @return int 成功写入的层级数
     */
    public function update(AbstractMultiCache $cache, $keyParams = ''): int
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);
        $data = $cache->build($keyParams);
        $successNum = 0;
        $levels = array_reverse($levels);
        foreach ($levels as $level) {
            $driver = $this->getDriver($level);
            $key = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);
            $ret = $this->saveBuildResult($cache, $driver, $key, $data, $level->ttl, $level->nullTtl);
            if (!$ret) {
                $this->logger and $this->logger->error("[update] key:{$key}, driver:{$level->driver}, failed to set");
            } else {
                $successNum++;
                $this->logger and $this->logger->debug("[update] key:{$key}, driver:{$level->driver}, set successfully");
            }
        }

        return $successNum;
    }

    /**
     * 当只有单级缓存时，透传驱动方法
     * 注意：$arguments[0] 应为 keyParams，会自动转换为完整 cache key
     */
    public function callDriverMethod(AbstractMultiCache $cache, string $name, array $arguments)
    {
        $levels = $cache->getLevels();
        if (count($levels) == 1) {
            $driver = $this->getDriver($levels[0]);
            $keyParams = $arguments[0] ?? '';
            $arguments[0] = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);
            return call_user_func_array([$driver, $name], $arguments);
        }
        throw new CacheException("{$name} is unsupported");
    }

    /**
     * 删除缓存：从所有层级逐级删除
     * @return int 成功删除的层级数
     */
    public function delete(AbstractMultiCache $cache, $keyParams = ''): int
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);
        $successNum = 0;
        foreach ($levels as $level) {
            $driver = $this->getDriver($level);
            $key = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);
            if ($driver->delete($key)) {
                $successNum++;
            }
        }
        return $successNum;
    }

    /**
     * 批量删除缓存：从所有层级逐级删除
     * @return array 每个层级成功删除的数量
     */
    public function multiDelete(AbstractMultiCache $cache, array $keyParamsArr): array
    {
        $levels = $cache->getLevels();
        $this->validateLevels($levels);
        $result = [];
        foreach ($levels as $level) {
            $driver = $this->getDriver($level);
            $keys = [];
            foreach ($keyParamsArr as $keyParams) {
                $keys[] = $driver->makeKey($this->prefix, $this->getKey($cache), $keyParams);
            }
            $result[$level->driver] = $driver->multiDelete($keys);
        }
        return $result;
    }

}
