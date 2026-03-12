<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

class CacheProxy
{

    private CacheEngine $engine;

    private AbstractMultiCache $cache;

    /** @var string|array 数据来源 */
    private $source = [];

    public function __construct(CacheEngine $engine, AbstractMultiCache $cache)
    {
        $this->engine = $engine;
        $this->cache = $cache;
    }

    public function get($keyParams = '', $refresh = false)
    {
        $result = $this->engine->get($this->cache, $keyParams, $refresh);
        $this->source = $result['source'];
        return $result['data'];
    }

    public function multiGet(array $keyParamsArr): array
    {
        $result = $this->engine->multiGet($this->cache, $keyParamsArr);
        $this->source = $result['source'];
        return $result['data'];
    }

    public function update($keyParams = ''): int
    {
        return $this->engine->update($this->cache, $keyParams);
    }

    public function getSource(): string|array
    {
        return $this->source;
    }

    public function clearSource()
    {
        $this->source = [];
    }

    public function getConfig(): AbstractMultiCache
    {
        return $this->cache;
    }

    public function __get($name)
    {
        return $this->cache->$name;
    }

    public function __set($name, $value)
    {
        $this->cache->$name = $value;
    }

    public function __isset($name)
    {
        return isset($this->cache->$name);
    }

    public function __call($name, $arguments)
    {
        return $this->engine->callDriverMethod($this->cache, $name, $arguments);
    }

}
