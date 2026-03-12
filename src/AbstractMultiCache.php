<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

abstract class AbstractMultiCache
{

    /** 缓存key */
    public $key = '';

    /** 当数据为空时存的值 */
    public $nullValue = '';

    /** 是否缓存空值 */
    public $isCacheNull = true;

    /** 是否给数据添加版本号 */
    public $addVersion = false;

    /**  数据版本号, 当addVersion=true时生效 */
    public $version = 1;

    /** 构建数据, 当遇到锁重试次数, 需要buildLock=true */
    public $buildWaitCount = 3;

    /** 构建数据, 当遇到锁重试时间间隔, 毫秒 */
    public $buildWaitTime = 100;

    /** 构建数据超时处理模式, 1:放行到build, 2:抛出异常 */
    public $buildWaitMod = 1;

    /** build数据时是否加锁 */
    public $buildLock = false;


    abstract public function getCacheList(): array;

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
