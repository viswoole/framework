<?php

declare(strict_types=1);

namespace Viswoole\Tests\Cache\Support;

/** 仅对指定测试目录注入临时文件写入故障，默认不改变原生文件操作。 */
final class LeaseWriteFault
{
  public static ?string $directory = null;
  public static bool $isFlushFailure = false;

  /**
   * 判断句柄是否属于当前故障注入范围。
   *
   * @param resource $handle 被测试的句柄
   * @return bool 是否注入故障
   */
  public static function applies(mixed $handle): bool
  {
    $path = stream_get_meta_data($handle)['uri'];
    return self::$directory !== null && dirname($path) === self::$directory;
  }
}

namespace Viswoole\Cache\Driver\File;

use Viswoole\Tests\Cache\Support\LeaseWriteFault;

/**
 * 让真实写入只完成前八字节，检验未提交记录不会污染有效租约。
 *
 * @param resource $handle 目标句柄
 * @param string $content 完整内容
 * @return int|false 实际写入字节数
 */
function fwrite(mixed $handle, string $content): int|false
{
  if (LeaseWriteFault::applies($handle) && !LeaseWriteFault::$isFlushFailure) {
    return \fwrite($handle, substr($content, 0, 8));
  }
  return \fwrite($handle, $content);
}

/**
 * 在完整写入后模拟刷新失败。
 *
 * @param resource $handle 目标句柄
 * @return bool 是否刷新成功
 */
function fflush(mixed $handle): bool
{
  if (LeaseWriteFault::applies($handle) && LeaseWriteFault::$isFlushFailure) return false;
  return \fflush($handle);
}
