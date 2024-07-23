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


class Uri
{

  /**
   * @var string http协议
   */
  private string $scheme;
  /**
   * @var string 用户信息
   */
  private string $userInfo;
  /**
   * @var string 域名或ip
   */
  private string $host;
  /**
   * @var int|null 端口
   */
  private ?int $port;
  /**
   * @var string 访问资源路径
   */
  private string $path;
  /**
   * @var string 查询参数
   */
  private string $query;
  /**
   * @var string 片段部分 #xxx
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
   * 根据request创建URI对象
   * @param string $scheme
   * @param array|null $userInfo
   * @param string $host
   * @param int|null $port
   * @param string $path
   * @param string $query
   * @param string $fragment
   * @return static
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
   * 获取 URI 的协议方案部分（Scheme）。
   * 例如，对于 URI "http://example.com"，此方法返回 "http"。
   *
   * @access public
   * @return string
   */
  public function getScheme(): string
  {
    return $this->scheme;
  }

  /**
   * 获取 URI 的授权部分（Authority）。
   * 授权部分通常包括主机名和可选的端口号。
   * 例如，对于 URI "http://example.com:8080"，此方法返回 "example.com:8080"。
   *
   * @access public
   * @return string
   */
  public function getAuthority(): string
  {
    if (!empty($this->port)) {
      return "$this->host:$this->port";
    }
    return $this->host;
  }

  /**
   * 获取 URI 的用户信息部分。通常用于包含用户名和密码。
   * 例如，对于 URI "http://user:password@example.com"，此方法返回 "user:password"。
   * 从Authorization 请求头获取。
   *
   * @access public
   * @return string
   */
  public function getUserInfo(): string
  {
    return $this->userInfo;
  }

  /**
   * 获取 URI 的主机部分（Host）。
   * 主机部分通常包括主机名或 IP 地址。例如，对于 URI "http://example.com"，此方法返回 "example.com"。
   *
   * @access public
   * @return string
   */
  public function getHost(): string
  {
    return $this->host;
  }

  /**
   * 获取 URI 的端口部分（Port）。
   * 端口部分表示 URI 使用的端口号。
   * 例如，对于 URI "http://example.com:8080"，此方法返回 8080。
   *
   * @access public
   * @return int|null
   */
  public function getPort(): ?int
  {
    return $this->port;
  }

  /**
   * 获取 URI 的路径部分（Path）。路径部分表示资源在服务器上的路径。
   * 例如，对于 URI "http://example.com/path/to/resource"，此方法返回 "/path/to/resource"
   *
   * @access public
   * @return string
   */
  public function getPath(): string
  {
    return $this->path;
  }

  /**
   * 获取 URI 的查询部分（Query）。
   * 查询部分通常用于传递参数给资源。
   * 例如，对于 URI "http://example.com/resource?param1=value1&param2=value2"，
   * 此方法返回 "param1=value1&param2=value2"。
   *
   * @access public
   * @return string
   */
  public function getQuery(): string
  {
    return $this->query;
  }

  /**
   * 获取 URI 的片段部分（Fragment）。
   * 片段部分通常用于标识资源中的特定部分。
   * 例如，对于 URI "http://example.com/resource#section1"，此方法返回 "section1"。
   *
   * @access public
   * @return string
   */
  public function getFragment(): string
  {
    return $this->fragment;
  }

  /**
   * 创建一个新的 URI 对象，其中包含指定的协议部分。
   *
   * @access public
   * @param string $scheme
   * @return Uri
   */
  public function withScheme(string $scheme): Uri
  {
    $newInstance = clone $this;
    $newInstance->scheme = $scheme;
    return $newInstance;
  }

  /**
   * 创建一个新的 URI 对象，其中包含指定的用户信息部分。
   *
   * @access public
   * @param string $user 用户
   * @param string|null $password 密码
   * @return Uri
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
   * 创建一个新的 URI 对象，其中包含指定的主机部分。
   *
   * @access public
   * @param string $host
   * @return Uri
   */
  public function withHost(string $host): Uri
  {
    $newInstance = clone $this;
    $newInstance->host = $host;
    return $newInstance;
  }

  /**
   * 创建一个新的 URI 对象，其中包含指定的端口部分。
   *
   * @access public
   * @param int|null $port
   * @return Uri
   */
  public function withPort(?int $port): Uri
  {
    $newInstance = clone $this;
    $newInstance->port = $port;
    return $newInstance;
  }

  /**
   * 创建一个新的 URI 对象，其中包含指定的路径部分。
   *
   * @access public
   * @param string $path
   * @return Uri
   */
  public function withPath(string $path): Uri
  {
    $newInstance = clone $this;
    $newInstance->path = $path;
    return $newInstance;
  }

  /**
   * 创建一个新的 URI 对象，其中包含指定的查询部分。
   *
   * @access public
   * @param string $query
   * @return Uri
   */
  public function withQuery(string $query): Uri
  {
    $newInstance = clone $this;
    $newInstance->query = $query;
    return $newInstance;
  }

  /**
   * 创建一个新的 URI 对象，其中包含指定的片段部分。
   *
   * @access public
   * @param string $fragment
   * @return Uri
   */
  public function withFragment(string $fragment): Uri
  {
    $newInstance = clone $this;
    $newInstance->fragment = $fragment;
    return $newInstance;
  }

  /**
   * 返回 URI 的字符串表示形式。这是一个魔术方法，用于将 URI 对象转换为字符串。
   * http://example.com:8080/path#fragment?query=1
   *
   * @access public
   * @return string
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
