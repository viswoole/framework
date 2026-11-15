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
use InvalidArgumentException;
use Override;
use Swoole\Coroutine\System;
use Throwable;
use Viswoole\Cache\Driver;
use Viswoole\Cache\Driver\File\ArrayOperations;
use Viswoole\Cache\Driver\File\LockStore;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Core\Common\Filesystem;
use Viswoole\Core\Exception\FilesystemException;

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
  use ArrayOperations;

  /** 普通缓存键禁止使用的内部目录名，清缓存时永久保留。 */
  public const string LOCK_DIRECTORY = '.viswoole-locks';

  public const string EXPIRE_PATTERN = '/^expire\((\d+)\)/';
  /**
   * @var string 缓存文件存储根目录
   */
  protected string $storage;
  /**
   * @var array<string,array{scene:string,autoUnlock:bool}> 当前持有锁的列表
   */
  private array $lockList = [];

  /** @var LockStore|null 与缓存数据隔离的租约存储 */
  private ?LockStore $lockStore = null;

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
  )
  {
    $this->storage = rtrim($storage, '/');
    parent::__construct($prefix, $tag_prefix, $tag_store, $expire);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function inc(string $key, int $step = 1): false|int
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
  #[Override]
  public function get(string $key, mixed $default = null): mixed
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
   * 缓存键常由业务拼接用户输入，键中的穿越序列（.. 段、反斜杠）会把
   * 读写删操作引向缓存目录之外的任意路径，构建期直接拒绝：
   * 仅放行不含 ".." 段与反斜杠的键，保留 key 内以 / 分隔的多级目录用法
   *
   * @param string $key 缓存标识
   * @return string 缓存文件完整路径
   * @throws InvalidArgumentException 缓存键包含路径穿越序列时抛出
   */
  protected function filename(string $key): string
  {
    $key = $this->getCacheKey($key);
    // 先整体归一化校验： ".. /" 或 "/.." 等 mix 形式在此即被拦截；
    // 反斜杠在 Windows 下是路径分隔符，统一拒绝防止绕过正斜杠分段检查
    if (str_contains($key, '\\')) {
      throw new InvalidArgumentException("非法的缓存键（禁止反斜杠）：$key");
    }
    foreach (explode('/', $key) as $segment) {
      if (strcasecmp($segment, self::LOCK_DIRECTORY) === 0) {
        throw new InvalidArgumentException("缓存键不能访问内部锁目录：$key");
      }
      if ($segment === '..') {
        throw new InvalidArgumentException("非法的缓存键（禁止路径穿越）：$key");
      }
    }
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
    // 创建目录（如果不存在），竞态安全的目录创建逻辑见 Filesystem::ensureDirectory
    try {
      Filesystem::ensureDirectory($dir);
    } catch (FilesystemException $e) {
      // 转换为缓存模块异常，保持 dir() 对外抛出的异常类型契约不变；
      // 附带驱动与存储根目录上下文，一条异常消息即可定位问题来源与底层失败原因
      throw new CacheErrorException(
        "文件缓存驱动创建缓存目录失败（storage={$this->storage}）：{$e->getMessage()}",
        previous: $e
      );
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
      // 隐藏的锁目录也属于有效内容，不能因 glob 忽略隐藏项而删除父目录。
      if (!(new FilesystemIterator($dir))->valid()) rmdir($dir);
      return $result;
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function set(
    string            $key,
    mixed             $value,
    DateTime|int|null $expire = null,
    bool              $NX = false
  ): bool
  {
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
    string            $key,
    mixed             $value,
    DateTime|int|null $expire = null,
    bool              $NX = false
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
  #[Override]
  public function dec(string $key, int $step = 1): false|int
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
  #[Override]
  public function pull(string $key): mixed
  {
    $result = $this->get($key, false);

    if ($result !== false) $this->delete($key);
    return $result;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function delete(array|string $keys): false|int
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
  #[Override]
  public function has(string $key): bool
  {
    return $this->getRaw($key) !== null;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function clear(): bool
  {
    return $this->rmdir($this->dir());
  }

  /**
   * 递归清理缓存数据，保留内部锁区域及缓存根目录
   *
   * @param string $dirname 目录路径
   * @return bool 清理成功返回 true
   */
  protected function rmdir(string $dirname): bool
  {
    if (!is_dir($dirname)) return true;
    $isSuccessful = true;
    $hasLockDirectory = false;
    foreach (new FilesystemIterator($dirname) as $item) {
      if (strcasecmp($item->getFilename(), self::LOCK_DIRECTORY) === 0) {
        $hasLockDirectory = true;
        continue;
      }
      $isRemoved = $item->isDir()
        ? $this->rmdir($item->getPathname())
        : @unlink($item->getPathname());
      $isSuccessful = $isRemoved && $isSuccessful;
    }
    // 保留根目录，clear 与首次创建内部锁目录交错时也不删除其父目录。
    $isRoot = rtrim($dirname, DIRECTORY_SEPARATOR) === rtrim($this->storage, DIRECTORY_SEPARATOR);
    if ($isRoot || $hasLockDirectory) return $isSuccessful;
    return (!is_dir($dirname) || @rmdir($dirname)) && $isSuccessful;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function lock(
    string    $scene,
    int       $expire = 10,
    bool      $autoUnlock = false,
    int       $retry = 5,
    float|int $sleep = 0.2
  ): string
  {
    $scene = $this->getLockKey($scene);
    $lockId = bin2hex(random_bytes(16));
    $store = $this->getLockStore();
    $attempts = max(1, $retry);
    while ($attempts-- > 0) {
      if ($store->acquire($scene, $lockId, $expire)) {
        $this->lockList[$lockId] = ['scene' => $scene, 'autoUnlock' => $autoUnlock];
        return $lockId;
      }
      if ($attempts > 0 && $sleep > 0) System::sleep($sleep);
    }
    throw new CacheErrorException('缓存系统繁忙，请稍后重试');
  }

  /**
   * 使用存储目录内的保留区域，仅要求缓存目录本身可写。
   *
   * @return LockStore 文件租约存储
   */
  private function getLockStore(): LockStore
  {
    if ($this->lockStore === null) {
      $storage = realpath($this->dir());
      if ($storage === false) throw new CacheErrorException('解析文件缓存目录失败');
      $this->lockStore = new LockStore($storage . DIRECTORY_SEPARATOR . self::LOCK_DIRECTORY);
    }
    return $this->lockStore;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function close(): void
  {
    foreach ($this->lockList as $lockId => $lockInfo) {
      if ($lockInfo['autoUnlock']) $this->unlock($lockId);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function unlock(string $id): bool
  {
    if (!isset($this->lockList[$id])) return false;
    $released = $this->getLockStore()->release($this->lockList[$id]['scene'], $id);
    // 释放操作已等待互斥量；成功释放或已被接管的租约均可移除本地记录。
    unset($this->lockList[$id]);
    return $released;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function connect(): File
  {
    return $this;
  }
}
