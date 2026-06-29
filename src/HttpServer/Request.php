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

declare(strict_types=1);

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
 * Swoole HTTP 请求的代理封装
 *
 * 对 Swoole\Http\Request 进行面向对象封装，提供参数获取、标头操作、文件处理等能力，
 * 并在构造时自动解析 JSON 请求体和上传文件。
 *
 * @see RequestInterface
 * @link https://wiki.swoole.com/zh-cn/#/http_server?id=swoolehttprequest
 */
class Request implements RequestInterface
{
  /**
   * Accept 标头预定义类型映射
   *
   * 键为类型别名，值为对应的 MIME 类型列表（逗号分隔），
   * 用于 isJson() 和 getAcceptType() 判断客户端期望的响应格式。
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
   * 全局输入过滤规则
   *
   * 键为 PHP 内置函数名，值为传给该函数的额外参数。
   * 示例：['htmlspecialchars'=>['flags'=>ENT_QUOTES|ENT_SUBSTITUTE]]
   *       ['htmlspecialchars'=>[ENT_QUOTES|ENT_SUBSTITUTE]]
   *       ['htmlspecialchars','strip_tags'=>null]
   *
   * @var array<string, array<string,mixed>|null>
   */
  protected array $filter = ['htmlspecialchars' => ['flags' => ENT_QUOTES | ENT_SUBSTITUTE]];

  /**
   * @param swooleRequest $swooleRequest Swoole 原始请求对象，由框架在 onRequest 回调中注入
   */
  public function __construct(public readonly swooleRequest $swooleRequest)
  {
    $contentType = $swooleRequest->header['content-type'] ?? null;
    // 修复: 使用 str_starts_with 匹配，兼容 "application/json; charset=utf-8" 等 Content-Type
    if ($contentType !== null && str_starts_with($contentType, 'application/json')) {
      // 获取原始请求内容
      $rawContent = $swooleRequest->rawContent();
      // 解析JSON数据
      $parsed = json_decode($rawContent, true);
      // 修复: JSON 解析失败时设置为空数组，避免 $swooleRequest->post 为 null
      if (json_last_error() !== JSON_ERROR_NONE) {
        $parsed = [];
      }
      // 将解析后的数据设置到 $request->post
      $swooleRequest->post = $parsed;
    }
    if (!empty($swooleRequest->files)) {
      $swooleRequest->files = $this->parseFiles(
        $swooleRequest->files
      );
    }
  }

  /**
   * 将 Swoole 原始文件结构转换为 UploadedFile 实例数组
   *
   * Swoole 多文件上传时结构为 [name=>[0=>'a',1=>'b'], tmp_name=>[...], ...]，
   * 需要转置为每个文件独立的 UploadedFile 对象。
   *
   * @param array $files Swoole 原始 $_FILES 结构
   * @return array<string, UploadedFile|UploadedFile[]> 键为字段名，值为单个或多个 UploadedFile
   */
  protected function parseFiles(array $files): array
  {
    $uploadedFiles = [];
    foreach ($files as $name => $file) {
      // 修复: 兼容 Swoole 多文件上传格式
      // Swoole 多文件格式为 [name => [0=>'a', 1=>'b'], tmp_name => [...], ...]
      // 通过检查第一个值是否为数组来判断是否为多文件上传
      $firstValue = reset($file);
      if (is_array($firstValue)) {
        // Swoole 多文件格式，需要转置
        foreach ($firstValue as $index => $v) {
          $uploadedFiles[$name][$index] = new UploadedFile(
            $file['type'][$index] ?? '',
            $file['name'][$index] ?? '',
            $file['size'][$index] ?? 0,
            $file['tmp_name'][$index] ?? '',
            $file['error'][$index] ?? UPLOAD_ERR_OK,
          );
        }
      } else {
        // 单文件
        $uploadedFiles[$name] = new UploadedFile(
          $file['type'] ?? '',
          $file['name'] ?? '',
          $file['size'] ?? 0,
          $file['tmp_name'] ?? '',
          $file['error'] ?? UPLOAD_ERR_OK,
        );
      }
    }
    return $uploadedFiles;
  }

  /**
   * 构建当前请求的 URI 实例
   *
   * 综合协议、Host 标头、认证信息、路径和查询参数组装完整 URI，
   * 兼容 IPv6 地址格式（如 [::1]:8080）。
   *
   * @return Uri 当前请求的 URI 实例
   */
  #[Override] public function getUri(): Uri
  {
    $host = $this->getHeader('host');
    // 修复: 使用 parse_url 解析 host 头，兼容 IPv6 地址（如 [::1]:8080）
    $parsed = parse_url('http://' . $host);
    $hostname = $parsed['host'] ?? $host;
    // 修复: port 为 0 时应视为无效端口，返回 null
    $port = (int)($parsed['port'] ?? 0) > 0 ? (int)$parsed['port'] : null;
    return Uri::create(
      scheme: $this->https() ? 'https' : 'http',
      userInfo: $this->getBasicAuthCredentials(),
      host: $hostname,
      port: $port,
      path: $this->target(),
      query: $this->getServer('query_string', '')
    );
  }

  /**
   * 获取请求标头（所有键名均为小写）
   *
   * @param string|null $key 标头名称，不传则返回全部标头关联数组
   * @param mixed $default 标头不存在时的默认值
   * @return array|string|null 传入 key 时返回字符串值或 null，不传时返回全部标头数组
   */
  #[Override] public function getHeader(
    ?string $key = null,
    mixed   $default = null
  ): array|string|null {
    return is_null($key)
      ? $this->swooleRequest->header ?? $default
      : $this->swooleRequest->header[strtolower($key)] ?? $default;
  }

  /**
   * 工厂方法：创建一个新的请求对象
   *
   * 用于在非 onRequest 回调场景下构造请求，例如异步任务中模拟请求。
   *
   * @param array{
   *   parse_cookie:bool,
   *   parse_body:bool,
   *   parse_files:bool,
   *   enable_compression:bool,
   *   compression_level:int,
   *   upload_tmp_dir:string
   * } $options Swoole\Request::create 的配置项
   * @return RequestInterface 新创建的请求实例
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=create
   */
  #[Override] public static function create(array $options = []): RequestInterface
  {
    $request = swooleRequest::create($options);
    return new static($request);
  }

  /**
   * 判断当前请求是否通过 HTTPS 发起
   *
   * 通过 Swoole Server 的 ssl 配置判断，而非请求标头。
   *
   * @return bool true 表示 HTTPS 请求
   */
  #[Override] public function https(): bool
  {
    return Server::getServer()->ssl;
  }

  /**
   * 获取 Swoole server 属性（等价于 $_SERVER）
   *
   * @param string|null $key 属性键名，不传则返回全部
   * @param mixed $default 键不存在时的默认值
   * @return mixed 传入 key 时返回对应值，不传时返回完整 server 数组
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=server
   */
  public function getServer(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->server
      : $this->swooleRequest->server[$key] ?? $default;
  }

  /**
   * 从 Authorization 标头中提取 Basic 认证凭据
   *
   * @return array|null [username, password] 二元数组，无 Authorization 标头或格式不匹配时返回 null
   */
  public function getBasicAuthCredentials(): ?array
  {
    $userinfo = $this->getHeader('Authorization');
    if (!empty($userinfo)) {
      // 修复: getHeader() 返回类型为 array|string|null, 当传入 key 时返回的是字符串,
      // 对字符串执行 foreach 会抛出 TypeError, 需统一转为数组后再遍历
      $headers = is_array($userinfo) ? $userinfo : [$userinfo];
      foreach ($headers as $value) {
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
   * 获取请求路径（不含查询参数）
   *
   * 优先取 path_info，回退到 request_uri，最终默认为 '/'。
   *
   * @return string 请求路径
   */
  public function target(): string
  {
    return $this->getServer('path_info', $this->getServer('request_uri', '/'));
  }

  /**
   * 获取请求路径（target 的别名）
   *
   * @return string 请求路径
   */
  public function getPath(): string
  {
    return $this->target();
  }

  /**
   * 获取 Cookie 值
   *
   * @param string|null $key Cookie 名称，不传则返回全部 Cookie 关联数组
   * @param mixed $default 键不存在时的默认值
   * @return mixed 单个 Cookie 值或全部 Cookie 数组
   */
  #[Override] public function cookie(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->cookie ?? $default
      : $this->swooleRequest->cookie[$key] ?? $default;
  }

  /**
   * 获取上传文件
   *
   * @param string|null $key 表单字段名，不传则返回全部上传文件
   * @return UploadedFile[]|UploadedFile|array<string, UploadedFile|UploadedFile[]>|null 无对应文件时返回 null
   */
  #[Override] public function files(?string $key = null): array|UploadedFile|null
  {
    return is_null($key) ? $this->swooleRequest->files : $this->swooleRequest->files[$key] ?? null;
  }

  /**
   * 获取原始请求体（等价于 fopen('php://input')）
   *
   * @return string|false 原始 POST 数据，读取失败返回 false
   */
  #[Override] public function getContent(): string|false
  {
    return $this->swooleRequest->getContent();
  }

  /**
   * 获取完整原始 HTTP 请求报文（含 Header 和 Body）
   *
   * 注意：HTTP2 模式下不可用。
   *
   * @return string|false 完整报文字符串，连接不存在或 HTTP2 模式下返回 false
   */
  #[Override] public function getData(): string|false
  {
    return $this->swooleRequest->getData();
  }

  /**
   * 解析 HTTP 请求数据包
   *
   * @param string $data 待解析的原始数据包
   * @return int 成功解析的报文长度
   * @throws RuntimeException 解析失败时抛出
   */
  #[Override] public function parse(string $data): int
  {
    return $this->swooleRequest->parse($data);
  }

  /**
   * 判断请求数据包是否已完整接收
   *
   * @return bool true 表示数据包已到达结尾
   */
  #[Override] public function isCompleted(): bool
  {
    return $this->swooleRequest->isCompleted();
  }

  /**
   * 获取客户端 IP 地址
   *
   * 优先读取反向代理设置的 x-real-ip 标头，回退到 Swoole 的 remote_addr。
   *
   * @return string 客户端 IP，无法获取时返回 'UNKNOWN'
   */
  #[Override] public function ip(): string
  {
    return $this->getHeader('x-real-ip') ?? $this->getServer('remote_addr', 'UNKNOWN');
  }

  /**
   * 批量获取请求参数
   *
   * @param string|array|null $rule 取值规则：字符串取单个字段；[key=>default] 取多个并设默认值；[key1,key2] 取多个无默认值；null 取全部
   * @param bool $isShowNull 是否保留值为 null 的字段
   * @return array 参数名到值的关联数组
   */
  #[Override] public function params(
    array|string|null $rule = null,
    bool $isShowNull = true
  ): array {
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
    // 修复: 使用 null 合并运算符替代冗余的 null 检查
    $params ??= [];
    return $isShowNull ? $params : array_filter($params, fn($value) => !is_null($value));
  }

  /**
   * 获取请求参数（自动根据请求方法选择 GET 或 POST）
   *
   * 非 GET 请求从 POST 取值，GET 请求从查询参数取值。
   * 字符串类型的值会经过全局过滤器处理。
   *
   * @param string|null $key 参数名，不传则获取全部
   * @param mixed $default 参数不存在时的默认值
   * @param string|array|null $filter 额外过滤器，覆盖全局过滤规则
   * @return mixed 参数值
   */
  #[Override] public function param(
    ?string      $key = null,
    mixed        $default = null,
    array|string|null $filter = null
  ): mixed {
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
   * 获取当前请求的 HTTP 方法
   *
   * @return string 大写的请求方法名（如 GET、POST）
   * @throws RuntimeException Swoole 无法获取请求方法时抛出
   */
  #[Override] public function getMethod(): string
  {
    $result = $this->swooleRequest->getMethod();
    if (!$result) throw new RuntimeException('获取请求方法失败');
    return $result;
  }

  /**
   * 获取 POST 参数
   *
   * @param string|null $key 参数名，不传则返回全部 POST 数据
   * @param mixed $default 参数不存在时的默认值
   * @return mixed 单个参数值或全部 POST 数据
   */
  #[Override] public function post(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->post ?? $default
      : $this->swooleRequest->post[$key] ?? $default;
  }

  /**
   * 获取 GET 查询参数
   *
   * @param string|null $key 参数名，不传则返回全部查询参数
   * @param mixed $default 参数不存在时的默认值
   * @return mixed 单个参数值或全部查询参数
   */
  #[Override] public function get(?string $key = null, mixed $default = null): mixed
  {
    return is_null($key)
      ? $this->swooleRequest->get ?? $default
      : $this->swooleRequest->get[$key] ?? $default;
  }

  /**
   * 对字符串数据应用过滤函数链
   *
   * 先应用全局 $filter 规则，再叠加调用时传入的 $filter。
   * 每个过滤规则键为函数名，值为传给函数的额外参数数组。
   *
   * @param string $data 待过滤的原始字符串
   * @param array|string|null $filter 额外过滤规则，字符串表示单个函数名，数组同 $filter 格式
   * @return string 过滤后的字符串
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
        // 修复: $arguments 可能为 null(见上方 $filters[$filter] = null 赋值),
        // 在 strict_types 模式下对 null 使用展开运算符 ... 会抛出 TypeError
        if (is_array($arguments)) {
          $data = $fn($data, ...$arguments);
        } else {
          $data = $fn($data);
        }
      }
    }
    return $data;
  }

  /**
   * 获取 HTTP 协议版本号
   *
   * 从 server_protocol（如 "HTTP/1.1"）中提取版本部分。
   *
   * @return string 协议版本，如 "1.1"、"2"
   */
  #[Override] public function getProtocolVersion(): string
  {
    $arr = explode('/', $this->getServer('server_protocol', 'HTTP/1.1'));
    return count($arr) === 2 ? $arr[1] : $arr[0];
  }

  /**
   * 检查指定标头是否存在（不区分大小写）
   *
   * @param string $key 标头名称（不区分大小写）
   * @return bool 标头存在返回 true，否则返回 false
   */
  #[Override] public function hasHeader(string $key): bool
  {
    return array_key_exists(strtolower($key), $this->getHeader());
  }

  /**
   * 设置（或新增）请求标头
   *
   * 标头名称会统一转为小写存储。若标头已存在则覆盖。
   *
   * @param string $name 标头名称（不区分大小写）
   * @param string $value 标头值
   * @return RequestInterface 支持链式调用
   * @throws InvalidArgumentException 标头名称或值不合法时抛出
   */
  #[Override] public function setHeader(string $name, string $value): RequestInterface
  {
    Header::validate($name, $value);
    $this->swooleRequest->header[strtolower($name)] = $value;
    return $this;
  }

  /**
   * 判断客户端是否期望 JSON 响应
   *
   * 通过 Accept 标头匹配 JSON MIME 类型判断。
   *
   * @return bool true 表示客户端期望 JSON 响应
   */
  #[Override] public function isJson(): bool
  {
    $accept = $this->getHeader('accept');
    // 修复: accept 为 null 时 stristr() 会产生警告，提前返回 false
    if ($accept === null) return false;
    $types = explode(',', self::ACCEPT_TYPE['json']);
    foreach ($types as $type) {
      if (stristr($accept, $type)) return true;
    }
    return false;
  }

  /**
   * 获取客户端期望的资源类型别名
   *
   * 遍历 ACCEPT_TYPE 映射，匹配 Accept 标头返回对应别名（如 json、html），
   * 无法匹配时返回 '*'。
   *
   * @return string 资源类型别名，如 'json'、'html'、'*'
   */
  #[Override] public function getAcceptType(): string
  {
    $accept = $this->getHeader('accept');
    // 修复: accept 为 null 时 stristr() 会产生警告，提前返回 '*'
    if ($accept === null) return '*';
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
   * 代理获取 Swoole\Http\Request 的属性
   *
   * @param string $name 属性名
   * @return mixed 属性值
   * @throws InvalidArgumentException 属性在 Swoole\Http\Request 中不存在时抛出
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
   * 代理设置 Swoole\Http\Request 的属性
   *
   * @param string $name 属性名
   * @param mixed $value 属性值
   * @throws InvalidArgumentException 属性在 Swoole\Http\Request 中不存在时抛出
   */
  public function __set(string $name, $value): void
  {
    if (property_exists($this->swooleRequest, $name)) {
      $this->swooleRequest->$name = $value;
    } else {
      throw new InvalidArgumentException("属性 $name 在 Swoole\Http\Request 对象中不存在");
    }
  }

  /**
   * 代理调用 Swoole\Http\Request 的方法
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed 方法返回值
   * @throws BadMethodCallException 方法在 Swoole\Http\Request 中不存在时抛出
   */
  public function __call(string $name, array $arguments)
  {
    if (method_exists($this->swooleRequest, $name)) {
      return call_user_func_array([$this->swooleRequest, $name], $arguments);
    } else {
      throw new BadMethodCallException("方法 $name 在 Swoole\Http\Request 对象中不存在");
    }
  }

  /**
   * 合并写入请求参数
   *
   * 根据类型决定写入 GET 或 POST 存储，auto 模式按当前请求方法自动选择。
   *
   * @param array<string, mixed> $params 待合并的参数键值对
   * @param string $type 写入目标：'get'、'post' 或 'auto'（按请求方法自动判断）
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
   * 获取底层的 Swoole\Http\Request 对象
   *
   * @return swooleRequest Swoole 原始请求对象
   */
  #[Override] public function getSwooleRequest(): swooleRequest
  {
    return $this->swooleRequest;
  }
}
