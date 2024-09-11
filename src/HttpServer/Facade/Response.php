<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\HttpServer\Facade;

use JsonSerializable;
use Override;
use Viswoole\Core\Facade;
use Viswoole\HttpServer\Contract\ResponseInterface;

/**
 * HTTP响应对象
 *
 * @method static ResponseInterface status(int $http_status_code, string $reasonPhrase = '') 设置响应状态
 * @method static ResponseInterface header(string $key, string $value, bool $format = true) 设置响应标头
 * @method static ResponseInterface create(object|array|int $server = -1, int $fd = -1) 创建新响应对象
 * @method static ResponseInterface setStatusCode(int $http_status_code, string $reasonPhrase = '') 发送HTTP状态
 * @method static bool send(?string $content = null) 发送响应
 * @method static bool end(?string $content = null) 发送响应
 * @method static bool isWritable() 判断是否已结束
 * @method static ResponseInterface setHeader(string $key, string $value, bool $format = true) 设置响应头
 * @method static bool trailer(string $key, string $value) 在响应末尾追加header，仅HTTP2有效
 * @method static bool redirect(string $uri, int $http_code = 302) 重定向
 * @method static ResponseInterface write(string $data) 启用 Http Chunk 分段向浏览器发送相应内容。
 * @method static ResponseInterface json(JsonSerializable|array $data) 将数据以json格式设置为响应内容
 * @method static ResponseInterface setContentType(string $contentType, string $charset = 'utf-8') 设置响应内容类型
 * @method static ResponseInterface setContent(string $content) 设置响应内容
 * @method static bool sendfile(string $filePath, int $offset = 0, int $length = 0, ?string $fileMimeType = null) 发送文件
 * @method static array getHeader() 获取响应头
 * @method static ResponseInterface echo (bool $echo = true) 设置是否输出至控制台
 * @method static ResponseInterface rawCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = '') 设置cookie
 * @method static ResponseInterface cookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = '') 设置cookie
 * @method static ResponseInterface detach() 分离响应对象。
 * @method static ResponseInterface setHeaders(array $headers) 批量设置响应标头
 * @method static \Swoole\Http\Response getSwooleResponse() 获取swoole响应对象
 *
 * 优化命令：php viswoole optimize:facade \\Viswoole\\HttpServer\\Facades\\Response
 */
class Response extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\HttpServer\Response::class;
  }
}
