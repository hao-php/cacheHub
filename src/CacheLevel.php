<?php
declare(strict_types=1);

namespace Haoa\CacheHub;

use Haoa\CacheHub\Serializer\RawSerializer;

class CacheLevel
{

    public string $driver;

    public int $ttl;

    public string $serializer;

    public int $nullTtl;

    public $driverHandler;

    public function __construct(
        string $driver,
        int $ttl = 300,
        string $serializer = RawSerializer::class,
        int $nullTtl = 0,
        $driverHandler = null
    ) {
        $this->driver = $driver;
        $this->ttl = $ttl;
        $this->serializer = $serializer;
        $this->nullTtl = $nullTtl;
        $this->driverHandler = $driverHandler;
    }

}
