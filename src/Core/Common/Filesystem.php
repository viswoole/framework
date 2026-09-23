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

namespace Viswoole\Core\Common;

use Viswoole\Core\Exception\FilesystemException;

/**
 * 文件系统工具类，提供跨模块复用的目录与文件操作
 */
final class Filesystem
{
  /**
   * 确保目录存在，不存在时递归创建（并发安全）
   *
   * 用于 Swoole 多进程/多协程并发初始化场景：多个 worker 同时通过 is_dir()
   * 判断目录不存在并调用 mkdir() 时，只有一个进程会创建成功，其余进程
   * mkdir() 返回 false 属于正常的竞态结果。因此在 mkdir 失败后必须复查
   * is_dir()，目录已存在（输掉竞态）视为成功，仍不存在才抛出异常。
   *
   * @param string $dir 目录绝对路径
   * @param int $mode 目录权限，默认 0755
   * @return void
   * @throws FilesystemException 目录创建失败（且目录最终不存在）时抛出，
   *                             如父目录权限不足、路径被同名文件占用等
   */
  public static function ensureDirectory(string $dir, int $mode = 0755): void
  {
    // fast path：目录已存在，直接返回，避免每次读写都触发系统调用
    if (is_dir($dir)) return;
    // 用局部错误处理器捕获 mkdir 的原始错误信息（如 mkdir(): Permission denied），
    // 相比 @ 抑制 + error_get_last()：当调用链上存在自定义错误处理器时，
    // 被 @ 抑制的错误不会更新 error_get_last()，导致无法获取底层真实原因。
    // 局部处理器可确定性拿到错误文本，且不会将 Warning 泄漏给外部处理器
    $lastErrorMessage = null;
    set_error_handler(function (int $errno, string $errstr) use (&$lastErrorMessage): bool {
      $lastErrorMessage = $errstr;
      // 已捕获错误信息，交由本方法统一处理，阻断默认处理器
      return true;
    });
    try {
      $isCreated = mkdir($dir, $mode, true);
    } finally {
      restore_error_handler();
    }
    if (!$isCreated) {
      // 复查目录是否已由并发进程创建成功（输掉竞态视为成功）
      if (is_dir($dir)) return;
      // 真实失败：优先使用底层真实错误精确定位原因；
      // 无错误信息时（理论上不发生）回退到启发式判断。注意直接父目录在递归创建
      // 中途失败时尚不存在，is_writable() 对不存在路径恒返回 false，
      // 因此启发式需先区分「父目录不存在（祖先路径被占用）」与「父目录存在但不可写」
      $reason = $lastErrorMessage ?? self::guessFailureReason($dir);
      throw new FilesystemException("创建目录失败：{$dir}（{$reason}）");
    }
  }

  /**
   * 在无法获取底层错误信息时，通过路径状态启发式推测目录创建失败的原因
   *
   * @param string $dir 创建失败的目录路径
   * @return string 人类可读的失败原因描述
   */
  private static function guessFailureReason(string $dir): string
  {
    $parent = dirname($dir);
    return !is_dir($parent)
      ? '祖先目录创建失败，路径可能被同名非目录文件占用'
      : (is_writable($parent)
        ? '路径被同名非目录文件占用或磁盘 I/O 异常'
        : '父目录不可写，请检查目录权限');
  }
}
