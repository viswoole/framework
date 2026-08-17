<?php
// +----------------------------------------------------------------------
// | 缓存配置
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Cache\Facade\Cache;

return [
  // 默认通道
  'default' => env('cache.store', 'file'),
  // 通道列表
  'stores' => [
    // 驱动类需继承 Viswoole\Cache\Driver，
    // 或实现 Viswoole\Cache\Contract\CacheDriverInterface 接口。
    // 内置了两种缓存驱动：File、Redis
    // 数组形式配置 'name' => ['driver' => Driver::class, 'options' => []]，options 为驱动类的构造参数列表
    // 字符串形式配置 'name' => Driver::class
    // 实例形式配置 'name' => new \Viswoole\Cache\Driver\Redis(env('REDIS_HOST', '127.0.0.1'))
    'file' => Cache::FILE_DRIVER
  ]
];
