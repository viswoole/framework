<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Database\Transaction;

use PDO;
use RuntimeException;
use Swoole\Database\MysqliProxy;
use Swoole\Database\PDOProxy;
use Throwable;
use Viswoole\Database\Exception\DbException;

/**
 * XA 语句驱动分派器
 *
 * 在底层连接上执行 XA 协议语句（START/END/PREPARE/COMMIT/ROLLBACK）
 * 与 XA RECOVER 查询，统一 PDO 系与 mysqli 系的分派及结果解析。
 *
 * 与 ConnectManager::executeSavepointSql 的静默跳过语义不同：
 * XA 语句失败意味着原子性保证失效，未知驱动类型必须 fail-fast 抛出，
 * 而不能像保存点那样静默跳过（静默跳过会得到一个"以为在 XA 事务里、
 * 实际上没有"的假事务）。
 */
final class XaDriver
{
  /**
   * 在连接上执行 XA 语句（写入语义）
   *
   * @param object $connect 底层连接（PDO/PDOProxy/mysqli/MysqliProxy）
   * @param string $sql XA 语句
   * @throws DbException 驱动不支持或执行失败时抛出（原始异常作为 previous）
   */
  public static function execute(object $connect, string $sql): void
  {
    try {
      if ($connect instanceof PDO || $connect instanceof PDOProxy) {
        $connect->exec($sql);
        return;
      }
      /** @noinspection PhpComposerExtensionStubsInspection */
      if ($connect instanceof MysqliProxy || $connect instanceof \mysqli) {
        if (!$connect->query($sql)) {
          throw new DbException("XA 语句执行失败：{$sql}");
        }
        return;
      }
      throw new RuntimeException(
        'XA 事务仅支持 PDO/mysqli 系驱动连接，收到 ' . get_debug_type($connect)
      );
    } catch (DbException $e) {
      throw $e;
    } catch (Throwable $e) {
      throw new DbException("XA 语句执行失败（{$sql}）：{$e->getMessage()}", 0, $sql, $e);
    }
  }

  /**
   * 在连接上执行查询语句并返回关联行
   *
   * @param object $connect 底层连接
   * @param string $sql 查询语句
   * @return array<int,array<string,mixed>> 关联行数组
   * @throws DbException 驱动不支持或查询失败时抛出
   */
  public static function query(object $connect, string $sql): array
  {
    if ($connect instanceof PDO || $connect instanceof PDOProxy) {
      $statement = $connect->query($sql);
      if ($statement === false) {
        throw new DbException("XA 查询执行失败：{$sql}");
      }
      return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    /** @noinspection PhpComposerExtensionStubsInspection */
    if ($connect instanceof MysqliProxy || $connect instanceof \mysqli) {
      $result = $connect->query($sql);
      if ($result === false) {
        throw new DbException("XA 查询执行失败：{$sql}");
      }
      /** @noinspection PhpComposerExtensionStubsInspection */
      return $result->fetch_all(MYSQLI_ASSOC);
    }
    throw new DbException('XA 事务仅支持 PDO/mysqli 系驱动连接，收到 ' . get_debug_type($connect));
  }

  /**
   * 查询连接上处于 prepared 状态的未决事务 xid 列表
   *
   * XA RECOVER CONVERT INTO 使 data 列以十六进制字符串返回（不同驱动/版本
   * 可能返回 0x 前缀 hex、裸 hex 或原始二进制），此处统一解析还原。
   *
   * @param object $connect 底层连接
   * @return string[] 未决事务的 xid 列表（gtrid+bqual，本框架 bqual 恒为空）
   * @throws DbException 查询失败或驱动不支持时抛出
   */
  public static function recover(object $connect): array
  {
    // CONVERT XID 使 data 列以 0x 前缀十六进制返回（不同驱动/版本也可能返回
    // 裸 hex 或原始二进制），统一由 decodeXidData 解析
    $rows = self::query($connect, 'XA RECOVER CONVERT XID');
    $xids = [];
    foreach ($rows as $row) {
      $row = array_change_key_case($row);
      $data = (string)($row['data'] ?? '');
      $gtridLength = (int)($row['gtrid_length'] ?? 0);
      if ($data === '') continue;
      $xids[] = substr(self::decodeXidData($data, $gtridLength), 0, $gtridLength);
    }
    return $xids;
  }

  /**
   * 解码 XA RECOVER 返回的 data 列
   *
   * @param string $data 原始列值（0x 前缀 hex / 裸 hex / 二进制）
   * @param int $gtridLength gtrid 长度（用于区分裸 hex 与二进制）
   * @return string 解码后的 xid 字节串
   */
  private static function decodeXidData(string $data, int $gtridLength): string
  {
    if (str_starts_with($data, '0x')) {
      return (string)hex2bin(substr($data, 2));
    }
    // 长度恰为 2 倍 gtrid 长度的是裸 hex 字符串（CONVERT INTO 的常见返回形态），
    // 等长则按原始二进制处理
    if ($gtridLength > 0 && strlen($data) === $gtridLength * 2) {
      return (string)hex2bin($data);
    }
    return $data;
  }

  /**
   * 判断异常是否为 XAER_NOTA（未知 xid，事务已终结或不存在）
   *
   * 恢复场景的幂等基石：并发恢复/重试时后到者对已终结 xid 执行终结语句
   * 将得到 XAER_NOTA，视为已完成而非错误。
   *
   * @param Throwable $e 待判断的异常
   * @return bool 是 XAER_NOTA 返回 true
   */
  public static function isNotExists(Throwable $e): bool
  {
    return str_contains($e->getMessage(), 'XAER_NOTA') || (int)$e->getCode() === 1390;
  }
}
