<?php
// +----------------------------------------------------------------------
// | HTTP路由注册
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\HttpServer\Request;
use Viswoole\HttpServer\Response;
use Viswoole\Router\Facade\Router;

// 这是路由系统生成的默认路由示例，访问根目录时返回一个随机数字
Router::get('/', function (Request $request, Response $response) {
  return $response->send('<h1>Hello Viswoole. #' . rand(1000, 9999) . '</h1>');
})->setTitle('Welcome');

// 定义路由匹配失败的处理器
Router::miss(function (Request $request, Response $response) {
  return $response->status(404)->json([
    'message' => 'routing resource not found',
    'code' => 404
  ]);
});
