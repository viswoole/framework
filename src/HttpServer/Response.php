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

namespace Viswoole\HttpServer;

use BadMethodCallException;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Swoole\Http\Response as swooleResponse;
use Viswoole\Core\Console\Output;
use Viswoole\HttpServer\Contract\ResponseInterface;

/**
 * Swoole HTTP 响应的代理封装
 *
 * 对 Swoole\Http\Response 进行面向对象封装，提供状态码设置、标头操作、
 * 内容写入、Cookie 管理、文件发送等能力，并支持链式调用。
 *
 * @see ResponseInterface
 * @link https://wiki.swoole.com/#/http_server?id=swoolehttpresponse
 */
class Response implements ResponseInterface
{
  /**
   * @var int 响应状态码，仅构造时同步到 swooleResponse，后续不同步
   */
  protected int $statusCode = Status::OK;
  /**
   * @var string 状态描述短语
   */
  protected string $reasonPhrase = Status::REASON_PHRASES[Status::OK];
  /**
   * @var array 默认响应标头，仅构造时同步到 swooleResponse，后续不同步
   */
  protected array $headers = [
    'Content-Type' => 'text/html; charset=utf-8'
  ];
  /**
   * @var string 待发送的响应内容
   */
  protected string $content = '';
  /**
   * @var int json_encode 的 flags 参数，默认不转义 Unicode 和斜杠
   */
  protected int $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
  /**
   * @var bool 是否将响应内容输出到控制台（仅调试阶段使用）
   */
  protected bool $echoToConsole = false;

  /**
   * @param swooleResponse $swooleResponse Swoole 原始响应对象，由框架在 onRequest 回调中注入
   */
  public function __construct(public readonly swooleResponse $swooleResponse)
  {
    $this->swooleResponse->status($this->statusCode, $this->reasonPhrase);
    foreach ($this->headers as $key => $value) {
      $this->swooleResponse->header($key, $value);
    }
  }

  /**
   * 设置 HTTP 响应状态码及描述短语
   *
   * @param int $http_status_code 状态码，有效范围 100-599
   * @param string $reasonPhrase 状态描述，为空时自动从 Status 常量获取
   * @return ResponseInterface 支持链式调用
   * @throws InvalidArgumentException 状态码不在 100-599 范围时抛出
   */
  #[Override] public function status(
    int    $http_status_code,
    string $reasonPhrase = ''
  ): ResponseInterface
  {
    // 检查状态码是否有效
    if ($http_status_code < 100 || $http_status_code >= 600) {
      throw new InvalidArgumentException(
        'Invalid HTTP status code, correct value should be between 100 and 599 '
      );
    }
    if (empty($reasonPhrase)) {
      $reasonPhrase = Status::getReasonPhrase($http_status_code);
    }
    $this->swooleResponse->status($http_status_code, $reasonPhrase);
    return $this;
  }

  /**
   * 设置响应标头
   *
   * @param string $key 标头名称
   * @param string $value 标头值
   * @param bool $format 是否按 HTTP 约定格式化键名
   * @return ResponseInterface 支持链式调用
   * @throws InvalidArgumentException 响应对象已结束或已分离时抛出
   */
  #[Override] public function header(
    string $key,
    string $value,
    bool   $format = true
  ): ResponseInterface
  {
    $result = $this->swooleResponse->header($key, $value, $format);
    if (!$result) {
      throw new InvalidArgumentException('设置响应头失败，响应对象已结束或已分离');
    }
    return $this;
  }

  /**
   * 工厂方法：创建新的响应对象
   *
   * @param object|array|int $server Swoole\Server 对象、[Server, Request] 数组或文件描述符
   * @param int $fd 文件描述符，$server 为 Swoole\Server 时必填
   * @return ResponseInterface 新创建的响应实例
   * @throws RuntimeException 创建失败时抛出
   */
  #[Override] public static function create(
    object|array|int $server = -1,
    int              $fd = -1
  ): ResponseInterface
  {
    $result = swooleResponse::create($server, $fd);
    if (!$result) throw new RuntimeException('创建响应对象失败，请检查参数是否正确。');
    return new static($result);
  }

  /**
   * 代理调用 Swoole\Http\Response 的方法
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed 方法返回值
   * @throws BadMethodCallException 方法在 Swoole\Http\Response 中不存在时抛出
   */
  public function __call(string $name, array $arguments)
  {
    if (method_exists($this->swooleResponse, $name)) {
      return call_user_func_array([$this->swooleResponse, $name], $arguments);
    } else {
      throw new BadMethodCallException("方法 $name 在 Swoole\Http\Response 对象中不存在");
    }
  }

  /**
   * 代理获取 Swoole\Http\Response 的属性
   *
   * @param string $name 属性名
   * @return mixed 属性值
   * @throws InvalidArgumentException 属性在 Swoole\Http\Response 中不存在时抛出
   */
  public function __get(string $name)
  {
    if (property_exists($this->swooleResponse, $name)) {
      return $this->swooleResponse->$name;
    } else {
      throw new InvalidArgumentException("属性 $name 在 Swoole\Http\Response 对象中不存在");
    }
  }

  /**
   * 代理设置 Swoole\Http\Response 的属性
   *
   * @param string $name 属性名
   * @param mixed $value 属性值
   * @throws InvalidArgumentException 属性在 Swoole\Http\Response 中不存在时抛出
   */
  public function __set(string $name, $value): void
  {
    if (property_exists($this->swooleResponse, $name)) {
      $this->swooleResponse->$name = $value;
    } else {
      throw new InvalidArgumentException("属性 $name 在 Swoole\Http\Response 对象中不存在");
    }
  }

  /**
   * 批量设置响应标头
   *
   * 数组值为数组时会用逗号拼接为字符串。
   *
   * @param array<string, string|string[]> $headers 标头键值对
   * @return ResponseInterface 支持链式调用
   */
  #[Override] public function setHeaders(array $headers): ResponseInterface
  {
    foreach ($headers as $headerName => $headerValue) {
      $headerValue = is_array($headerValue)
        ? implode(',', $headerValue)
        : $headerValue;
      $this->header($headerName, $headerValue);
    }
    return $this;
  }

  /**
   * 设置状态码（status 方法的别名）
   *
   * @see status
   */
  public function setStatusCode(
    int    $http_status_code,
    string $reasonPhrase = ''
  ): ResponseInterface
  {
    return $this->status($http_status_code, $reasonPhrase);
  }

  /**
   * 发送响应（end 方法的别名）
   *
   * @see end
   */
  public function send(?string $content = null): bool
  {
    return $this->end($content);
  }

  /**
   * 结束响应并发送内容
   *
   * 若已开启 echoToConsole，会同时将响应内容和耗时输出到控制台。
   * 响应对象不可写时直接返回 false。
   *
   * @param string|null $content 响应内容，为 null 时使用已设置的 content
   * @return bool 发送成功返回 true，响应已结束或不可写返回 false
   */
  #[Override] public function end(?string $content = null): bool
  {
    if ($this->isWritable()) {
      if (is_null($content)) $content = $this->content;
      if ($this->echoToConsole) {
        // 请求时间
        $request_time = \Viswoole\HttpServer\Facade\Request::getServer('request_time_float');
        // 获取当前时间
        $current_time_float = microtime(true);
        // 计算耗时
        $elapsed_time = $current_time_float - $request_time;
        // 输出到控制台
        Output::dump($content, "响应内容:耗时{$elapsed_time}秒");
      }
      return $this->swooleResponse->end($content);
    } else {
      return false;
    }
  }

  /**
   * 判断响应是否仍可写入（未结束且未分离）
   *
   * @return bool true 表示可写入，false 表示已结束或已分离
   */
  #[Override] public function isWritable(): bool
  {
    return $this->swooleResponse->isWritable();
  }

  /**
   * 设置响应标头（header 方法的别名）
   *
   * @see header
   */
  public function setHeader(string $key, string $value, bool $format = true): ResponseInterface
  {
    return $this->header($key, $value, $format);
  }

  /**
   * 在 HTTP 响应末尾追加 Trailer 标头（仅 HTTP2 有效）
   *
   * @param string $key 标头键名
   * @param string $value 标头值
   * @return bool 设置成功返回 true
   */
  #[Override] public function trailer(string $key, string $value): bool
  {
    return $this->swooleResponse->trailer($key, $value);
  }

  /**
   * 发送 HTTP 重定向响应
   *
   * @param string $uri 目标 URI
   * @param int $http_code 重定向状态码，默认 302
   * @return bool 重定向成功返回 true
   */
  #[Override] public function redirect(string $uri, int $http_code = 302): bool
  {
    return $this->swooleResponse->redirect($uri, $http_code);
  }

  /**
   * 分段写入响应数据（HTTP Chunk 模式）
   *
   * @param string $data 数据内容，最大长度受 buffer_output_size 配置控制
   * @return ResponseInterface 支持链式调用
   * @throws RuntimeException 写入失败（连接上下文已不存在）时抛出
   */
  #[Override] public function write(string $data): ResponseInterface
  {
    $result = $this->swooleResponse->write($data);
    if (!$result) throw new RuntimeException('分段写入数据失败，连接上下文已不存在。');
    return $this;
  }

  /**
   * 将数据编码为 JSON 并设置为响应内容
   *
   * 先编码再设置 Content-Type，避免编码失败但已修改了标头。
   *
   * @param mixed $data 任意可序列化为 JSON 的数据
   * @return ResponseInterface 支持链式调用
   * @throws RuntimeException JSON 编码失败时抛出
   */
  #[Override] public function json(mixed $data): ResponseInterface
  {
    // 修复: 先 json_encode 成功后再 setContentType，避免编码失败但已设置了 Content-Type
    $data = json_encode($data, $this->jsonFlags);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new RuntimeException('JSON响应数据编码失败，请检查数据是否正确。');
    }
    $this->setContentType('application/json');
    $this->setContent($data);
    return $this;
  }

  /**
   * 快捷设置 Content-Type 响应标头
   *
   * @param string $contentType MIME 类型，如 application/json
   * @param string $charset 字符编码，默认 utf-8
   * @return ResponseInterface 支持链式调用
   */
  #[Override] public function setContentType(
    string $contentType,
    string $charset = 'utf-8'
  ): ResponseInterface
  {
    $this->header('Content-Type', $contentType . '; charset=' . $charset);
    return $this;
  }

  /**
   * 设置待发送的响应内容
   *
   * @param string $content 响应正文
   * @return ResponseInterface 支持链式调用
   */
  #[Override] public function setContent(string $content): ResponseInterface
  {
    $this->content = $content;
    return $this;
  }

  /**
   * 获取底层的 Swoole\Http\Response 对象
   *
   * @return swooleResponse Swoole 原始响应对象
   */
  #[Override] public function getSwooleResponse(): swooleResponse
  {
    return $this->swooleResponse;
  }

  /**
   * 设置 HTML 响应内容
   *
   * @param string $html HTML 内容
   * @return ResponseInterface 支持链式调用
   */
  #[Override] public function html(string $html): ResponseInterface
  {
    $this->setContentType('text/html');
    $this->setContent($html);
    return $this;
  }

  /**
   * 发送本地文件作为响应体
   *
   * 未指定 MIME 类型时通过 finfo 自动检测，检测失败回退为 application/octet-stream。
   *
   * @param string $filePath 文件绝对路径
   * @param int $offset 发送起始偏移量
   * @param int $length 发送字节数，0 表示全部
   * @param string|null $fileMimeType 强制指定的 MIME 类型
   * @return bool 发送成功返回 true
   * @throws InvalidArgumentException 文件不存在时抛出
   */
  #[Override] public function sendfile(
    string  $filePath,
    int     $offset = 0,
    int     $length = 0,
    ?string $fileMimeType = null
  ): bool
  {
    if (!file_exists($filePath)) {
      throw new InvalidArgumentException("没有找到要发送的文件：{$filePath}，请检查路径是否正确。");
    }
    if (empty($fileMimeType)) {
      // 修复: finfo_open 和 finfo_file 可能返回 false，增加 false 检查
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      if ($finfo === false) {
        $this->header('Content-Type', 'application/octet-stream');
      } else {
        $fileMimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        if ($fileMimeType === false) {
          $fileMimeType = 'application/octet-stream';
        }
        $this->header('Content-Type', $fileMimeType);
      }
    } else {
      $this->header('Content-Type', $fileMimeType);
    }
    return $this->swooleResponse->sendfile($filePath, $offset, $length);
  }

  /**
   * 获取所有已设置的响应标头
   *
   * @return array<string, string> 标头键值对
   */
  #[Override] public function getHeader(): array
  {
    // 修复: swooleResponse->header 可能为 null，使用 null 合并确保返回数组
    return $this->swooleResponse->header ?? [];
  }

  /**
   * 控制是否将响应内容输出到控制台（调试用）
   *
   * @param bool $echo true 开启控制台输出
   * @return ResponseInterface 支持链式调用
   */
  #[Override] public function echo(bool $echo = true): ResponseInterface
  {
    $this->echoToConsole = $echo;
    return $this;
  }

  /**
   * 设置不编码的原始 Cookie（值不经过 urlencode 处理）
   *
   * @param string $key Cookie 名称
   * @param string $value Cookie 值
   * @param int $expire 过期时间戳
   * @param string $path 存储路径
   * @param string $domain 域名
   * @param bool $secure 是否仅 HTTPS 传输
   * @param bool $httponly 是否禁止 JS 访问
   * @param string $samesite SameSite 策略
   * @param string $priority Cookie 优先级
   * @return ResponseInterface 支持链式调用
   * @throws RuntimeException 设置失败时抛出
   * @see cookie
   */
  #[Override] public function rawCookie(
    string $key,
    string $value = '',
    int    $expire = 0,
    string $path = '/',
    string $domain = '',
    bool   $secure = false,
    bool   $httponly = false,
    string $samesite = '',
    string $priority = ''
  ): ResponseInterface
  {
    // 修复: rawCookie 应调用 swooleResponse->rawcookie() 而非 cookie()，保留 rawCookie 语义
    $result = $this->swooleResponse->rawcookie(
      $key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority
    );
    if (!$result) throw new RuntimeException('设置rawCookie失败。');
    return $this;
  }

  /**
   * 设置 Cookie（值会经过 urlencode 编码）
   *
   * @param string $key Cookie 名称
   * @param string $value Cookie 值
   * @param int $expire 过期时间戳
   * @param string $path 存储路径
   * @param string $domain 域名
   * @param bool $secure 是否仅 HTTPS 传输
   * @param bool $httponly 是否禁止 JS 访问
   * @param string $samesite SameSite 策略
   * @param string $priority Cookie 优先级
   * @return ResponseInterface 支持链式调用
   * @throws RuntimeException 设置失败时抛出
   */
  #[Override] public function cookie(
    string $key,
    string $value = '',
    int    $expire = 0,
    string $path = '/',
    string $domain = '',
    bool   $secure = false,
    bool   $httponly = false,
    string $samesite = '',
    string $priority = ''
  ): ResponseInterface
  {
    $result = $this->swooleResponse->cookie(
      $key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority
    );
    if (!$result) throw new RuntimeException('设置cookie失败。');
    return $this;
  }

  /**
   * 分离响应对象，使其销毁时不再自动 end
   *
   * 与 create 和 Server::send 配合使用，实现异步推送。
   *
   * @return ResponseInterface 支持链式调用
   * @throws RuntimeException 分离失败（连接上下文已不存在）时抛出
   */
  #[Override] public function detach(): ResponseInterface
  {
    $result = $this->swooleResponse->detach();
    if (!$result) throw new RuntimeException('分离响应失败，连接上下文已不存在。');
    return $this;
  }
}
