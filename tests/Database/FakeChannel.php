<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Override;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Channel;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * 可记录调用并配置 execute 返回值的通道测试替身
 *
 * 供 Database 相关单测使用，避免依赖真实数据库连接。
 * query 使用基类默认实现（内部调用 execute），便于验证 SQL/绑定参数/主库标记的透传。
 */
class FakeChannel extends Channel
{
  /** @var array<int,array{method:string,sql:Raw|string,bindings:array,getId:false|string,master:bool}> 记录 execute 的调用参数 */
  public array $calls = [];

  /**
   * @param PDOStatementProxy|PDOStatement|int|string $executeResult execute 方法固定返回的结果
   */
  public function __construct(private readonly PDOStatementProxy|PDOStatement|int|string $executeResult)
  {
  }

  #[Override]
  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): PDOStatementProxy|PDOStatement|int|string
  {
    $this->calls[] = ['method' => 'execute', 'sql' => $sql, 'bindings' => $bindings, 'getId' => $getId, 'master' => $master];
    return $this->executeResult;
  }

  #[Override]
  public function pop(string $type): mixed
  {
    return null;
  }

  #[Override]
  public function put(mixed $connect): void
  {
  }

  #[Override]
  public function build(Options $options): Raw
  {
    // 写入路径断言支持：将 options->data 平铺为位置绑定参数
    // （单行为值列表，批量按行顺序展开），select 等无 data 路径不受影响
    $bindings = [];
    if (!empty($options->data)) {
      $rows = (array_is_list($options->data) && isset($options->data[0]) && is_array($options->data[0]))
        ? $options->data
        : [$options->data];
      foreach ($rows as $row) {
        foreach ($row as $value) $bindings[] = $value;
      }
    }
    return new Raw('', $bindings);
  }
}
