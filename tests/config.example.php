<?php
declare(strict_types=1);

/**
 * 测试配置文件
 *
 * 使用方法：
 * 1. 复制此文件为 config.php: cp config.example.php config.php
 * 2. 修改 config.php 中的配置为你实际的 Redis 配置
 */

return [
    'redis' => [
        'host' => 'redis',
        'port' => 6379,
        'db'   => 3,
    ],
];
