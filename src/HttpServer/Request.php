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
use Swoole\Http\Request as swooleRequest;
use Viswoole\Core\Facade\Server;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Message\UploadedFile;
use Viswoole\HttpServer\Message\Uri;

/**
 * HTTP请求对象
 *
 * 该类用于Swoole\Http\Request类进行代理封装。
 *
 * @link https://wiki.swoole.com/zh-cn/#/http_server?id=swoolehttprequest
 */
class Request implements RequestInterface
{
  /**
   * 请求类型
   */
  public const array ACCEPT_TYPE = [
    'html' => 'text/html,application/xhtml+xml,*/*',
    'json' => 'application/json,text/x-json,text/json',
    'image' => 'image/png,image/jpg,image/jpeg,image/pjpeg,image/gif,image/webp,image/*',
    'text' => 'text/plain',
    'xml' => 'application/xml,text/xml,application/x-xml',
    'js' => 'text/javascript,application/javascript,application/x-javascript',
    'css' => 'text/css',
    'rss' => 'application/rss+xml',
    'yaml' => 'application/x-yaml,text/yaml',
    'atom' => 'application/atom+xml',
    'pdf' => 'application/pdf',
    'csv' => 'text/csv'
  ];
  /**
   *  例1 ['htmlspecialchars'=>['flags' => ENT_QUOTES|ENT_SUBSTITUTE]]。
   *  例2 ['htmlspecialchars'=>[ENT_QUOTES|ENT_SUBSTITUTE]]。
   *  例3 ['htmlspecialchars','strip_tags'=>null]。
   * @var array{string:array} 全局过滤方法
   */
  protected array $filter = ['htmlspecialchars' => ['flags' => ENT_QUOTES | ENT_SUBSTITUTE]];

  public function __construct(public readonly swooleRequest $swooleRequest)
  {
    $contentType = $swooleRequest->header['content-type'] ?? null;
    if ($contentType === 'application/json') {
      // 获取原始请求内容
      $rawContent = $swooleRequest->rawContent();
      // 解析JSON数据
      $postData = json_decode($rawContent, true);
      // 将解析后的数据设置到 $request->post
      $swooleRequest->post = $postData;
    }
    if (!empty($swooleRequest->files)) $swooleRequest->files = $this->parseFiles(
      $swooleRequest->files
    );
  }

  /**
   * 解析上传文件为UploadedFile实例
   * @param array $files
   * @return array<string, UploadedFile[]>
   */
  protected function parseFiles(array $files): array
  {
    $uploadedFiles = [];
    foreach ($files as $name => $file) {
      if (is_numeric(implode('', array_keys($file)))) {
        $file = $this->parseFiles($file);
        $uploadedFiles[$name] = $file;
      } else {
        $uploadedFiles[$name] = [new UploadedFile(...$file)];
      }
    }
    return $uploadedFiles;
  }

  /**
   * 检索 URI 实例。
   *
   * @access public
   * @return Uri 返回表示请求 URI 的 UriInterface 实例。
   */
  #[Override] public function getUri(): Uri
  {
    $host = explode(':', $this->getHeader('host'));
    return Uri::create(
      scheme  : $this->https() ? 'https' : 'http',
      userInfo: $this->getBasicAuthCredentials(),
      host    : $host[0],
      port    : $host[1] ?? null,
      path    : $this->target(),
      query   : $this->getServer('query_string', '')
    );
  }

  /**
   * 获取请求标头,所有标头均为小写
   *
   * @param string|null $key 如果传入key则获取单个请求标头，否则获取所有标头
   * @return array|string|null 存在返回标头值，不存在返回null
   */
  #[Override] public function getHeader(
    ?string $key = null,
    mixed   $default = null
  ): array|string|null
  {
    return is_null($key)
      ? $this->swooleRequest->header ?? $default
      : $this->swooleRequest->header[strtolower($key)] ?? $default;
  }

  /**
   * 创建一个新的RequestInterface对象
   *
   * @param array{
   *   parse_cookie:bool,
   *   parse_body:bool,
   *   parse_files:bool,
   *   enable_compression:bool,
   *   compression_level:int,
   *   upload_tmp_dir:string
   * } $options
   * @return RequestInterface
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=create
   */
  #[Override] public static function create(array $options = []): RequestInterface
  {
    $request = swooleRequest::create($options);
    return new static($request);
  }

  /**
   * 判断是否https访问
   *
   * @return bool
   */
  #[Override] public function https(): bool
  {
    return Server::getServer()->ssl;
  }

  /**
   * 获取服务参数
   *
   * @return array{
   *   query_string:string,
   *   request_method:string,
   *   request_uri:string,
   *   path_info:string,
   *   request_time:int,
   *   request_time_float:float,
   *   server_protocol:string,
   *   server_port:int,
   *   remote_port:int,
   *   remote_addr:string,
   *   master_time:int
   * }|mixed
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=server
   */
  public function getServer(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->server
      : $this->swooleRequest->server[$key] ?? $default;
  }

  /**
   * 获取基本身份验证票据
   *
   * @access public
   * @return array|null AssociativeArray(username,password)
   */
  public function getBasicAuthCredentials(): ?array
  {
    $userinfo = $this->getHeader('Authorization');
    if (!empty($userinfo)) {
      foreach ($userinfo as $value) {
        // 获取请求头部中的 "Authorization" 字段的值
        $authorizationHeader = $value;
        // 检查是否包含 "Basic " 前缀
        if (str_starts_with($authorizationHeader, 'Basic ')) {
          // 去除 "Basic " 前缀并解码 Base64 编码的字符串
          $base64Credentials = substr($authorizationHeader, 6);
          $credentials = base64_decode($base64Credentials);
          if ($credentials !== false) {
            // 分离用户名和密码
            return explode(':', $credentials, 2);
          }
        }
      }
    }
    return null;
  }

  /**
   * 获取消息的请求目标。
   *
   * @access public
   * @return string
   */
  public function target(): string
  {
    return $this->getServer('path_info', $this->getServer('request_uri', '/'));
  }

  /**
   * 获取消息的请求目标。
   *
   * @access public
   * @return string
   */
  public function getPath(): string
  {
    return $this->target();
  }

  /**
   * 获取cookie
   *
   * @param string|null $key 要获取的Cookie名称，不传获取所有cookie关联数组
   * @param mixed|null $default
   * @return mixed
   */
  #[Override] public function cookie(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->cookie ?? $default
      : $this->swooleRequest->cookie[$key] ?? $default;
  }

  /**
   * 获取上传的文件
   *
   * @param string|null $key 可选文件名称
   * @return UploadedFile[]|null|array<string, UploadedFile[]>
   */
  #[Override] public function files(?string $key = null): array|null
  {
    return is_null($key) ? $this->swooleRequest->files : $this->swooleRequest->files[$key] ?? null;
  }

  /**
   * 获取POST包体，此函数等同于 PHP 的 fopen('php://input')。
   *
   * @return string|false 返回原始POST数据，失败返回false
   */
  #[Override] public function getContent(): string|false
  {
    return $this->swooleRequest->getContent();
  }

  /**
   * 获取完整的原始 Http 请求报文，注意 Http2 下无法使用。
   * 包括 Http Header 和 Http Body。
   *
   * @return string|false 执行成功返回报文，如果上下文连接不存在或者在 Http2 模式下返回 false
   */
  #[Override] public function getData(): string|false
  {
    return $this->swooleRequest->getData();
  }

  /**
   * 解析 HTTP 请求数据包，会返回成功解析的数据包长度。
   *
   * @param string $data
   * @return int 解析成功返回解析的报文长度
   * @throws RuntimeException 解析失败
   */
  #[Override] public function parse(string $data): int
  {
    return $this->swooleRequest->parse($data);
  }

  /**
   * 获取当前的 HTTP 请求数据包是否已到达结尾。
   *
   * @return bool
   */
  #[Override] public function isCompleted(): bool
  {
    return $this->swooleRequest->isCompleted();
  }

  /**
   * 获取客户端ip
   *
   * @return string
   */
  #[Override] public function ip(): string
  {
    return $this->getHeader('x-real-ip') ?? $this->getServer('remote_addr', 'UNKNOWN');
  }

  /**
   * 批量获取请求参数
   * @access public
   * @param string|array|null $rule 可传key或[key=>default,...]或[key1,key2....]
   * @param bool $isShowNull 是否显示为null的字段
   * @return array
   */
  #[Override] public function params(array|string|null $rule = null, bool $isShowNull = true
  ): array
  {
    $params = [];
    if (empty($rule)) {
      $params = $this->param();
    } elseif (is_string($rule)) {
      $params[$rule] = $this->param($rule);
    } else {
      foreach ($rule as $key => $val) {
        [$paramName, $defaultVal] = is_int($key) ? [$val, null] : [$key, $val];
        $params[$paramName] = $this->param($paramName, $defaultVal);
      }
    }
    if ($params === null) return [];
    return $isShowNull ? $params : array_filter($params, fn($value) => !is_null($value));
  }

  /**
   * 获取请求参数，自动判断get或post
   *
   * @access public
   * @param string|null $key 字段，不传则获取全部
   * @param mixed $default 默认值
   * @param string|array|null $filter 过滤器
   * @return mixed
   */
  #[Override] public function param(
    ?string      $key = null,
    mixed        $default = null,
    array|string $filter = null
  ): mixed
  {
    if ($this->getMethod() !== 'GET') {
      $data = $this->post($key, $default);
    } else {
      $data = $this->get($key, $default);
    }
    if (is_string($data)) {
      $data = $this->filter($data, $filter);
    }
    return $data;
  }

  /**
   * 获取当前的 HTTP 请求的请求方式。
   *
   * @return string 成返回大写的请求方式
   */
  #[Override] public function getMethod(): string
  {
    $result = $this->swooleRequest->getMethod();
    if (!$result) throw new RuntimeException('获取请求方法失败');
    return $result;
  }

  /**
   * 获取post参数
   *
   * @param string|null $key 要获取的参数
   * @param mixed|null $default 默认值
   * @return mixed
   */
  #[Override] public function post(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->post ?? $default
      : $this->swooleRequest->post[$key] ?? $default;
  }

  /**
   * 获取get参数
   *
   * @param string|null $key 要获取的参数
   * @param mixed|null $default 默认值
   * @return mixed
   */
  #[Override] public function get(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->get ?? $default
      : $this->swooleRequest->get[$key] ?? $default;
  }

  /**
   * 过滤数据
   *
   * @param string $data
   * @param array|string|null $filter
   * @return string
   */
  protected function filter(string $data, array|string|null $filter = null): string
  {
    $filters = $this->filter ?? [];
    if (!empty($filter)) {
      if (is_string($filter)) {
        $filters[$filter] = null;
      } else {
        foreach ($filter as $key => $val) {
          if (is_string($key)) {
            $filters[$key] = $val;
          } else {
            $filters[$val] = null;
          }
        }
      }
    }
    foreach ($filters as $fn => $arguments) {
      if (function_exists($fn)) {
        $data = $fn($data, ...$arguments);
      }
    }
    return $data;
  }

  /**
   * 获取HTTP协议版本。
   *
   * @access public
   * @return string HTTP协议版本。例如，"1.1"，"1.0","2"
   */
  #[Override] public function getProtocolVersion(): string
  {
    $arr = explode('/', $this->getServer('server_protocol', 'HTTP/1.1'));
    return count($arr) === 2 ? $arr[1] : $arr[0];
  }

  /**
   * 通过给定的不区分大小写的名称检查标头是否存在。
   *
   * @access public
   * @param string $key
   * @return bool 如果任何标头名称使用不区分大小写的字符串比较与给定的标头名称匹配，则返回true。如果消息中没有找到匹配的标头名称，则返回false。
   */
  #[Override] public function hasHeader(string $key): bool
  {
    return array_key_exists(strtolower($key), $this->getHeader());
  }

  /**
   * 使用提供的值替换指定标头的实例。(不存在会新增)
   *
   * @param string $name 不区分大小写的标头字段名称。
   * @param string $value 标头值。
   * @return static
   */
  #[Override] public function setHeader(string $name, string $value): RequestInterface
  {
    Header::validate($name, $value);
    $this->swooleRequest->header[strtolower($name)] = $value;
    return $this;
  }

  /**
   * 当前是否JSON请求
   * @access public
   * @return bool
   */
  #[Override] public function isJson(): bool
  {
    $accept = $this->getHeader('accept');
    $types = explode(',', self::ACCEPT_TYPE['json']);
    foreach ($types as $type) {
      if (stristr($accept, $type)) return true;
    }
    return false;
  }

  /**
   * 当前请求的资源类型
   * @access public
   * @return string
   */
  #[Override] public function getAcceptType(): string
  {
    $accept = $this->getHeader('accept');
    if (empty($accept)) return '*';
    foreach (self::ACCEPT_TYPE as $key => $val) {
      $types = explode(',', $val);
      foreach ($types as $type) {
        if (stristr($accept, $type)) return $key;
      }
    }
    return '*';
  }

  /**
   * 用于 获取 Swoole\Http\Request 对象的属性
   *
   * @param string $name
   * @return mixed
   */
  public function __get(string $name)
  {
    if (property_exists($this->swooleRequest, $name)) {
      return $this->swooleRequest->$name;
    } else {
      throw new InvalidArgumentException("属性 $name 在 Swoole\Http\Request 对象中不存在");
    }
  }

  /**
   * 用于 设置 Swoole\Http\Request 对象的属性
   *
   * @param string $name
   * @param $value
   * @return void
   */
  public function __set(string $name, $value): void
  {
    if (property_exists($this->swooleRequest, $name)) {
      $this->swooleRequest->$name = $value;
    } else {
      throw new InvalidArgumentException("属性 $name 在 Swoole\Http\Request 对象中不存在");
    }
  }

  public function __call(string $name, array $arguments)
  {
    if (method_exists($this->swooleRequest, $name)) {
      return call_user_func_array([$this->swooleRequest, $name], $arguments);
    } else {
      throw new BadMethodCallException("方法 $name 在 Swoole\Http\Request 对象中不存在");
    }
  }

  /**
   * 添加/修改请求参数
   *
   * @param array<string,mixed> $params 参数名称
   * @param string $type 参数类型，可选值auto,get,post。
   * @return void
   */
  #[Override] public function addParams(array $params, string $type = 'auto'): void
  {
    switch (strtolower($type)) {
      case 'get':
        $this->swooleRequest->get = array_merge($this->swooleRequest->get ?? [], $params);
        break;
      case 'post':
        $this->swooleRequest->post = array_merge($this->swooleRequest->post ?? [], $params);
        break;
      default:
        if ($this->swooleRequest->getMethod() === 'GET') {
          $this->swooleRequest->get = array_merge($this->swooleRequest->get ?? [], $params);
        } else {
          $this->swooleRequest->post = array_merge($this->swooleRequest->post ?? [], $params);
        }
    }
  }

  /**
   * @return swooleRequest
   */
  #[Override] public function getSwooleRequest(): swooleRequest
  {
    return $this->swooleRequest;
  }
}
