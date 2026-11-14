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

namespace Viswoole\Monitor\Contract;

use Viswoole\Monitor\Dto\SlowRequestSnapshot;

/**
 * 慢请求记录存储边界
 *
 * 职责：定义慢请求记录的持久化契约。
 * 边界：实现方收到的记录已完成脱敏与大小限制，写入发生在请求上下文
 * 清理之后，存储内部的数据库、缓存调用不会被递归采集；实现可替换为
 * 外部检索或监控系统。
 */
interface TraceWriterInterface
{
  /**
   * 写入一条慢请求记录
   *
   * @param SlowRequestSnapshot $snapshot 已脱敏且定稿的不可变请求快照
   */
  public function write(SlowRequestSnapshot $snapshot): void;
}
