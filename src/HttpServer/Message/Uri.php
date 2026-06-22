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

namespace Viswoole\HttpServer\Message;


/**
 * URI 值对象
 *
 * 不可变的 URI 组件容器，支持 scheme、userInfo、host、port、path、query、fragment
 * 各部分的获取与 with* 系列修改方法（返回新实例）。
 */
class Uri
{

  /**
   * @var string 协议方案（http/https）
   */
  private string $scheme;
  /**
   * @var string 用户信息（user:password 格式）
   */
  private string $userInfo;
  /**
   * @var string 主机名或 IP 地址
   */
  private string $host;
  /**
   * @var int|null 端口号，null 表示使用协议默认端口
   */
  private ?int $port;
  /**
   * @var string 资源路径
   */
  private string $path;
  /**
   * @var string 查询字符串（不含 ?）
   */
  private string $query;
  /**
   * @var string 片段标识（不含 #）
   */
  private string $fragment;

  public function __construct(
    string $scheme = '',
    ?array $userInfo = [],
    string $host = '',
    ?int   $port = null,
    string $path = '',
    string $query = '',
    string $fragment = ''
  )
  {
    $this->scheme = $scheme;
    $this->userInfo = empty($userInfo) ? '' : implode(':', $userInfo);
    $this->host = $host;
    $this->port = $port;
    $this->path = $path;
    $this->query = $query;
    $this->fragment = $fragment;
  }

  /**
   * 工厂方法：根据各组件创建 URI 实例
   *
   * @param string $scheme 协议方案
   * @param array|null $userInfo [user, password] 二元数组
   * @param string $host 主机名
   * @param int|null $port 端口号
   * @param string $path 资源路径
   * @param string $query 查询字符串
   * @param string $fragment 片段标识
   * @return static 新的 URI 实例
   */
  public static function create(
    string $scheme = '',
    ?array $userInfo = [],
    string $host = '',
    ?int   $port = null,
    string $path = '',
    string $query = '',
    string $fragment = ''
  ): static
  {
    return new static(
      scheme  : $scheme,
      userInfo: $userInfo,
      host    : $host,
      port    : $port,
      path    : $path,
      query   : $query,
      fragment: $fragment
    );
  }

  /**
   * 获取协议方案
   *
   * @return string 如 'http' 或 'https'
   */
  public function getScheme(): string
  {
    return $this->scheme;
  }

  /**
   * 获取授权部分（host:port）
   *
   * @return string 如 'example.com:8080'，端口为默认值时省略
   */
  public function getAuthority(): string
  {
    if (!empty($this->port)) {
      return "$this->host:$this->port";
    }
    return $this->host;
  }

  /**
   * 获取用户信息部分（从 Authorization 标头解析）
   *
   * @return string 如 'user:password'
   */
  public function getUserInfo(): string
  {
    return $this->userInfo;
  }

  /**
   * 获取主机名或 IP 地址
   *
   * @return string 如 'example.com'
   */
  public function getHost(): string
  {
    return $this->host;
  }

  /**
   * 获取端口号
   *
   * @return int|null 端口号，null 表示使用协议默认端口
   */
  public function getPort(): ?int
  {
    return $this->port;
  }

  /**
   * 获取资源路径
   *
   * @return string 如 '/path/to/resource'
   */
  public function getPath(): string
  {
    return $this->path;
  }

  /**
   * 获取查询字符串（不含 ?）
   *
   * @return string 如 'param1=value1&param2=value2'
   */
  public function getQuery(): string
  {
    return $this->query;
  }

  /**
   * 获取片段标识（不含 #）
   *
   * @return string 如 'section1'
   */
  public function getFragment(): string
  {
    return $this->fragment;
  }

  /**
   * 返回修改协议方案后的新 URI 实例
   *
   * @param string $scheme 协议方案
   * @return Uri 新实例
   */
  public function withScheme(string $scheme): Uri
  {
    $newInstance = clone $this;
    $newInstance->scheme = $scheme;
    return $newInstance;
  }

  /**
   * 返回修改用户信息后的新 URI 实例
   *
   * @param string $user 用户名
   * @param string|null $password 密码，为空时仅保留用户名
   * @return Uri 新实例
   */
  public function withUserInfo(string $user, ?string $password = null): Uri
  {
    $newInstance = clone $this;
    if (empty($password)) {
      $newInstance->userInfo = $user;
    } else {
      $newInstance->userInfo = "$user:$password";
    }
    return $newInstance;
  }

  /**
   * 返回修改主机名后的新 URI 实例
   *
   * @param string $host 主机名或 IP
   * @return Uri 新实例
   */
  public function withHost(string $host): Uri
  {
    $newInstance = clone $this;
    $newInstance->host = $host;
    return $newInstance;
  }

  /**
   * 返回修改端口号后的新 URI 实例
   *
   * @param int|null $port 端口号，null 表示使用协议默认端口
   * @return Uri 新实例
   */
  public function withPort(?int $port): Uri
  {
    $newInstance = clone $this;
    $newInstance->port = $port;
    return $newInstance;
  }

  /**
   * 返回修改资源路径后的新 URI 实例
   *
   * @param string $path 资源路径
   * @return Uri 新实例
   */
  public function withPath(string $path): Uri
  {
    $newInstance = clone $this;
    $newInstance->path = $path;
    return $newInstance;
  }

  /**
   * 返回修改查询字符串后的新 URI 实例
   *
   * @param string $query 查询字符串（不含 ?）
   * @return Uri 新实例
   */
  public function withQuery(string $query): Uri
  {
    $newInstance = clone $this;
    $newInstance->query = $query;
    return $newInstance;
  }

  /**
   * 返回修改片段标识后的新 URI 实例
   *
   * @param string $fragment 片段标识（不含 #）
   * @return Uri 新实例
   */
  public function withFragment(string $fragment): Uri
  {
    $newInstance = clone $this;
    $newInstance->fragment = $fragment;
    return $newInstance;
  }

  /**
   * 将 URI 转换为字符串表示
   *
   * 格式：scheme://host[:port][path][?query][#fragment]
   *
   * @return string 完整 URI 字符串
   */
  public function __toString(): string
  {
    $uri = "$this->scheme://$this->host";
    if (!empty($this->port)) {
      $uri .= ":$this->port";
    }
    if ($this->path !== '/') {
      $uri .= $this->path;
    }
    if (!empty($this->query)) {
      $uri .= "?$this->query";
    }
    if (!empty($this->fragment)) {
      $uri .= "#$this->fragment";
    }
    return $uri;
  }
}
