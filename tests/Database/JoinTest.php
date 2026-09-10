<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * JOIN 构建回归测试
 *
 * 修复前缺陷：LeftJoin()（现已更名 leftJoin()）按自身签名 (table, localKey, operator, foreignKey)
 * 顺序直传给 join()，而 join() 的签名为 (table, localKey, foreignKey, operator, type)，
 * 导致 operator 与 foreignKey 两个槽位互换——调用
 * LeftJoin('orders', 'users.id', '=', 'orders.user_id') 会把 '=' 存入 foreignKey、
 * 'orders.user_id' 存入 operator，生成坏 SQL "ON users.idorders.user_id=`=`"
 * （quote() 对含点号字段原样返回、'=' 被反引号包裹、三段无空格直连）。
 *
 * 修复方式：四个方法统一签名为 (table, localKey, foreignKey, operator='=')，
 * join() 对 operator 做白名单校验，leftJoin() 对旧参数顺序调用抛出迁移提示。
 */
class JoinTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建捕获 build() 收到 Options 的通道替身
   *
   * 查询链路为 table()->joinXxx()->value() → runCrud → build(Options) → execute，
   * 在 build 处截获 Options 引用，execute 返回 Sqlite 替身语句。
   */
  private function makeCaptureChannel(): FakeChannel
  {
    return new class ($this->makeSelectStatement()) extends FakeChannel {
      /** @var Options|null 最近一次 build 收到的查询选项 */
      public ?Options $capturedOptions = null;

      #[Override]
      public function build(Options $options): Raw
      {
        $this->capturedOptions = $options;
        return new Raw('SELECT * FROM `users`', []);
      }
    };
  }

  /**
   * 用真实 SqlBuilder（经 PDOChannel::build）把捕获的 Options 构建为 SQL 字符串
   *
   * PDOChannel 以全默认参数实例化：构建过程纯字符串操作，不触碰数据库。
   */
  private function buildSql(Options $options): string
  {
    return (new PDOChannel())->build($options)->toString();
  }

  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志（避免 PHPUnit 标记 risky）
   *
   * @param callable $fn 无参回调，内部执行触发查询的链式调用
   */
  private function runQuietly(callable $fn): void
  {
    ob_start();
    try {
      $fn();
    } finally {
      ob_end_clean();
    }
  }

  /**
   * 四个 JOIN 方法应按统一签名把 foreignKey/operator 存入正确槽位
   *
   * 用例以 [方法名, 参数列表] 描述（而非闭包），保证数据提供器
   * 在 PHPUnit 进程隔离模式下可序列化。
   *
   * @return array<string,array{array{string,array<int,string>},string,string,string}>
   *         每个用例包含：调用描述、期望的连接类型、期望的 operator、期望的 JOIN SQL 片段
   */
  public static function provideJoinCases(): array
  {
    return [
      'join 默认 INNER 且默认等号' => [
        ['join', ['orders', 'users.id', 'orders.user_id']],
        'INNER',
        '=',
        'INNER JOIN `orders` ON users.id=orders.user_id',
      ],
      'leftJoin 存 LEFT 且槽位正确（修复回归点）' => [
        ['leftJoin', ['orders', 'users.id', 'orders.user_id']],
        'LEFT',
        '=',
        'LEFT JOIN `orders` ON users.id=orders.user_id',
      ],
      'leftJoin 自定义运算符不串槽' => [
        ['leftJoin', ['orders', 'users.id', 'orders.user_id', '<>']],
        'LEFT',
        '<>',
        'LEFT JOIN `orders` ON users.id<>orders.user_id',
      ],
      'rightJoin 存 RIGHT' => [
        ['rightJoin', ['orders', 'users.id', 'orders.user_id']],
        'RIGHT',
        '=',
        'RIGHT JOIN `orders` ON users.id=orders.user_id',
      ],
      'fullJoin 存 FULL' => [
        ['fullJoin', ['orders', 'users.id', 'orders.user_id']],
        'FULL',
        '=',
        'FULL JOIN `orders` ON users.id=orders.user_id',
      ],
    ];
  }

  /**
   * 验证各 JOIN 方法把 foreignKey/operator/table/type 存入正确槽位并生成正确 SQL
   *
   * @param array{string,array<int,string>} $call        调用描述：[方法名, 参数列表]
   * @param string                          $type        期望的连接类型
   * @param string                          $operator    期望存入 operator 槽位的值
   * @param string                          $sqlFragment 期望生成的 JOIN SQL 片段
   */
  #[DataProvider('provideJoinCases')]
  public function testJoinStoresKeysInCorrectSlots(
    array  $call,
    string $type,
    string $operator,
    string $sqlFragment
  ): void
  {
    [$method, $args] = $call;
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn() => $capture->table('users')->{$method}(...$args)->value('id'));

    $join = $capture->capturedOptions->join[0] ?? null;
    self::assertNotNull($join, '查询选项中应存在 JOIN 条件');
    self::assertSame('orders', $join['table']);
    self::assertSame('users.id', $join['localKey']);
    self::assertSame('orders.user_id', $join['foreignKey'], 'foreignKey 槽位不得混入 operator');
    self::assertSame($operator, $join['operator']);
    self::assertSame($type, $join['type']);

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString($sqlFragment, $sql);
  }

  /**
   * join() 传入无效连接类型应抛出 InvalidArgumentException 且不残留状态
   */
  public function testJoinRejectsInvalidType(): void
  {
    $capture = $this->makeCaptureChannel();
    $query = $capture->table('users');
    try {
      $query->join('orders', 'users.id', 'orders.user_id', '=', 'CROSS');
      self::fail('无效连接类型应抛出 InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
      self::assertStringContainsString('INNER, LEFT, RIGHT, FULL', $e->getMessage());
    }
    // 抛出异常前不得写入 options->join（无残留状态），且同一查询实例可继续使用
    $this->runQuietly(fn() => $query->value('id'));
    self::assertSame([], $capture->capturedOptions->join);
  }

  /**
   * join() 传入白名单以外的 operator 应抛出 InvalidArgumentException
   */
  public function testJoinRejectsInvalidOperator(): void
  {
    $capture = $this->makeCaptureChannel();
    try {
      $capture->table('users')->join('orders', 'users.id', 'orders.user_id', 'DROP');
      self::fail('白名单以外的 operator 应抛出 InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
      self::assertStringContainsString('无效的关联条件运算符', $e->getMessage());
    }
  }

  /**
   * leftJoin() 收到旧版参数顺序（第 3 参为运算符）应抛出迁移提示，
   * 而不是静默把 '=' 存入 foreignKey 槽位生成坏 SQL
   */
  public function testLeftJoinRejectsLegacyArgumentOrder(): void
  {
    $capture = $this->makeCaptureChannel();
    // 旧版调用方式：leftJoin(table, localKey, operator, foreignKey)
    try {
      $capture->table('users')->leftJoin('orders', 'users.id', '=', 'orders.user_id');
      self::fail('旧参数顺序调用应抛出迁移提示');
    } catch (InvalidArgumentException $e) {
      self::assertStringContainsString('参数顺序已变更', $e->getMessage());
      self::assertStringContainsString('=', $e->getMessage());
    }
  }
}
