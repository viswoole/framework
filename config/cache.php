<?php
// +----------------------------------------------------------------------
// | 缓存配置
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Driver\Redis;
use Viswoole\Cache\RedisConfig;

return [
  // 默认通道
  'default' => env('cache.store', 'file'),
  // 通道列表
  'stores' => [
    // 驱动类需继承Viswoole\Cache\Driver，
    // 或实现Viswoole\Cache\Contract\CacheDriverInterface接口。
    // 通道支持给定驱动类完全限定名称或数组形式配置，Options接受传递给驱动类的构造参数。
    'file' => File::class,
    'redis' => [
      'driver' => Redis::class,
      'options' => new RedisConfig(env('REDIS_HOST', '127.0.0.1'))
    ],
  ]
];
