<?php
declare(strict_types=1);

namespace Haoa\CacheHub\Serializer;

class RawSerializer implements SerializerInterface
{

    public function encode($data)
    {
        return $data;
    }

    public function decode($data)
    {
        return $data;
    }

}
