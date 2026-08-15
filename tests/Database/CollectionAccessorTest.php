<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;

/**
 * 集合获取器（accessor）行为测试
 *
 * 覆盖三块能力：
 * 1. 模型级获取器 get{驼峰字段}Attr 在 toArray 时自动应用；
 * 2. 集合级 withAttr 临时获取器：DataSet（单行）生效，且优先于模型获取器；
 * 3. 修复回归——多行 Collection 的 withAttr 此前不生效（配置存于外层集合，
 *    字段转换发生在子 DataSet 递归 toArray 中，配置不传播）；以及
 *    toArray(bool $withAttr) 形参此前未参与判断，传 false 仍应用获取器。
 */
class CollectionAccessorTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建定义了模型级获取器的 users 模型，并替换默认通道为 Sqlite 替身
   *
   * Sqlite 返回两行：['id' => 1, 'name' => 'a']、['id' => 2, 'name' => 'b']
   */
  private function makeModel(): Model
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', new FakeChannel($this->makeSelectStatement()));

    return new class extends Model {
      protected string $table = 'users';

      /** 模型级获取器：name 字段读取时转大写 */
      public function getNameAttr(mixed $value): string
      {
        return strtoupper((string)$value);
      }
    };
  }

  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志（避免 PHPUnit 标记 risky）
   *
   * @template T
   * @param callable(): T $fn 无参回调，内部执行触发查询的链式调用
   * @return T 回调返回值
   */
  private function runQuietly(callable $fn): mixed
  {
    ob_start();
    try {
      return $fn();
    } finally {
      ob_end_clean();
    }
  }

  /**
   * 模型级获取器应自动应用于多行结果
   */
  public function testModelAccessorAppliedOnCollection(): void
  {
    $model = $this->makeModel();
    $rows = $this->runQuietly(fn() => $model->query()->select());
    self::assertSame([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']], $rows->toArray());
  }

  /**
   * toArray(false) 应跳过模型级获取器（修复前形参未生效）
   */
  public function testToArrayFalseSkipsModelAccessor(): void
  {
    $model = $this->makeModel();
    $rows = $this->runQuietly(fn() => $model->query()->select());
    self::assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], $rows->toArray(false));
  }

  /**
   * 多行 Collection 的 withAttr 应传播到每行生效（修复前配置滞留外层永不生效）
   */
  public function testWithAttrPropagatesToCollectionRows(): void
  {
    $model = $this->makeModel();
    $rows = $this->runQuietly(fn() => $model->query()->select());
    $rows->withAttr('name', fn($v) => 'X-' . $v);
    self::assertSame([['id' => 1, 'name' => 'X-a'], ['id' => 2, 'name' => 'X-b']], $rows->toArray());
  }

  /**
   * toArray(false) 同时应跳过集合级 withAttr 回调
   */
  public function testToArrayFalseSkipsWithAttrCallbacks(): void
  {
    $model = $this->makeModel();
    $rows = $this->runQuietly(fn() => $model->query()->select());
    $rows->withAttr('name', fn($v) => 'X-' . $v);
    self::assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], $rows->toArray(false));
  }

  /**
   * 单行 DataSet 的 withAttr 既有行为保持：生效且优先于模型获取器
   */
  public function testDataSetWithAttrTakesPrecedenceOverModelAccessor(): void
  {
    $model = $this->makeModel();
    $row = $this->runQuietly(fn() => $model->query()->where('id', 1)->find());
    $row->withAttr('name', fn($v) => 'Y-' . $v);
    self::assertSame('Y-a', $row->toArray()['name']);
  }

  /**
   * 未命中 withAttr 的字段仍应走模型获取器（传播不能误伤模型获取器）
   */
  public function testUnrelatedColumnStillUsesModelAccessor(): void
  {
    $model = $this->makeModel();
    $rows = $this->runQuietly(fn() => $model->query()->select());
    $rows->withAttr('id', fn($v) => 'ID-' . $v);
    $array = $rows->toArray();
    // id 走 withAttr 回调，name 仍走模型获取器
    self::assertSame('ID-1', $array[0]['id']);
    self::assertSame('A', $array[0]['name']);
  }
}
