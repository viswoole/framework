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

namespace Viswoole\Cache\Driver;

use DateTime;
use InvalidArgumentException;
use Override;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Exception\CacheErrorException;

/**
 * 缓存标签实现，支持按标签分组管理缓存键的写入、清除与移除
 *
 * 每个标签维护一个 Set 集合存储属于该标签的所有缓存键，
 * 标签仓库（TagStore）则维护所有已注册标签的集合，用于全局标签查询。
 *
 * @see CacheTagInterface
 */
class Tag implements CacheTagInterface
{
  /**
   * @var array<string,string> 标签名到标签键的映射（原始标签名 → 带前缀的标签键）
   */
  protected array $tags;

  /**
   * 初始化标签实例，将标签名转换为带前缀的标签键
   *
   * @param string|array $tags 一个或多个标签名
   * @param CacheDriverInterface $driver 缓存驱动实例
   * @throws InvalidArgumentException 标签为空时抛出
   */
  public function __construct(
    string|array                   $tags,
    protected CacheDriverInterface $driver
  )
  {
    if (is_string($tags)) $tags = [$tags];
    // 修复问题#13：空标签集合语义不明，构造时验证标签不为空
    if (empty($tags)) {
      throw new InvalidArgumentException('标签不能为空');
    }
    foreach ($tags as $key => $tag) {
      $tags[$key] = $this->driver->getTagKey($tag);
    }
    $this->tags = $tags;
  }

  /**
   * 清除所有标签下的缓存数据及标签本身
   *
   * 遍历每个标签：删除标签关联的缓存键、删除标签集合、从标签仓库中移除标签。
   */
  #[Override] public function clear(): void
  {
    foreach ($this->tags as $tag) {
      $names = $this->driver->getArray($tag);
      // 修复问题#6：getArray 返回 false 时传给 delete 会导致错误，增加 false 检查
      if ($names !== false) $this->driver->delete($names);
      // 清除标签
      $this->driver->delete($tag);
      // 从标签库库中删除标签
      $this->driver->sRemoveArray($this->driver->getTagStoreName(), $tag);
    }
  }

  /**
   * 写入缓存值并自动将其键追加到当前标签集合中
   *
   * @param string $key 缓存键
   * @param mixed $value 缓存值
   * @param DateTime|int|null $expire 过期时间（秒），null 使用驱动默认值
   * @param bool $NX 是否仅在缓存不存在时写入
   * @return bool 写入成功返回 true
   */
  #[Override] public function set(
    string            $key,
    mixed             $value,
    DateTime|int|null $expire = null,
    bool              $NX = false
  ): bool
  {
    $result = $this->driver->set($key, $value, $expire, $NX);
    if ($result === false) return false;
    $this->push($key);
    return true;
  }

  /**
   * 将缓存键追加到所有当前标签的集合中，并注册标签到标签仓库
   *
   * @param string $key 缓存键
   */
  #[Override] public function push(string $key): void
  {
    foreach ($this->tags as $tag) {
      $this->driver->sAddArray($tag, $key);
    }
    $this->driver->sAddArray($this->driver->getTagStoreName(), $this->tags);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function remove(array|string $keys): void
  {
    if (is_string($keys)) $keys = [$keys];
    foreach ($this->tags as $tag) {
      // 从标签数据集中移除缓存标记key
      $result = $this->driver->sRemoveArray($tag, $keys);
      if ($result === false) {
        $keys = implode(',', $keys);
        throw new CacheErrorException("从标签集合中剔除{$keys}缓存失败");
      }
      // 判断标签数据集是否已为空，如果为空则把标签从标签总仓库数据集中移除
      $arr = $this->driver->getArray($tag);
      if (empty($arr)) $this->driver->sRemoveArray($this->driver->getTagStoreName(), $tag);
    }
    // 移除缓存数据
    $this->driver->delete($keys);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function get(): array
  {
    $arr = [];
    foreach ($this->tags as $tag => $tagKey) {
      $list = $this->driver->getArray($tagKey);
      $list = $list === false ? [] : $list;
      if (count($this->tags) === 1) return $list;
      $arr[$tag] = $list;
    }
    return $arr;
  }
}
