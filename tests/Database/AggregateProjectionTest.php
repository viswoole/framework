<?php

declare(strict_types=1);

/* 文件职责：验证聚合查询替换展示字段，并保留过滤绑定和克隆查询隔离。 */

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Raw;

/**
 * 聚合字段投影回归测试
 */
final class AggregateProjectionTest extends TestCase
{
  /**
   * 使用聚合表达式替换指定字段
   *
   * count 指定统计列与其他聚合方法均不得混入普通展示字段。
   *
   * @return void 聚合 SQL 及原查询隔离得到验证
   */
  public function testAggregatesReplaceProjectionAndKeepBindings(): void
  {
    ob_start();
    try {
      foreach (['count' => '*', 'sum' => 'id', 'avg' => 'id', 'min' => 'id', 'max' => 'id'] as $method => $column) {
        $query = (new PDOChannel())->table('announcements')->columns('id', 'title')->where('club_id', 123);
        $aggregate = (clone $query)->toRaw()->$method($column);
        self::assertInstanceOf(Raw::class, $aggregate);
        self::assertSame('SELECT ' . strtoupper($method) . '(' . $column . ') AS ' . $method . ' FROM `announcements` WHERE `club_id` = ?', $aggregate->sql);
        self::assertStringNotContainsString('`title`', $aggregate->sql);
        self::assertSame([123], array_values($aggregate->bindings));
        $original = $query->toRaw()->select();
        self::assertStringContainsString('`title`', $original->sql);
      }
      $count = (new PDOChannel())->table('announcements')->columns('title')->toRaw()->count('id');
      self::assertStringContainsString('COUNT(id) AS count', $count->sql);
      self::assertStringNotContainsString('`title`', $count->sql);
    } finally {
      ob_end_clean();
    }
  }
}
