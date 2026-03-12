<?php
spl_autoload_register(function($class) {
    $baseDir = __DIR__;
    $paths = [
        $baseDir . '/' . $class . '.php',
        $baseDir . '/Fixture/' . $class . '.php',
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require($path);
            return;
        }
    }
});

\Swoole\Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    'log_level' => SWOOLE_LOG_WARNING,
]);
