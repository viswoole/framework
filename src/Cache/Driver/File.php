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
 * 基于文件系统的缓存驱动，将缓存数据以序列化形式写入磁盘文件
 *
 * 通过在文件头部嵌入过期时间戳实现 TTL 管理，支持文件锁实现竞争锁机制。
 * 适用于无 Redis 等外部服务的轻量场景，性能低于内存型驱动。
 *
 * @see Driver
 */
class File extends Driver
{
  public const string EXPIRE_PATTERN = '/^expire\((\d+)\)/';
  /**
   * @var string 缓存文件存储根目录
   */
  protected string $storage;
  /**
   * @var array<string,array{scene:string,secretKey:string,autoUnlock:bool,lockHandle:resource}> 当前持有锁的列表
   */
  private array $lockList = [];

  /**
   * 初始化文件缓存驱动
   *
   * @param string $storage 缓存文件存储根目录
   * @param string $prefix 缓存键前缀
   * @param string $tag_prefix 标签键前缀标识
   * @param string $tag_store 标签仓库键名
   * @param int $expire 默认过期时间（秒），0 表示永不过期
   */
  public function __construct(
    string $storage = BASE_PATH . '/runtime/cache',
    string $prefix = '',
    string $tag_prefix = 'tag:',
    string $tag_store = 'TAG_STORE',
    int    $expire = 0
  ) {
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
   * 读取缓存原始内容（含过期检测与自动清理）
   *
   * 读取文件后检查头部过期时间戳，已过期则删除文件并返回 null。
   *
   * @param string $key 缓存键
   * @return mixed|null 缓存值，不存在或已过期返回 null
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
        // 修复：文件已过期应调用 unlink 删除文件，原代码错误调用了 unlock（解锁）
        $this->unlink($filename);
        return null;
      } else {
        $fileContent = substr($fileContent, strlen($matches[0]));
      }
    }
    return $this->unserialize($fileContent);
  }

  /**
   * 根据缓存键生成完整的文件路径
   *
   * @param string $key 缓存标识
   * @return string 缓存文件完整路径
   */
  protected function filename(string $key): string
  {
    $key = $this->getCacheKey($key);
    return $this->dir() . $key;
  }

  /**
   * 获取存储目录路径，目录不存在时自动创建
   *
   * @param string $dir 相对或绝对子目录路径
   * @return string 以目录分隔符结尾的目录路径
   * @throws CacheErrorException 创建目录失败时抛出
   */
  protected function dir(string $dir = ''): string
  {
    if (str_starts_with($dir, '/')) {
      $dir = $this->storage . $dir;
    } else {
      $dir = $this->storage . DIRECTORY_SEPARATOR . $dir;
    }
    // 创建目录（如果不存在）
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0755, true)) {
        throw new CacheErrorException('创建缓存目录失败：' . $dir);
      }
    }
    return str_ends_with($dir, DIRECTORY_SEPARATOR) ? $dir : $dir . DIRECTORY_SEPARATOR;
  }

  /**
   * 安全删除缓存文件，并在目录为空时清理空目录
   *
   * @param string $path 文件路径
   * @return bool 删除成功返回 true
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
  #[Override] public function set(
    string       $key,
    mixed        $value,
    DateTime|int|null $expire = null,
    bool         $NX = false
  ): bool {
    return $this->setRaw($key, $value, $expire, $NX);
  }

  /**
   * 写入缓存数据到文件，支持 NX（仅不存在时写入）和过期时间
   *
   * 文件内容格式为 `expire(时间戳)序列化数据`，无过期时间时省略头部。
   *
   * @param string $key 缓存键
   * @param mixed $value 缓存值
   * @param DateTime|int|null $expire 过期时间，null 使用驱动默认值
   * @param bool $NX 是否仅在缓存不存在时写入
   * @return bool 写入成功返回 true
   */
  protected function setRaw(
    string       $key,
    mixed        $value,
    DateTime|int|null $expire = null,
    bool         $NX = false
  ): bool {
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
   * 检测文件内容是否已过期
   *
   * @param string $fileContent 文件原始内容
   * @return true|int true 表示已过期，-1 表示无过期时间，其他正整数表示剩余秒数
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
    if ($expire === true) {
      $this->unlink($filename);
      return false;
    }
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
   * @inheritDoc
   */
  #[Override] public function has(string $key): bool
  {
    return $this->getRaw($key) !== null;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function clear(): bool
  {
    return $this->rmdir($this->dir());
  }

  /**
   * 递归删除指定目录及其下所有缓存文件
   *
   * @param string $dirname 目录路径
   * @return bool 清理成功返回 true
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
  ): string {
    $expire = $expire <= 0 ? null : time() + $expire;

    if ($retry <= 0) $retry = 1;

    $result = false;

    $scene = $this->getLockKey($scene);

    $filename = $this->getLockFilename($scene);

    $lockId = md5(uniqid("{$scene}_", true) . '_' . Coroutine::getCid());

    $data = $expire ? "expire($expire)$lockId" : $lockId;

    while ($retry-- > 0) {

      // 修复：使用 'c+' 模式打开文件，避免 'w' 模式截断已有锁文件内容导致无法判断上一个锁状态
      $lockHandle = fopen($filename, 'c+');
      if ($lockHandle === false) {
        System::sleep($sleep);
        continue;
      }

      if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
        // flock 成功意味着没有其他进程持有锁
        // 读取文件内容，仅用于判断上一个锁是否已过期（处理进程崩溃未释放锁的情况）
        $fileContent = stream_get_contents($lockHandle, 0, 0);
        // 仅当上一个锁有过期时间且未过期时才重试；无过期时间(-1)或已过期(true)都应获取锁
        $expireStatus = $fileContent === '' ? true : $this->hasExpire($fileContent);
        if ($expireStatus !== true && $expireStatus > 0) {
          // 上一个锁有过期时间且未过期，取锁失败，释放当前锁并关闭句柄后重试
          flock($lockHandle, LOCK_UN);
          fclose($lockHandle);
          System::sleep($sleep);
          continue;
        }
        // 将锁ID写入锁文件，先截断旧内容再写入
        ftruncate($lockHandle, 0);
        rewind($lockHandle);
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
        // 修复：未获得锁时关闭句柄避免文件句柄泄漏，再休眠重试
        fclose($lockHandle);
        System::sleep($sleep);
      }
    }
    if ($result === false) throw new CacheErrorException('缓存系统繁忙，请稍后重试');
    return $lockId;
  }

  /**
   * 生成锁文件的完整路径
   *
   * @param string $scene 锁场景标识
   * @return string 锁文件路径
   */
  private function getLockFilename(string $scene): string
  {
    $dir = $this->dir('/lock');
    return $dir . $scene;
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
  #[Override] public function unlock(string $id): bool
  {
    if (empty($this->lockList)) return false;
    if (!isset($this->lockList[$id])) return false;
    $lockInfo = $this->lockList[$id];
    $lockHandle = $lockInfo['lockHandle'];
    $secretKey = $lockInfo['secretKey'];
    if (is_resource($lockHandle)) {
      // 读取文件内容
      rewind($lockHandle);
      $fileContent = stream_get_contents($lockHandle);
      if ($fileContent === $secretKey) {
        // 修复：解锁前先清空文件内容，避免重新获取锁时读到旧的过期数据导致误判
        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        fflush($lockHandle);
        // 释放锁并关闭句柄
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
  ): false|int {
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
    // 修复问题#4：原实现 get($key, []) 永远返回数组不会返回 false，
    // 改为缓存不存在时返回 false，与接口声明 array|false 一致
    $result = $this->get($key);
    if ($result === null) return false;
    return is_array($result) ? $result : [$result];
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sRemoveArray(
    string       $key,
    array|string $values,
  ): false|int {
    $array = $this->getArray($key);
    if (empty($array)) return 0;
    if (is_string($values)) $values = [$values];
    $newArray = array_filter($array, function ($value) use ($values) {
      return !in_array($value, $values);
    });
    $count = count($array) - count($newArray);
    $result = $this->set($key, $newArray);
    return $result ? $count : false;
  }
}
