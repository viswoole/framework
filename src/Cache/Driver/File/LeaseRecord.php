<?php

declare(strict_types=1);

namespace Viswoole\Cache\Driver\File;

use Viswoole\Cache\Exception\CacheErrorException;

/**
 * 租约数据读写；调用方必须持有独立的互斥文件锁。
 * 数据通过同目录临时文件原子替换，写入失败不破坏已提交租约。
 */
final class LeaseRecord
{
  /**
   * 读取已提交租约，损坏记录不被当作空闲锁。
   *
   * @param string $filename 租约数据路径
   * @return array{id:string,expiresAt:int|float}|null 租约或空记录
   */
  public function read(string $filename): ?array
  {
    if (!is_file($filename)) return null;
    $content = @file_get_contents($filename);
    if ($content === false) throw new CacheErrorException('读取文件租约失败：' . $filename);
    $record = json_decode($content, true);
    if (!is_array($record) || !isset($record['id'], $record['expiresAt'])
      || !is_string($record['id']) || $record['id'] === ''
      || (!is_int($record['expiresAt']) && !is_float($record['expiresAt']))
      || !is_finite((float)$record['expiresAt']) || $record['expiresAt'] < 0) {
      throw new CacheErrorException('文件租约记录损坏：' . $filename);
    }
    return ['id' => $record['id'], 'expiresAt' => $record['expiresAt']];
  }

  /**
   * 完整写入并关闭临时文件后提交，失败时清理未提交文件。
   *
   * @param string $filename 租约数据路径
   * @param string $content 完整租约 JSON
   * @return void
   */
  public function write(string $filename, string $content): void
  {
    $temporary = $filename . '.pending-' . bin2hex(random_bytes(12));
    try {
      $this->stage($temporary, $content);
      // 仅替换数据 inode；互斥文件永久保留，所有参与者仍锁住同一 inode。
      if (!@rename($temporary, $filename)) {
        throw new CacheErrorException('提交文件租约失败：' . $filename);
      }
    } finally {
      if (is_file($temporary) && !@unlink($temporary)) {
        throw new CacheErrorException('清理未提交租约失败：' . $temporary);
      }
    }
  }

  /**
   * 在互斥保护下删除数据；删除失败时保留已提交状态并报告错误。
   *
   * @param string $filename 租约数据路径
   * @return void
   */
  public function delete(string $filename): void
  {
    if (!@unlink($filename)) throw new CacheErrorException('删除文件租约失败：' . $filename);
  }

  /**
   * 写入独立临时文件，任何短写入或刷新失败都阻止提交。
   *
   * @param string $filename 临时文件路径
   * @param string $content 完整租约 JSON
   * @return void
   */
  private function stage(string $filename, string $content): void
  {
    $handle = @fopen($filename, 'x+b');
    if ($handle === false) throw new CacheErrorException('创建临时租约失败：' . $filename);
    try {
      if (@fwrite($handle, $content) !== strlen($content)) {
        throw new CacheErrorException('写入文件租约失败：' . $filename);
      }
      if (!fflush($handle)) throw new CacheErrorException('刷新文件租约失败：' . $filename);
    } finally {
      if (!fclose($handle)) throw new CacheErrorException('关闭临时租约失败：' . $filename);
    }
  }
}
