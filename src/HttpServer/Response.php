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
use JsonSerializable;
use Override;
use RuntimeException;
use Swoole\Http\Response as swooleResponse;
use Viswoole\HttpServer\Contract\ResponseInterface;

/**
 * HTTP响应对象
 *
 * 该类封装了Swoole\Http\Response类，并提供了一些额外的方法。
 * @link https://wiki.swoole.com/#/http_server?id=swoolehttpresponse
 */
class Response implements ResponseInterface
{
  /**
   * @var int 响应状态码
   */
  protected int $statusCode = Status::OK;
  /**
   * @var string 状态描述
   */
  protected string $reasonPhrase = Status::REASON_PHRASES[Status::OK];
  /**
   * @var array 默认响应标头
   */
  protected array $headers = [
    'Content-Type' => 'text/html; charset=utf-8'
  ];
  /**
   * @var string 响应内容
   */
  protected string $content = '';
  /**
   * @var int json_encode flags 参数
   */
  protected int $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
  /**
   * @var bool 是否把消息输出到控制台，建议在调试阶段使用
   */
  protected bool $echoToConsole = false;

  /**
   * @param swooleResponse $swooleResponse swoole响应对象
   */
  public function __construct(public readonly swooleResponse $swooleResponse)
  {
    $this->swooleResponse->status($this->statusCode, $this->reasonPhrase);
    foreach ($this->headers as $key => $value) {
      $this->swooleResponse->header($key, $value);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function status(int $http_status_code, string $reasonPhrase = ''
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
   * @inheritDoc
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
   * @inheritDoc
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

  public function __call(string $name, array $arguments)
  {
    if (method_exists($this->swooleResponse, $name)) {
      return call_user_func_array([$this->swooleResponse, $name], $arguments);
    } else {
      throw new BadMethodCallException("方法 $name 在 Swoole\Http\Response 对象中不存在");
    }
  }

  /**
   * 用于 获取 Swoole\Http\Response 对象的属性
   *
   * @param string $name
   * @return mixed
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
   * 用于 设置 Swoole\Http\Response 对象的属性
   *
   * @param string $name
   * @param $value
   * @return void
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
   * @access public
   * @param array $headers
   * @return ResponseInterface
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
   * 发送HTTP状态
   *
   * @access public
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
   * 发送响应
   *
   * @access public
   * @see end
   */
  public function send(?string $content = null): bool
  {
    return $this->end($content);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function end(?string $content = null): bool
  {
    if ($this->isWritable()) {
      if (is_null($content)) $content = $this->content;
      return $this->swooleResponse->end($content);
    } else {
      return false;
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function isWritable(): bool
  {
    return $this->swooleResponse->isWritable();
  }

  /**
   * 设置响应头
   *
   * @inheritDoc
   * @see header
   */
  public function setHeader(string $key, string $value, bool $format = true): ResponseInterface
  {
    return $this->header($key, $value, $format);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function trailer(string $key, string $value): bool
  {
    return $this->swooleResponse->trailer($key, $value);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function redirect(string $uri, int $http_code = 302): bool
  {
    return $this->swooleResponse->redirect($uri, $http_code);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function write(string $data): ResponseInterface
  {
    $result = $this->swooleResponse->write($data);
    if (!$result) throw new RuntimeException('分段写入数据失败，连接上下文已不存在。');
    return $this;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function json(array|JsonSerializable $data): ResponseInterface
  {
    $this->setContentType('application/json');
    $this->setContent(json_encode($data, $this->jsonFlags));
    return $this;
  }

  /**
   * @inheritDoc
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
   * @inheritDoc
   */
  #[Override] public function setContent(string $content): ResponseInterface
  {
    $this->content = $content;
    return $this;
  }

  /**
   * 获取Swoole\Http\Response响应对象
   *
   * @return swooleResponse
   */
  #[Override] public function getSwooleResponse(): swooleResponse
  {
    return $this->swooleResponse;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function html(string $html): ResponseInterface
  {
    $this->setContentType('text/html');
    $this->setContent($html);
    return $this;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function file(
    string $filePath, int $offset = 0, int $length = 0, ?string $fileMimeType = null
  ): bool
  {
    return $this->sendfile($filePath, $offset, $length, $fileMimeType);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sendfile(
    string  $filePath,
    int     $offset = 0,
    int     $length = 0,
    ?string $fileMimeType = null
  ): bool
  {
    if (empty($fileMimeType)) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $fileMimeType = finfo_file($finfo, $filePath);
      finfo_close($finfo);
    }
    $this->header('Content-Type', $fileMimeType);
    return $this->swooleResponse->sendfile($filePath, $offset, $length);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getHeader(): array
  {
    return $this->swooleResponse->header;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function echo(bool $echo = true): ResponseInterface
  {
    $this->echoToConsole = $echo;
    return $this;
  }

  /**
   * @inheritDoc
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
    return $this->cookie(
      $key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority
    );
  }

  /**
   * @inheritDoc
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
   * @inheritDoc
   */
  #[Override] public function detach(): ResponseInterface
  {
    $result = $this->swooleResponse->detach();
    if (!$result) throw new RuntimeException('分离响应失败，连接上下文已不存在。');
    return $this;
  }
}
