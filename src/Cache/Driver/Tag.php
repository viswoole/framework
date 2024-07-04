<?php
/*
 *  +----------------------------------------------------------------------
 *  | ViSwoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Cache\Driver;

use DateTime;
use Override;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Exception\CacheErrorException;

/**
 * 标签
 */
class Tag implements CacheTagInterface
{
  protected array $tags;

  /**
   * @param string|array $tags 标签
   * @param CacheDriverInterface $driver 驱动
   */
  public function __construct(
    string|array                   $tags,
    protected CacheDriverInterface $driver
  )
  {
    if (is_string($tags)) $tags = [$tags];
    foreach ($tags as $key => $tag) {
      $tags[$key] = $this->driver->getTagKey($tag);
    }
    $this->tags = $tags;
  }

  /**
   * 清除标签缓存
   *
   * @access public
   * @return void
   */
  #[Override] public function clear(): void
  {
    foreach ($this->tags as $tag) {
      $names = $this->driver->getArray($tag);
      // 清除标签下的缓存
      $this->driver->delete($names);

      // 清除标签
      $this->driver->delete($tag);

      // 从标签库库中删除标签
      $this->driver->sRemoveArray($this->driver->getTagStoreName(), $tag);
    }
  }

  /**
   * 写入缓存
   *
   * @access public
   * @param string $key 缓存变量名
   * @param mixed $value 存储数据
   * @param DateTime|int|null $expire 有效时间（秒）
   * @param bool $NX
   * @return bool
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
    return $this->push($key);
  }

  /**
   * 追加缓存标识到标签
   *
   * @access public
   * @param string $key
   * @return bool
   */
  #[Override] public function push(string $key): bool
  {
    foreach ($this->tags as $tag) {
      // 把缓存键名添加到集合中
      $result = $this->driver->sAddArray($tag, $key);
      if ($result === false) {
        throw new CacheErrorException('往标签集合中追加缓存键名失败');
      }
    }
    // 把标签设置到缓存标签集合中
    $result = $this->driver->sAddArray($this->driver->getTagStoreName(), $this->tags);
    if ($result === false) {
      throw new CacheErrorException('往标签集合中追加缓存键名失败');
    }
    return true;
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
      // 移除缓存数据
      $this->driver->delete($keys);
      // 判断标签数据集是否已为空，如果为空则把标签从标签总仓库数据集中移除
      $arr = $this->driver->getArray($tag);
      if (empty($arr)) $this->driver->sRemoveArray($this->driver->getTagStoreName(), $tag);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function get(): array
  {
    $arr = [];
    foreach ($this->tags as $tag) {
      $list = $this->driver->get($tag, []);
      if (count($this->tags) === 1) return $list;
      $arr[$tag] = $list;
    }
    return $arr;
  }
}
