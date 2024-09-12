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
use FilesystemIterator;
use Override;
use Swoole\Coroutine\System;
use Throwable;
use Viswoole\Cache\Driver;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Core\Coroutine;

/**
 * 文件缓存驱动
 */
class File extends Driver
{
  public const string EXPIRE_PATTERN = '/^expire\((\d+)\)/';
  /**
   * @var string 存储目录
   */
  protected string $storage;
  /**
   * @var array 锁
   */
  private array $lockList = [];

  /**
   * @param string $storage 存储目录
   * @param string $prefix 前缀
   * @param string $tag_store 标签仓库名称(用于存储标签映射列表)
   * @param int $expire 过期时间 默认0不过期
   */
  public function __construct(
    string $storage = BASE_PATH . '/runtime/cache',
    string $prefix = '',
    string $tag_prefix = 'tag:',
    string $tag_store = 'TAG_STORE',
    int    $expire = 0
  )
  {
    $this->storage = rtrim($storage, '/');
    parent::__construct($prefix, $tag_prefix, $tag_store, $expire);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function inc(string $key, int $step = 1): false|int
  {
    $data = $this->get($key);
    if (is_float($data) || is_int($data)) {
      $data += $step;
      $result = $this->set($key, $data);
      return $result ? $data : false;
    } else {
      throw new CacheErrorException('缓存值非数值，不能调用自增方法。');
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function get(string $key, mixed $default = null): mixed
  {
    return $this->getRaw($key) ?? $default;
  }

  /**
   * 获取缓存内容
   *
   * @param string $key
   * @return mixed|null 返回null代表无缓存
   */
  protected function getRaw(string $key): mixed
  {
    $filename = $this->filename($key);
    if (!is_file($filename)) return null;
    $fileContent = @file_get_contents($filename);
    if ($fileContent === false) return null;
    if (preg_match(self::EXPIRE_PATTERN, $fileContent, $matches)) {
      $fileExpireTime = (int)$matches[1] - time();
      if ($fileExpireTime < 0) {
        // 文件已经过期，删除文件
        $this->unlock($filename);
        return null;
      } else {
        $fileContent = substr($fileContent, strlen($matches[0]));
      }
    }
    return $this->unserialize($fileContent);
  }

  /**
   * 获取文件名
   *
   * @param string $key 缓存标识
   * @return string
   */
  protected function filename(string $key): string
  {
    $key = $this->getCacheKey($key);
    return $this->dir() . $key;
  }

  /**
   * 获取存储目录
   *
   * @param string $dir
   * @return string
   */
  protected function dir(string $dir = ''): string
  {
    if (str_starts_with($dir, '/')) {
      $dir = $this->storage . $dir;
    } else {
      $dir = $this->storage . DIRECTORY_SEPARATOR . $dir;
    }
    // 创建目录（如果不存在）
    if (!is_dir(dirname($dir))) {
      mkdir($dir, 0755, true);
    }
    return str_ends_with($dir, DIRECTORY_SEPARATOR) ? $dir : $dir . DIRECTORY_SEPARATOR;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function unlock(string $id): bool
  {
    if (empty($this->lockList)) return false;
    if (!isset($this->lockList[$id])) return false;
    $lockInfo = $this->lockList[$id];
    $lockHandle = $lockInfo['lockHandle'];
    $secretKey = $lockInfo['secretKey'];
    if (is_resource($lockHandle)) {
      // 读取文件内容
      $fileContent = file_get_contents(stream_get_meta_data($lockHandle)['uri']);
      if ($fileContent === $secretKey) {
        // 如果文件内容与锁的secretKey匹配，解锁
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        unset($this->lockList[$id]);
        return true;
      } else {
        return false;
      }
    } else {
      return false;
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function set(
    string       $key,
    mixed        $value,
    DateTime|int $expire = null,
    bool         $NX = false
  ): bool
  {
    return $this->setRaw($key, $value, $expire, $NX);
  }

  /**
   * 写入缓存
   *
   * @param string $key
   * @param mixed $value 记录值
   * @param DateTime|int|null $expire 过期时间
   * @param bool $NX 如果不存在则写入
   * @return bool
   */
  protected function setRaw(
    string       $key,
    mixed        $value,
    DateTime|int $expire = null,
    bool         $NX = false
  ): bool
  {
    $filename = $this->filename($key);
    $data = $this->serialize($value);
    $expire = $expire === null ? $this->expire : $this->formatExpireTime($expire);
    // 判断是否需要设置过期时间
    if ($expire > 0) {
      $expire = time() + $expire;
      $data = "expire($expire)$data";
    }
    if ($NX) {
      // 如果是文件不存在则写入
      if (!is_file($filename)) {
        $result = file_put_contents($filename, $data, LOCK_EX | LOCK_NB);
      } else {
        // 文件存在则判断文件是否过期，过期则写入
        $content = file_get_contents($filename);
        if ($this->hasExpire($content) === true) {
          $result = file_put_contents($filename, $data, LOCK_EX | LOCK_NB);
        } else {
          $result = false;
        }
      }
    } else {
      $result = file_put_contents($filename, $data);
    }
    clearstatcache();
    return (bool)$result;
  }

  /**
   * 判断是否过期
   *
   * @param string $fileContent
   * @return true|int 返回true代表已过期，返回-1则没有过期时间，返回其他数字代表剩余过期时间
   */
  protected function hasExpire(string $fileContent): true|int
  {
    if (preg_match(self::EXPIRE_PATTERN, $fileContent, $matches)) {
      $fileExpireTime = (int)$matches[1] - time();
      if ($fileExpireTime > 0) {
        return $fileExpireTime;
      } else {
        return true;
      }
    }
    return -1;
  }

  /**
   * @inheritDoc
   */
  public function ttl(string $key): false|int
  {
    $filename = $this->filename($key);
    if (!is_file($filename)) return false;
    $fileContent = file_get_contents($filename);
    $expire = $this->hasExpire($fileContent);
    if ($expire === true) return false;
    return $expire;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function dec(string $key, int $step = 1): false|int
  {
    $data = $this->get($key);
    if (is_float($data) || is_int($data)) {
      $data -= $step;
      $result = $this->set($key, $data);
      return $result ? $data : false;
    } else {
      throw new CacheErrorException('缓存值非数值，不能调用自减方法。');
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function pull(string $key): mixed
  {
    $result = $this->get($key, false);

    if ($result !== false) $this->delete($key);
    return $result;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function delete(array|string $keys): false|int
  {
    if (is_string($keys)) $keys = [$keys];
    $number = 0;
    foreach ($keys as $name) {
      $filename = $this->filename($name);
      $result = $this->unlink($filename);
      if ($result) $number++;
    }
    return $number === 0 ? false : $number;
  }

  /**
   * 删除文件
   *
   * @param string $path
   * @return bool
   */
  protected function unlink(string $path): bool
  {
    try {
      $result = is_file($path) && unlink($path);
      $dir = dirname($path);
      // 如果目录为空，删除目录
      if (count(glob($dir . '/*')) === 0) rmdir($dir);
      return $result;
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function has(string $key): bool
  {
    return is_file($this->filename($key));
  }

  /**
   * @inheritDoc
   */
  #[Override] public function clear(): bool
  {
    return $this->rmdir($this->dir());
  }

  /**
   * 删除目录
   *
   * @param string $dirname
   * @return bool
   */
  protected function rmdir(string $dirname): bool
  {
    if (!is_dir($dirname)) return true;

    $items = new FilesystemIterator($dirname);

    foreach ($items as $item) {
      if ($item->isDir()) {
        $this->rmdir($item->getPathname());
      } else {
        $this->unlink($item->getPathname());
      }
    }
    return !is_dir($dirname) || rmdir($dirname);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function lock(
    string    $scene,
    int       $expire = 10,
    bool      $autoUnlock = false,
    int       $retry = 5,
    float|int $sleep = 0.2
  ): string
  {
    $expire = $expire <= 0 ? null : time() + $expire;

    if ($retry <= 0) $retry = 1;

    $result = false;

    $scene = $this->getLockKey($scene);

    $filename = $this->getLockFilename($scene);

    $lockId = md5(uniqid("{$scene}_", true) . '_' . Coroutine::getCid());

    $data = $expire ? "expire($expire)$lockId" : $lockId;

    while ($retry-- > 0) {

      $lockHandle = fopen($filename, 'w');

      if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
        // 读取文件内容
        $fileContent = file_get_contents(stream_get_meta_data($lockHandle)['uri']);
        //如果上一个锁还未过期则取锁失败
        if ($this->hasExpire($fileContent) === true) break;
        // 将锁ID写入锁文件
        fwrite($lockHandle, $data);
        // 刷新文件缓冲区
        fflush($lockHandle);
        // 记录到锁列表
        $this->lockList[$lockId] = [
          'scene' => $scene,
          'secretKey' => $data,
          'autoUnlock' => $autoUnlock,
          'lockHandle' => $lockHandle
        ];
        $result = true;
        break;
      } else {
        //未获得锁 休眠
        System::sleep($sleep);
      }
    }
    if ($result === false) throw new CacheErrorException('缓存系统繁忙，请稍后重试');
    return $lockId;
  }

  /**
   * 获取锁文件
   *
   * @param $scene
   * @return string
   */
  private function getLockFilename($scene): string
  {
    $dir = $this->dir('/lock');
    return $dir . $this->getCacheKey($scene);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function close(): void
  {
    foreach ($this->lockList as $lockId => $lockInfo) {
      if ($lockInfo['autoUnlock']) $this->unlock($lockId);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function connect(): File
  {
    return $this;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sAddArray(
    string       $key,
    array|string $values,
  ): false|int
  {
    if (is_string($values)) $values = [$values];

    $oldArray = $this->getArray($key);

    $oldArray = $oldArray === false ? [] : $oldArray;

    $newArray = array_merge($oldArray, $values);

    $oldLen = count($oldArray);

    $newLen = count($newArray);

    if ($oldLen === $newLen) return false;
    $result = $this->set($key, $newArray);
    return $result ? $newLen - $oldLen : false;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getArray(string $key): array|false
  {
    return $this->get($key, []);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sRemoveArray(
    string       $key,
    array|string $values,
  ): false|int
  {
    $array = $this->getArray($key);
    if (empty($array)) return 0;
    if (is_string($values)) $values = [$values];
    $newArray = array_filter($array, function ($value) use ($values, &$count) {
      return !in_array($value, $values);
    });
    $count = count($array) - count($newArray);
    $result = $this->set($key, $newArray);
    return $result ? $count : false;
  }
}
