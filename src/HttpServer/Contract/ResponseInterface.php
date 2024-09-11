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

namespace Viswoole\HttpServer\Contract;

use JsonSerializable;
use RuntimeException;
use Swoole\Http\Response as swooleResponse;

/**
 * 响应接口
 */
interface ResponseInterface
{
  /**
   * 创建新响应对象。
   *
   * 使用此方法前请务必调用 detach 方法将旧的 $response 对象分离，否则可能会造成对同一个请求发送两次响应内容。
   *
   * @access public
   * @param object|array|int $server Swoole\Server 或者 Swoole\Coroutine\Socket 对象，数组（数组只能有两个参数，第一个是 Swoole\Server 对象，第二个是 Swoole\Http\Request 对象），或者文件描述符。
   * @param int $fd 文件描述符。如果参数 $server 是 Swoole\Server 对象，$fd 是必填的
   * @return ResponseInterface 调用成功返回一个新的 ResponseInterface 对象
   * @throws RuntimeException 创建失败会抛出异常
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=create
   */
  public static function create(object|array|int $server = -1, int $fd = -1): ResponseInterface;

  /**
   * 获取Swoole\Http\Response响应对象
   *
   * @return swooleResponse
   */
  public function getSwooleResponse(): swooleResponse;

  /**
   * 批量设置响应标头
   *
   * @access public
   * @param array $headers
   * @return ResponseInterface
   */
  public function setHeaders(array $headers): ResponseInterface;

  /**
   * 快捷设置Content-Type响应头
   *
   * @access public
   * @param string $contentType 输出类型 例如 application/json
   * @param string $charset 输出编码 默认utf-8
   * @return ResponseInterface
   */
  public function setContentType(
    string $contentType,
    string $charset = 'utf-8'
  ): ResponseInterface;

  /**
   * html响应
   *
   * @access public
   * @param string $html
   * @return ResponseInterface
   */
  public function html(string $html): ResponseInterface;

  /**
   * 设置响应头(别名setHeader)
   *
   * @access public
   * @param string $key HTTP 头的 Key
   * @param string $value HTTP 头的 value
   * @param bool $format 是否需要对 Key 进行 HTTP 约定格式化【默认 true 会自动格式化】
   * @return ResponseInterface
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=setheader
   */
  public function header(string $key, string $value, bool $format = true): ResponseInterface;

  /**
   * 设置响应头
   *
   * @access public
   * @see header
   */
  public function setHeader(string $key, string $value, bool $format = true): ResponseInterface;

  /**
   * 将 Header 信息附加到 HTTP 响应的末尾，仅在 HTTP2 中可用，用于消息完整性检查，数字签名等。
   *
   * 重复设置相同地标头只会取最后一次，需要在end方法调用之前，调用该方法才有效。
   *
   * @access public
   * @param string $key HTTP 头的 Key 必须遵循HTTP约定
   * @param string $value HTTP 头的 value 必须遵循HTTP约定
   * @return bool
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=trailer
   */
  public function trailer(string $key, string $value): bool;

  /**
   * 重定向
   *
   * @param string $uri
   * @param int $http_code 302|301
   * @return bool
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=redirect
   */
  public function redirect(string $uri, int $http_code = 302): bool;

  /**
   * 启用 Http Chunk 分段向浏览器发送相应内容。
   *
   * @access public
   * @param string $data 要发送的数据内容【最大长度不得超过默认值2M，受 buffer_output_size 配置项控制】
   * @return ResponseInterface
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=write
   */
  public function write(string $data): ResponseInterface;

  /**
   * 发送响应(别名方法send)，对象销毁底层会自动进行end
   *
   * @access public
   * @param string|null $content
   * @return bool
   */
  public function end(?string $content = null): bool;

  /**
   * 发送响应
   *
   * @access public
   * @see end
   */
  public function send(?string $content = null): bool;

  /**
   * 发送HTTP状态(别名setStatusCode)
   *
   * @access public
   * @param int $http_status_code 状态码
   * @param string $reasonPhrase 状态描述短语
   * @return ResponseInterface
   */
  public function status(int $http_status_code, string $reasonPhrase = ''): ResponseInterface;

  /**
   * 发送HTTP状态
   *
   * @access public
   * @see status
   */
  public function setStatusCode(
    int    $http_status_code,
    string $reasonPhrase = ''
  ): ResponseInterface;

  /**
   * 任意格式的json响应
   *
   * @access public
   * @param array|JsonSerializable $data
   * @return ResponseInterface
   */
  public function json(array|JsonSerializable $data): ResponseInterface;

  /**
   * 检索所有消息头的值。
   *
   * 该方法返回所有标头和值的字符串，这些值使用逗号拼接在一起。
   *
   * @return array 所有标头。
   */
  public function getHeader(): array;

  /**
   * 设置响应内容
   *
   * @access public
   * @param string $content 响应内容
   * @return static
   */
  public function setContent(string $content): ResponseInterface;

  /**
   * 是否将响应输出到控制台
   *
   * @access public
   * @param bool $echo
   * @return ResponseInterface
   */
  public function echo(bool $echo = true): ResponseInterface;

  /**
   * 发送文件
   *
   * @param string $filePath 要发送的文件名称
   * @param int $offset 上传文件的偏移量
   * @param int $length 发送数据的尺寸
   * @param string|null $fileMimeType 文件类型
   * @return bool
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=sendfile
   */
  public function sendfile(
    string  $filePath,
    int     $offset = 0,
    int     $length = 0,
    ?string $fileMimeType = null
  ): bool;

  /**
   * rawCookie() 的参数和上文的 setCookie() 一致，只不过不进行编码处理
   *
   * @access public
   * @param string $key
   * @param string $value
   * @param int $expire
   * @param string $path
   * @param string $domain
   * @param bool $secure
   * @param bool $httponly
   * @param string $samesite
   * @param string $priority
   * @return ResponseInterface
   * @see cookie
   */
  public function rawCookie(
    string $key,
    string $value = '',
    int    $expire = 0,
    string $path = '/',
    string $domain = '',
    bool   $secure = false,
    bool   $httponly = false,
    string $samesite = '',
    string $priority = ''
  ): ResponseInterface;

  /**
   * 分离响应对象。
   * 使用此方法后，$response 对象销毁时不会自动 end。
   * 与 Response::create 和 Server::send 配合使用。
   *
   * @access public
   * @return ResponseInterface
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=detach
   */
  public function detach(): ResponseInterface;

  /**
   * 判断是否已经分离或已经结束
   *
   * @access public
   * @return bool 如果返回true则是未分离，返回false则代表已分离，或上下文已结束
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=detach
   */
  public function isWritable(): bool;

  /**
   * 设置Cookie信息
   *
   * @access public
   * @param string $key cookie名称
   * @param string $value cookie值
   * @param int $expire 过期时间
   * @param string $path 存储路径
   * @param string $domain 域名
   * @param bool $secure 是否通过安全的 HTTPS 连接来传输 Cookie
   * @param bool $httponly 是否允许浏览器的JavaScript访问带有 HttpOnly 属性的 Cookie
   * @param string $samesite 限制第三方 Cookie，从而减少安全风险
   * @param string $priority Cookie优先级，当Cookie数量超过规定，低优先级的会先被删除
   * @return ResponseInterface
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=setcookie
   */
  public function cookie(
    string $key,
    string $value = '',
    int    $expire = 0,
    string $path = '/',
    string $domain = '',
    bool   $secure = false,
    bool   $httponly = false,
    string $samesite = '',
    string $priority = ''
  ): ResponseInterface;
}
