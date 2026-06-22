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

use RuntimeException;
use Swoole\Http\Response as swooleResponse;

/**
 * HTTP 响应对象接口
 *
 * 定义响应状态、标头、Cookie、内容输出等核心能力，
 * Response 实现类必须遵循此契约。
 */
interface ResponseInterface
{
  /**
   * 工厂方法：创建新的响应对象
   *
   * 使用前须先调用 detach() 分离旧响应，否则同一请求会发送两次响应。
   *
   * @param object|array|int $server Swoole\Server、Swoole\Coroutine\Socket、[Server, Request] 或文件描述符
   * @param int $fd 文件描述符，$server 为 Swoole\Server 时必填
   * @return ResponseInterface 新创建的响应实例
   * @throws RuntimeException 创建失败时抛出
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=create
   */
  public static function create(object|array|int $server = -1, int $fd = -1): ResponseInterface;

  /**
   * 获取底层的 Swoole\Http\Response 对象
   *
   * @return swooleResponse Swoole 原始响应对象
   */
  public function getSwooleResponse(): swooleResponse;

  /**
   * 批量设置响应标头
   *
   * @param array<string,string> $headers 标头键值对
   * @return ResponseInterface 支持链式调用
   */
  public function setHeaders(array $headers): ResponseInterface;

  /**
   * 快捷设置 Content-Type 响应头
   *
   * @param string $contentType MIME 类型，如 application/json
   * @param string $charset 字符集编码，默认 utf-8
   * @return ResponseInterface 支持链式调用
   */
  public function setContentType(
    string $contentType,
    string $charset = 'utf-8'
  ): ResponseInterface;

  /**
   * 以 text/html 类型输出 HTML 内容
   *
   * @param string $html HTML 内容
   * @return ResponseInterface 支持链式调用
   */
  public function html(string $html): ResponseInterface;

  /**
   * 设置响应标头（setHeader 的别名）
   *
   * @param string $key 标头名称
   * @param string $value 标头值
   * @param bool $format 是否按 HTTP 约定格式化键名，默认 true
   * @return ResponseInterface 支持链式调用
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
   * 发送 HTTP 重定向响应
   *
   * @param string $uri 目标 URI
   * @param int $http_code 重定向状态码，302（临时）或 301（永久）
   * @return bool 发送成功返回 true
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=redirect
   */
  public function redirect(string $uri, int $http_code = 302): bool;

  /**
   * 以 HTTP Chunk 分段发送响应内容，单次最大长度受 buffer_output_size 配置控制（默认 2M）
   *
   * @param string $data 要发送的数据内容
   * @return ResponseInterface 支持链式调用
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=write
   */
  public function write(string $data): ResponseInterface;

  /**
   * 结束响应并发送内容，响应对象销毁时底层会自动调用
   *
   * @param string|null $content 要发送的响应内容，null 表示仅结束不发送额外内容
   * @return bool 发送成功返回 true
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
   * 设置 HTTP 状态码（setStatusCode 的别名）
   *
   * @param int $http_status_code HTTP 状态码
   * @param string $reasonPhrase 状态描述短语，为空时自动填充标准描述
   * @return ResponseInterface 支持链式调用
   */
  public function status(int $http_status_code, string $reasonPhrase = ''): ResponseInterface;

  /**
   * 设置 HTTP 状态码
   *
   * @see status()
   */
  public function setStatusCode(
    int    $http_status_code,
    string $reasonPhrase = ''
  ): ResponseInterface;

  /**
   * 任意格式的json响应
   *
   * @access public
   * @param mixed $data 任意可虚拟化的数据
   * @return ResponseInterface
   */
  public function json(mixed $data): ResponseInterface;

  /**
   * 获取所有已设置的响应标头
   *
   * @return array<string,string> 标头键值对
   */
  public function getHeader(): array;

  /**
   * 设置响应体内容
   *
   * @param string $content 响应内容
   * @return ResponseInterface 支持链式调用
   */
  public function setContent(string $content): ResponseInterface;

  /**
   * 控制是否将响应内容同时输出到控制台（调试用）
   *
   * @param bool $echo true 输出到控制台，默认 true
   * @return ResponseInterface 支持链式调用
   */
  public function echo(bool $echo = true): ResponseInterface;

  /**
   * 以零拷贝方式发送本地文件
   *
   * @param string $filePath 文件绝对路径
   * @param int $offset 文件偏移量（字节）
   * @param int $length 发送长度，0 表示发送至文件末尾
   * @param string|null $fileMimeType MIME 类型，null 时自动检测
   * @return bool 发送成功返回 true
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
   * 判断响应是否仍可写入（未分离且未结束）
   *
   * @return bool 未分离时返回 true，已分离或已结束时返回 false
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
