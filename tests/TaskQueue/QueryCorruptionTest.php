<?php

/*
 * 职责：复现单条查询索引损坏，验证健康记录、游标和统计仍可读取。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Support/ScenarioRunner.php';
/**
 * 损坏记录必须被显式报告，查询不承担修复写入。
 */
final class QueryCorruptionTest extends TestCase
{
  /**
   * @var array<string,bool> 隔离场景观测
   */
  private static array $observations;
  /**
   * 执行索引损坏场景
   *
   * @return void 加载观测
   */
  public static function setUpBeforeClass(): void
  {
    self::$observations = Support\ScenarioRunner::run('query-corruption.php', 10);
  }
  /**
   * 断言逐条容错
   *
   * @param string $name 场景名称
   * @return void 完成断言
   */
  #[DataProvider('provideBehaviors')]
  public function testCorruption(string $name): void { self::assertTrue(self::$observations[$name], $name); }
  /**
   * 提供损坏查询边界
   *
   * @return iterable<string,array{string}> 场景参数
   */
  public static function provideBehaviors(): iterable
  {
    foreach (['healthyListed','countIncomplete','cursorContinues','readOnly','detailIdentified',
      'invalidJson','scalar','null','nonFinite','state','lease','errorEncoding','missing','missingDetail','identity','filteredIncomplete'] as $name) { yield $name => [$name]; }
  }
}
