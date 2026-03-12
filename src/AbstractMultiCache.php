<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

abstract class AbstractMultiCache
{

    /** 缓存key */
    public $key = '';

    /** 空值标记，build() 返回空数据时写入缓存的哨兵值。注意：不要将此值作为正常业务数据 */
    public $nullValue = '';

    /** 是否缓存空值 */
    public $cacheNull = true;

    /** 是否给数据添加版本号 */
    public $versioned = false;

    /** 数据版本号，当 versioned=true 时生效 */
    public $version = 1;

    /** 是否在 build 时加锁，防止并发重复构建 */
    public $lockEnabled = false;

    /** 加锁等待重试次数 */
    public $lockRetryCount = 3;

    /** 加锁等待重试间隔，毫秒 */
    public $lockRetryInterval = 100;

    /** 锁等待超时处理模式：1=放行到 build，2=抛出异常 */
    public $lockTimeoutMode = 1;


    /**
     * 定义缓存层级
     * @return CacheLevel[]
     */
    abstract public function getLevels(): array;

    /**
     * 构建数据
     * @param mixed $params
     * @return mixed
     */
    abstract public function build($params);

    /**
     * 拿到数据后, 包装数据再返回
     * @param mixed $data
     * @return mixed
     */
    public function wrapData($data)
    {
        return $data;
    }

    /**
     * 批量构建数据
     * @param array $params
     * @return array 以key为下标的数组
     */
    public function multiBuild(array $params): array
    {
        throw new \Exception("multiBuild is not implemented");
    }

}
