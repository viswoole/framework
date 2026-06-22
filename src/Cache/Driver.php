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
use InvalidArgumentException;
use Override;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Driver\Tag;

/**
 * 缓存驱动抽象基类，提供缓存键前缀、标签、序列化及过期时间等通用能力
 *
 * 各具体驱动（File、Redis 等）继承此类并实现差异化的存储逻辑。
 * 基类封装了标签管理、锁键生成、序列化/反序列化等横切关注点。
 *
 * @see CacheDriverInterface
 */
abstract class Driver implements CacheDriverInterface
{
  /**
   * @var string 缓存键前缀，用于隔离不同应用的缓存命名空间
   */
  protected string $prefix = '';
  /**
   * @var int 默认缓存过期时间（秒），0 表示永不过期
   */
  protected int $expire = 0;
  /**
   * @var string 标签仓库的缓存键名，用于存储所有已注册标签的集合
   */
  protected string $tag_store = 'TAG_STORE';
  /**
   * @var string 标签键前缀，用于区分标签键与普通缓存键
   */
  protected string $tag_prefix = 'tag:';
  /**
   * @var array{get:callable,set:callable} 序列化与反序列化回调配置
   */
  protected array $serialize = [
    'get' => 'unserialize',
    'set' => 'serialize'
  ];

  /**
   * 初始化缓存驱动通用配置
   *
   * @param string $prefix 缓存键前缀，用于命名空间隔离
   * @param string $tag_prefix 标签键前缀标识
   * @param string $tag_store 标签仓库键名，存储所有已注册标签集合
   * @param int $expire 默认过期时间（秒），0 表示永不过期
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
   * 自定义序列化与反序列化回调
   *
   * 用于替换默认的 PHP serialize/unserialize，例如切换为 JSON 或 igBinary。
   * 传入的回调必须为可调用结构，否则抛出异常。
   *
   * @param string|Closure $set 序列化回调，将值转为可存储的字符串
   * @param string|Closure $get 反序列化回调，将存储的字符串还原为原始值
   * @return $this 支持链式调用
   * @throws InvalidArgumentException 回调不可调用时抛出
   */
  #[Override] public function setSerialize(
    string|Closure $set = 'serialize',
    string|Closure $get = 'unserialize'
  ): static
  {
    // 修复问题#17：serialize 闭包调用缺少 is_callable 验证，
    // 避免传入不可调用的值导致后续 serialize/unserialize 调用时致命错误
    if (!is_callable($set) || !is_callable($get)) {
      throw new InvalidArgumentException('序列化回调必须为可调用结构');
    }
    $this->serialize = [
      'set' => $set,
      'get' => $get
    ];
    return $this;
  }

  /**
   * 根据标签名生成带前缀的标签键
   *
   * @param string $tag 原始标签名
   * @return string 带前缀的标签键
   */
  #[Override] public function getTagKey(string $tag): string
  {
    return $this->tag_prefix . $tag;
  }

  /**
   * 创建缓存标签实例，用于按标签批量管理缓存
   *
   * @param string|array $tag 一个或多个标签名
   * @return CacheTagInterface 标签操作实例
   */
  #[Override] public function tag(array|string $tag): CacheTagInterface
  {
    return new Tag($tag, $this);
  }

  /**
   * 获取所有已注册的缓存标签集合
   *
   * @return array|false 标签列表，标签仓库不存在时返回 false
   */
  #[Override] public function getTags(): array|false
  {
    return $this->getArray($this->getTagStoreName());
  }

  /**
   * 获取标签仓库的完整缓存键名（含前缀）
   *
   * @return string 标签仓库键名
   */
  #[Override] public function getTagStoreName(): string
  {
    return $this->prefix . $this->tag_store;
  }

  /**
   * 析构时自动关闭连接句柄，释放资源
   */
  #[Override] public function __destruct()
  {
    $this->close();
  }

  /**
   * 使用配置的序列化回调将数据转为可存储格式
   *
   * @param mixed $data 待序列化的缓存数据
   * @return mixed 序列化后的数据
   */
  protected function serialize(mixed $data): mixed
  {
    $serialize = $this->serialize['set'] ?? 'serialize';
    return $serialize($data);
  }

  /**
   * 使用配置的反序列化回调将存储数据还原为原始值
   *
   * @param mixed $data 已序列化的缓存数据
   * @return mixed 反序列化后的原始数据
   */
  protected function unserialize(mixed $data): mixed
  {
    $unserialize = $this->serialize['get'] ?? 'unserialize';
    return $unserialize($data);
  }

  /**
   * 根据锁场景生成带前缀的锁缓存键
   *
   * @param string $scene 业务场景标识
   * @return string 锁缓存键
   */
  protected function getLockKey(string $scene): string
  {
    return $this->getCacheKey('lock_' . $scene);
  }

  /**
   * 根据缓存键生成带前缀的完整缓存标识
   *
   * @param string $key 原始缓存键
   * @return string 含前缀的完整缓存键
   */
  #[Override] public function getCacheKey(string $key): string
  {
    return $this->prefix . $key;
  }

  /**
   * 将过期时间统一转换为秒数
   *
   * 支持 DateTime 对象（计算距当前时间的剩余秒数）和整数秒数两种格式。
   *
   * @param DateTime|int $expire 过期时间，DateTime 表示绝对时间点，int 表示相对秒数
   * @return int 过期秒数
   */
  protected function formatExpireTime(DateTime|int $expire): int
  {
    if ($expire instanceof DateTime) {
      $expire = $expire->getTimestamp() - time();
    }
    return (int)$expire;
  }
}
