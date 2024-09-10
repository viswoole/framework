<?php
// +----------------------------------------------------------------------
// | 全局中间件注册
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\Core\Facade\Middleware;
use Viswoole\Core\Middlewares\AllowCrossDomain;

// 该中间件用于解决跨域请求问题
Middleware::register(AllowCrossDomain::class);
