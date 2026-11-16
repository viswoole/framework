<?php

/*
 * 职责：验证队列只读查询与实例归属，不启动业务应用。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Support/ScenarioRunner.php';
/**
 * 验证元数据查询和生命周期索引的一致性。
 */
final class QueryTest extends TestCase
{
  /**
   * @var array<string, bool> 独立 Redis 场景观测
   */
  private static array $observations;
  /**
   * 执行真实存储场景
   *
   * @return void 加载查询观测
   */
  public static function setUpBeforeClass(): void
  {
    self::$observations = Support\ScenarioRunner::run('query.php', 15);
  }
  /**
   * 验证查询行为
   *
   * @param string $behavior 场景名称
   * @return void 断言查询契约
   */
  #[DataProvider('provideBehaviors')]
  public function testQuery(string $behavior): void
  {
    self::assertTrue(self::$observations[$behavior], $behavior);
  }
  /**
   * 提供独立查询契约
   *
   * @return iterable<string, array{string}> 场景参数
   */
  public static function provideBehaviors(): iterable
  {
    foreach (['producer', 'execution', 'combined', 'counts', 'details', 'metadataOnly',
      'readOnly', 'pagination', 'expiredOwner', 'failedCleared', 'replayed', 'ackRemoved',
      'deleteRemoved', 'quarantineRemoved', 'missingRemoved', 'decorator', 'invalidFilters',
      'invalidCursor', 'invalidLimit', 'unknownDetail', 'boundedScan', 'automaticOwner', 'crossInstance', 'renewKeepsOwner'] as $name) { yield $name => [$name]; }
  }
}
