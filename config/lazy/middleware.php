<?php
// +----------------------------------------------------------------------
// | 全局中间件定义
// +----------------------------------------------------------------------

declare (strict_types=1);

use App\Middlewares\AllowCrossDomain;
use Viswoole\Core\Facade\Middleware;

// 该中间件用于解决跨域请求问题
Middleware::register(AllowCrossDomain::class);
