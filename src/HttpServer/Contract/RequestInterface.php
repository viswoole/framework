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
use Swoole\Http\Request as swooleRequest;
use Viswoole\HttpServer\Message\UploadedFile;
use Viswoole\HttpServer\Message\Uri;

/**
 * HTTP 请求对象接口
 *
 * 定义请求参数获取、标头操作、文件处理、URI 构建等核心能力，
 * Request 实现类必须遵循此契约。
 *
 * @link https://wiki.swoole.com/zh-cn/#/http_server?id=swoolehttprequest
 */
interface RequestInterface
{
  /**
   * 工厂方法：创建新的请求对象
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
  public static function create(array $options = []): RequestInterface;

  /**
   * 获取底层的 Swoole\Http\Request 对象
   *
   * @return swooleRequest Swoole 原始请求对象
   */
  public function getSwooleRequest(): swooleRequest;

  /**
   * 获取请求标头（所有键名均为小写）
   *
   * @param string|null $key 标头名称，不传则返回全部
   * @param mixed $default 标头不存在时的默认值
   * @return array|string|null 传入 key 时返回字符串值或 null，不传时返回全部标头数组
   */
  public function getHeader(?string $key = null, mixed $default = null): array|string|null;

  /**
   * 获取 Swoole server 属性（等价于 $_SERVER）
   *
   * @param string|null $key 属性键名，不传则返回全部
   * @param mixed $default 键不存在时的默认值
   * @return mixed 单个属性值或完整 server 数组
   * @link https://wiki.swoole.com/zh-cn/#/http_server?id=server
   */
  public function getServer(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取 POST 参数
   *
   * @param string|null $key 参数名，不传则返回全部 POST 数据
   * @param mixed $default 参数不存在时的默认值
   * @return mixed 单个参数值或全部 POST 数据
   */
  public function post(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取 GET 查询参数
   *
   * @param string|null $key 参数名，不传则返回全部查询参数
   * @param mixed $default 参数不存在时的默认值
   * @return mixed 单个参数值或全部查询参数
   */
  public function get(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取 Cookie 值
   *
   * @param string|null $key Cookie 名称，不传则返回全部 Cookie 关联数组
   * @param mixed $default 键不存在时的默认值
   * @return mixed 单个 Cookie 值或全部 Cookie 数组
   */
  public function cookie(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取上传文件
   *
   * @param string|null $key 表单字段名，不传则返回全部上传文件
   * @return UploadedFile|UploadedFile[]|null|array<string, UploadedFile|UploadedFile[]> 无对应文件时返回 null
   */
  public function files(?string $key = null): UploadedFile|array|null;

  /**
   * 获取原始请求体（等价于 fopen('php://input')）
   *
   * @return string|false 原始 POST 数据，读取失败返回 false
   */
  public function getContent(): string|false;

  /**
   * 获取完整原始 HTTP 请求报文（含请求行、Header 和 Body），HTTP2 下不可用
   *
   * @return string|false 完整报文字符串，连接不存在或 HTTP2 模式下返回 false
   */
  public function getData(): string|false;

  /**
   * 解析 HTTP 请求数据包，用于半包/粘包场景下手动追加数据
   *
   * @param string $data 原始请求数据包
   * @return int 成功解析的报文长度
   * @throws RuntimeException 数据包解析失败时抛出
   */
  public function parse(string $data): int;

  /**
   * 判断当前 HTTP 请求数据包是否已完整接收
   *
   * @return bool 数据包接收完毕返回 true
   */
  public function isCompleted(): bool;

  /**
   * 获取 HTTP 请求方法（大写形式，如 GET、POST）
   *
   * @return string 请求方法名
   */
  public function getMethod(): string;

  /**
   * 获取客户端 IP 地址，优先读取 X-Forwarded-For / X-Real-IP 等代理头
   *
   * @return string 客户端 IP
   */
  public function ip(): string;

  /**
   * 批量获取请求参数，支持指定字段名与默认值
   *
   * @param string|array<string,mixed>|null $rule 字段名、[字段名 => 默认值] 或 [字段名, ...]；null 返回全部
   * @param bool $isShowNull 是否包含值为 null 的字段
   * @return array<string,mixed> 筛选后的参数关联数组
   */
  public function params(
    string|array|null $rule = null,
    bool              $isShowNull = true
  ): array;

  /**
   * 获取单个请求参数，自动合并 GET 与 POST，支持过滤器
   *
   * @param string|null $key 参数名，null 时返回全部参数
   * @param mixed $default 参数不存在时的默认值
   * @param string|array<string,mixed>|null $filter 过滤器函数名或回调，对返回值进行清洗
   * @return mixed 参数值或全部参数
   */
  public function param(
    ?string      $key = null,
    mixed        $default = null,
    string|array $filter = null
  ): mixed;

  /**
   * 获取基本身份验证票据
   *
   * @access public
   * @return array|null AssociativeArray(username,password)
   */
  public function getBasicAuthCredentials(): ?array;

  /**
   * 判断请求 Content-Type 是否为 JSON
   *
   * @return bool 是 JSON 请求返回 true
   */
  public function isJson(): bool;

  /**
   * 当前请求的资源类型
   *
   * @access public
   * @return string
   */
  public function getAcceptType(): string;

  /**
   * 获取 HTTP 协议版本
   *
   * @return string 协议版本号，如 "1.1"、"1.0"、"2"
   */
  public function getProtocolVersion(): string;

  /**
   * 获取请求目标（Request-Target），即请求行中的路径及查询字符串部分
   *
   * @return string 请求目标，如 /path?query=1
   */
  public function target(): string;

  /**
   * 获取消息的请求目标。
   *
   * @access public
   * @return string
   */
  public function getPath(): string;

  /**
   * 获取请求 URI 实例，包含 scheme、host、path、query 等组成部分
   *
   * @return Uri URI 实例
   */
  public function getUri(): Uri;

  /**
   * 判断是否https访问
   *
   * @access public
   * @return bool
   */
  public function https(): bool;

  /**
   * 通过给定的不区分大小写的名称检查标头是否存在。
   *
   * @access public
   * @param string $key
   * @return bool 如果任何标头名称使用不区分大小写的字符串比较与给定的标头名称匹配，则返回true。如果消息中没有找到匹配的标头名称，则返回false。
   */
  public function hasHeader(string $key): bool;

  /**
   * 使用提供的值替换指定标头的实例。(不存在会新增)
   *
   * @param string $name 不区分大小写的标头字段名称。
   * @param string $value 标头值。
   * @return static
   */
  public function setHeader(string $name, string $value): RequestInterface;

  /**
   * 向请求中注入额外参数，可指定注入到 GET、POST 或自动合并
   *
   * @param array<string,mixed> $params 要注入的参数键值对
   * @param string $type 注入目标：auto（合并到 param）、get（GET 参数）、post（POST 参数）
   */
  public function addParams(array $params, string $type = 'auto'): void;
}
