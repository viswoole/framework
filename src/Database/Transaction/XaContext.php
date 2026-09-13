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

use Random\RandomException;

/**
 * XA 事务上下文
 *
 * 持有单个 XA 事务（一个协程首层 startXa 生命周期内）的全局标识与分支注册表：
 * 1. gtrid 全局事务 ID，随机生成，崩溃恢复时以它匹配 XA RECOVER 中的未决分支；
 * 2. 分支 xid = gtrid + '-b' + 序号：每连接一个分支。不用同一 xid 直接开两个连接，
 *    因为 MySQL 同一实例上同一 xid 的并发 XA START 会得到 XAER_DUPID（两个通道
 *    可能指向同一实例的不同库），分支后缀同时保证了恢复时的 gtrid 前缀匹配唯一性；
 * 3. 分支状态注册表以 spl_object_id 索引连接，连接对象在事务期间由
 *    ConnectManager 持有引用，对象 ID 不会被复用，索引安全。
 *
 * gtrid 与分支 xid 均为框架生成的 [0-9a-f-] 字符集（journal 内联 SQL 安全的前提，
 * 见 XaJournal），创建时做格式校验兜底。
 */
final class XaContext
{
  /**
   * 全局事务 ID：vw 前缀 + 16 位十六进制随机数（共 18 字节，远小于 MySQL 64 字节 xid 上限）
   */
  public readonly string $gtrid;

  /**
   * @var array<int,array{xid:string,state:XaBranchState}> 分支注册表，键为连接的 spl_object_id
   */
  private array $branches = [];

  /**
   * @var int 已分配的分支序号
   */
  private int $branchSequence = 0;

  /**
   * @var bool journal 行是否已写入（回滚路径据此避免无谓的 journal I/O）
   */
  private bool $journalRowExists = false;

  /**
   * @param XaJournal $journal 该事务的提交意图日志
   */
  private function __construct(private readonly XaJournal $journal)
  {
    $this->gtrid = 'vw' . bin2hex(random_bytes(8));
  }

  /**
   * 创建 XA 事务上下文
   *
   * @param XaJournal $journal 提交意图日志
   * @return static 新的上下文实例
   * @throws RandomException 随机源不可用时抛出
   */
  public static function create(XaJournal $journal): static
  {
    return new static($journal);
  }

  /**
   * 获取提交意图日志
   *
   * @return XaJournal journal 实例
   */
  public function journal(): XaJournal
  {
    return $this->journal;
  }

  /**
   * 为新加入事务的连接注册分支并分配 xid
   *
   * @param object $connect 事务连接（PDO/PDOProxy/mysqli 系）
   * @return string 分支 xid
   */
  public function registerBranch(object $connect): string
  {
    $this->branchSequence++;
    $xid = "{$this->gtrid}-b{$this->branchSequence}";
    $this->branches[spl_object_id($connect)] = ['xid' => $xid, 'state' => XaBranchState::Active];
    return $xid;
  }

  /**
   * 查询连接所属分支的 xid
   *
   * @param object $connect 事务连接
   * @return string|null 分支 xid，连接未注册分支时返回 null
   */
  public function branchXidOf(object $connect): ?string
  {
    return $this->branches[spl_object_id($connect)]['xid'] ?? null;
  }

  /**
   * 查询连接所属分支的状态
   *
   * @param object $connect 事务连接
   * @return XaBranchState|null 分支状态，连接未注册分支时返回 null
   */
  public function branchStateOf(object $connect): ?XaBranchState
  {
    return $this->branches[spl_object_id($connect)]['state'] ?? null;
  }

  /**
   * 标记连接所属分支的状态
   *
   * @param object $connect 事务连接
   * @param XaBranchState $state 目标状态
   */
  public function markBranch(object $connect, XaBranchState $state): void
  {
    $id = spl_object_id($connect);
    if (isset($this->branches[$id])) {
      $this->branches[$id]['state'] = $state;
    }
  }

  /**
   * 列出全部分支 xid
   *
   * @return string[] 分支 xid 列表
   */
  public function xids(): array
  {
    return array_column($this->branches, 'xid');
  }

  /**
   * 判断 journal 中是否存在本事务的行（提交意图是否已开始记录）
   *
   * @return bool 存在返回 true
   */
  public function journalRowExists(): bool
  {
    return $this->journalRowExists;
  }

  /**
   * 设置 journal 行存在标记
   *
   * @param bool $exists journal 行是否已写入
   */
  public function setJournalRowExists(bool $exists): void
  {
    $this->journalRowExists = $exists;
  }

  /**
   * 判断给定 xid 是否属于本事务（gtrid 前缀匹配）
   *
   * 恢复场景下按分支 xid 反查所属事务时使用。
   *
   * @param string $xid 待判断的 xid
   * @return bool 属于本事务返回 true
   */
  public function ownsXid(string $xid): bool
  {
    return str_starts_with($xid, $this->gtrid . '-');
  }
}
