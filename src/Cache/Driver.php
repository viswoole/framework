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

namespace Viswoole\Cache;

use Closure;
use DateTime;
use Override;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Driver\Tag;

abstract class Driver implements CacheDriverInterface
{
  /**
   * @var string 缓存前缀
   */
  protected string $prefix = '';
  /**
   * @var int 缓存默认的过期时间
   */
  protected int $expire = 0;
  /**
   * @var string 缓存标签库名称
   */
  protected string $tag_store = 'TAG_STORE';
  /**
   * @var string 标签前缀
   */
  protected string $tag_prefix = 'tag:';
  /**
   * @var array 序列化
   */
  protected array $serialize = [
    'get' => 'unserialize',
    'set' => 'serialize'
  ];

  /**
   * @param string $prefix 缓存前缀
   * @param string $tag_prefix 缓存标签前缀标识
   * @param string $tag_store 标签仓库
   * @param int $expire 缓存过期时间
   */
  public function __construct(
    string $prefix = '',
    string $tag_prefix = 'tag:',
    string $tag_store = 'TAG_STORE',
    int    $expire = 0
  )
  {
    if (!empty($tag_store)) $this->tag_store = $tag_store;
    $this->tag_prefix = $tag_prefix;
    $this->prefix = $prefix;
    $this->expire = $expire;
  }

  /**
   * 设置序列化方法
   *
   * @access public
   * @param string|Closure $set
   * @param string|Closure $get
   * @return $this
   */
  #[Override] public function setSerialize(
    string|Closure $set = 'serialize',
    string|Closure $get = 'unserialize'
  ): static
  {
    $this->serialize = [
      'set' => $set,
      'get' => $get
    ];
    return $this;
  }

  /**
   * 获取实际标签名
   *
   * @access public
   * @param string $tag 标签名
   * @return string
   */
  #[Override] public function getTagKey(string $tag): string
  {
    return $this->tag_prefix . $tag;
  }

  /**
   * 标签
   *
   * @access public
   * @param string|array $tag
   * @return CacheTagInterface
   */
  #[Override] public function tag(array|string $tag): CacheTagInterface
  {
    return new Tag($tag, $this);
  }

  /**
   * 获取所有缓存标签
   *
   * @access public
   * @return array|false
   */
  #[Override] public function getTags(): array|false
  {
    return $this->getArray($this->getTagStoreName());
  }

  /**
   * 获取标签仓库名称
   *
   * @return string
   */
  #[Override] public function getTagStoreName(): string
  {
    return $this->prefix . $this->tag_store;
  }

  /**
   * 销毁时自动调用close方法关闭句柄
   */
  #[Override] public function __destruct()
  {
    $this->close();
  }

  /**
   * 序列化数据
   * @access protected
   * @param mixed $data 缓存数据
   * @return mixed
   */
  protected function serialize(mixed $data): mixed
  {
    $serialize = $this->serialize['set'] ?? 'serialize';
    return $serialize($data);
  }

  /**
   * 反序列化数据
   * @access protected
   * @param mixed $data 缓存数据
   * @return mixed
   */
  protected function unserialize(mixed $data): mixed
  {
    $unserialize = $this->serialize['get'] ?? 'unserialize';
    return $unserialize($data);
  }

  /**
   * 获取锁缓存名
   *
   * @param string $scene
   * @return string
   */
  protected function getLockKey(string $scene): string
  {
    return $this->getCacheKey('lock_' . $scene);
  }

  /**
   * 获取实际的缓存标识
   *
   * @access public
   * @param string $key 缓存名
   * @return string
   */
  #[Override] public function getCacheKey(string $key): string
  {
    return $this->prefix . $key;
  }

  /**
   * 获取有效期
   *
   * @access protected
   * @param DateTime|int $expire 有效期
   * @return int 秒
   */
  protected function formatExpireTime(DateTime|int $expire): int
  {
    if ($expire instanceof DateTime) {
      $expire = $expire->getTimestamp() - time();
    }
    return (int)$expire;
  }
}
