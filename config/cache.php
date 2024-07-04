<?php
// +----------------------------------------------------------------------
// | 缓存配置
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Driver\Redis;

return [
  // 默认通道
  'default' => env('cache.store', 'file'),
  // 通道列表
  'stores' => [
    // 驱动类需继承Viswoole\Cache\Driver，
    // 或实现Viswoole\Cache\Contract\CacheDriverInterface接口。
    // 通道支持给定驱动类完全限定名称或驱动实例，例如new Viswoole\Cache\Driver\Redis()
    'file' => File::class,
    'redis' => Redis::class
  ]
];
