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
use Viswoole\HttpServer\Message\UploadedFile;
use Viswoole\HttpServer\Message\Uri;

/**
 * 请求对象
 *
 * @link https://wiki.swoole.com/zh-cn/#/http_server?id=swoolehttprequest
 */
interface RequestInterface
{
  /**
   * 创建一个新的RequestInterface对象
   *
   * @access public
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
  public static function create(array $options = []): RequestInterface;

  /**
   * 获取请求标头
   *
   * @access public
   * @param string|null $key 如果传入key则获取单个请求标头，否则获取所有标头
   * @return array|string|null 存在返回标头值，不存在返回null
   */
  public function getHeader(?string $key = null, mixed $default = null): array|string|null;

  /**
   * 获取服务参数
   *
   * @access public
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
  public function getServer(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取post参数
   *
   * @access public
   * @param string|null $key 要获取的参数
   * @param mixed|null $default 默认值
   * @return mixed
   */
  public function post(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取get参数
   *
   * @access public
   * @param string|null $key 要获取的参数
   * @param mixed|null $default 不存在时返回默认值
   * @return mixed
   */
  public function get(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取cookie
   *
   * @param string|null $key 要获取的Cookie名称，不传获取所有cookie关联数组
   * @param mixed|null $default 不存在返回默认值
   * @return mixed
   */
  public function cookie(?string $key = null, mixed $default = null): mixed;

  /**
   * 获取上传的文件
   *
   * @access public
   * @param string|null $key 可选文件名称
   * @return UploadedFile|UploadedFile[]|null|array<string, UploadedFile|UploadedFile[]>
   */
  public function files(?string $key = null): UploadedFile|array|null;

  /**
   * 获取POST包体，此函数等同于 PHP 的 fopen('php://input')。
   *
   * @access public
   * @return string|false 返回原始POST数据，失败返回false
   */
  public function getContent(): string|false;

  /**
   * 获取完整的原始 Http 请求报文，注意 Http2 下无法使用。包括 Http Header 和 Http Body
   *
   * @access public
   * @return string|false 执行成功返回报文，如果上下文连接不存在或者在 Http2 模式下返回 false
   */
  public function getData(): string|false;

  /**
   * 解析 HTTP 请求数据包，会返回成功解析的数据包长度。
   *
   * @access public
   * @param string $data
   * @return int 解析成功返回解析的报文长度
   * @throws RuntimeException 解析失败
   */
  public function parse(string $data): int;

  /**
   * 获取当前的 HTTP 请求数据包是否已到达结尾。
   *
   * @access public
   * @return bool
   */
  public function isCompleted(): bool;

  /**
   * 获取当前的 HTTP 请求的请求方式。
   *
   * @access public
   * @return string 成返回大写的请求方式
   */
  public function getMethod(): string;

  /**
   * 获取客户端ip
   *
   * @access public
   * @return string
   */
  public function ip(): string;

  /**
   * 批量获取请求参数
   *
   * @access public
   * @param string|array|null $rule 可传key或[key=>default,...]或[key1,key2....]
   * @param bool $isShowNull 是否显示为null的字段
   * @return array
   */
  public function params(
    string|array|null $rule = null,
    bool              $isShowNull = true
  ): array;

  /**
   * 获取请求参数，自动判断get或post
   *
   * @access public
   * @param string|null $key 字段，不传则获取全部
   * @param mixed $default 默认值
   * @param string|array|null $filter 过滤器
   * @return mixed
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
   * 当前是否JSON请求
   *
   * @access public
   * @return bool
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
   * 获取HTTP协议版本。
   *
   * @access public
   * @return string HTTP协议版本。例如，"1.1"，"1.0","2"
   */
  public function getProtocolVersion(): string;

  /**
   * 获取消息的请求目标。
   *
   * @access public
   * @return string
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
   * 检索 URI 实例。
   *
   * @access public
   * @return Uri 返回表示请求 URI 的 UriInterface 实例。
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
   * 添加/修改请求参数
   *
   * @param array<string,mixed> $params 参数名称
   * @param string $type 参数类型，可选值auto,get,post。
   * @return void
   */
  public function addParams(array $params, string $type = 'auto'): void;
}
