<?php

class RedisPool
{

    public function __call($name, $arguments)
    {
        $redis = TestHelper::getRedis();
        return call_user_func_array([$redis, $name], $arguments);
    }

}