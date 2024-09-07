<?php
// +----------------------------------------------------------------------
// | HTTP路由注册
// +----------------------------------------------------------------------

declare (strict_types=1);

use Viswoole\HttpServer\Request;
use Viswoole\HttpServer\Response;
use Viswoole\Router\Route;

Route::get('/', function (Request $request, Response $response) {
  return $response->send('<h1>Hello Viswoole. #' . rand(1000, 9999) . '</h1>');
});
Route::miss(
  function (Response $response) {
    return $response->status(404)->json(['message' => 'Not Found']);
  }
);
