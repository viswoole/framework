<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\Rules\Between;
use Viswoole\Core\Validate\Rules\Regex;

/**
 * 容器反射元数据缓存测试
 *
 * 验证 invokeClass/invokeMethod/invokeFunction 的参数元数据缓存
 * 在重复调用（模拟多请求复用）下注入、校验行为与首次调用完全一致
 */
class ContainerInjectCacheTest extends TestCase
{
  private App $app;

  /**
   * 初始化容器实例并开启输出缓冲
   */
  protected function setUp(): void
  {
    $this->app = App::factory();
    ob_start();
  }

  /**
   * 清理输出缓冲
   */
  protected function tearDown(): void
  {
    ob_end_clean();
  }

  /**
   * 测试方法参数注入在重复调用（缓存命中）后行为一致
   */
  public function testMethodInjectConsistentAcrossCalls(): void
  {
    $case = new class {
      /**
       * 混合类型参数：内置类型 + 默认值 + 可空
       */
      public function mixed(int $id, string $name = '默认', ?int $age = null): array
      {
        return [$id, $name, $age];
      }
    };
    // 首次调用（构建缓存）与二次调用（命中缓存）结果必须一致
    $first = $this->app->invokeMethod([$case, 'mixed'], ['id' => 1]);
    $second = $this->app->invokeMethod([$case, 'mixed'], ['id' => 1]);
    self::assertSame([1, '默认', null], $first);
    self::assertSame($first, $second);

    // 命名参数覆盖默认值
    self::assertSame(
      [2, 'bob', 18],
      $this->app->invokeMethod([$case, 'mixed'], ['id' => 2, 'name' => 'bob', 'age' => 18])
    );
  }

  /**
   * 测试验证规则属性在缓存复用后仍逐次执行
   */
  public function testValidateRuleStillAppliedOnCacheHit(): void
  {
    $case = new class {
      /**
       * 正则规则参数
       */
      public function phone(#[Regex('/^1\d{10}$/')] string $mobile): string
      {
        return $mobile;
      }
    };
    // 首次合法（规则实例入缓存）
    self::assertSame('13800138000', $this->app->invokeMethod([$case, 'phone'], ['mobile' => '13800138000']));
    // 二次非法：缓存的规则实例必须依然拦截
    $this->expectException(ValidateException::class);
    $this->app->invokeMethod([$case, 'phone'], ['mobile' => 'abc']);
  }

  /**
   * 测试类型不匹配异常在缓存命中后仍正常抛出
   */
  public function testTypeCheckStillAppliedOnCacheHit(): void
  {
    $case = new class {
      /**
       * 整型参数
       */
      public function score(int $value): int
      {
        return $value;
      }
    };
    $this->app->invokeMethod([$case, 'score'], ['value' => 60]); // 首次成功并缓存
    $this->expectException(ValidateException::class);
    $this->app->invokeMethod([$case, 'score'], ['value' => 'not-int']); // 缓存后非法
  }

  /**
   * 测试可变参数在缓存命中后注入正确
   */
  public function testVariadicInjectOnCacheHit(): void
  {
    $case = new class {
      /**
       * 可变参数求和
       */
      public function sum(int ...$nums): int
      {
        return array_sum($nums);
      }
    };
    self::assertSame(6, $this->app->invokeMethod([$case, 'sum'], [1, 2, 3]));
    self::assertSame(60, $this->app->invokeMethod([$case, 'sum'], [10, 20, 30]));
  }

  /**
   * 测试构造器注入在重复实例化（缓存命中）后一致
   */
  public function testConstructorInjectOnCacheHit(): void
  {
    $class = get_class(new class {
      /**
       * 构造器注入
       */
      public function __construct(public readonly string $label = 'svc')
      {
      }
    });
    $a = $this->app->invokeClass($class);
    $b = $this->app->invokeClass($class);
    // 每次实例化产生新实例，注入在缓存命中后依然生效
    self::assertInstanceOf($class, $a);
    self::assertInstanceOf($class, $b);
    self::assertSame('svc', $a->label);
    self::assertSame('svc', $b->label);
    self::assertNotSame($a, $b);
    // 显式传参覆盖默认值
    $c = $this->app->invokeClass($class, ['label' => 'custom']);
    self::assertSame('custom', $c->label);
  }

  /**
   * 测试命名函数反射缓存后注入一致
   */
  public function testNamedFunctionInjectOnCacheHit(): void
  {
    // 定义被测命名函数（带规则验证）
    if (!function_exists('viswoole_test_cached_fn')) {
      eval(<<<'PHP'
        function viswoole_test_cached_fn(#[\Viswoole\Core\Validate\Rules\Between(1, 100)] int $n) {
          return $n * 2;
        }
        PHP);
    }
    self::assertSame(100, $this->app->invokeFunction('viswoole_test_cached_fn', ['n' => 50]));
    self::assertSame(120, $this->app->invokeFunction('viswoole_test_cached_fn', ['n' => 60]));
    $this->expectException(ValidateException::class);
    $this->app->invokeFunction('viswoole_test_cached_fn', ['n' => 200]); // 缓存后越界仍拦截
  }

  /**
   * 测试闭包调用不走缓存且行为正确（回归保护）
   */
  public function testClosureInvokeBypassesCache(): void
  {
    $closure = fn(int $x): int => $x + 1;
    self::assertSame(2, $this->app->invokeFunction($closure, ['x' => 1]));
    // 不同闭包实例、相同签名，各自独立求值
    $closure2 = fn(int $x): int => $x + 100;
    self::assertSame(101, $this->app->invokeFunction($closure2, ['x' => 1]));
  }

  /**
   * 测试对象默认值为 new 表达式时每次实例化得到新实例（默认值实时求值语义）
   */
  public function testNewExpressionDefaultValueNotCached(): void
  {
    $case = new class {
      /**
       * 对象默认值参数
       */
      public function build(\stdClass $dto = new \stdClass()): object
      {
        return $dto;
      }
    };
    $a = $this->app->invokeMethod([$case, 'build'], []);
    $b = $this->app->invokeMethod([$case, 'build'], []);
    self::assertInstanceOf(\stdClass::class, $a);
    self::assertInstanceOf(\stdClass::class, $b);
    // 默认值实时求值：两次调用得到不同实例，而非缓存共享同一实例
    self::assertNotSame($a, $b);
  }

  /**
   * 测试无类型声明参数（type 为 null）在缓存后正常透传
   */
  public function testUntypedParamPassThrough(): void
  {
    $case = new class {
      /**
       * 无类型参数
       */
      public function raw($anything): mixed
      {
        return $anything;
      }
    };
    self::assertSame('x', $this->app->invokeMethod([$case, 'raw'], ['anything' => 'x']));
    self::assertSame([1, 2], $this->app->invokeMethod([$case, 'raw'], ['anything' => [1, 2]]));
  }

  /**
   * 测试静态方法与字符串形式调用在缓存后行为一致
   *
   * 使用具名 fixture 类：ReflectionMethod::createFromMethodName 不支持匿名类名（PHP 限制）
   */
  public function testStaticMethodInvokeOnCacheHit(): void
  {
    $class = CachedStaticFixture::class;
    self::assertSame(9, $this->app->invokeMethod("$class::calc", ['base' => 3]));
    self::assertSame(27, $this->app->invokeMethod([$class, 'calc'], ['base' => 3, 'exp' => 3]));
    self::assertSame(16, $this->app->invokeMethod("$class::calc", ['base' => 4]));
  }

  /**
   * 测试 Between 规则在缓存复用下对不同参数分别校验
   */
  public function testMultipleRulesOnCachedShape(): void
  {
    $case = new class {
      /**
       * 区间规则参数
       */
      public function level(#[Between(1, 10)] int $level): int
      {
        return $level;
      }
    };
    self::assertSame(1, $this->app->invokeMethod([$case, 'level'], ['level' => 1]));
    self::assertSame(10, $this->app->invokeMethod([$case, 'level'], ['level' => 10]));
    $this->expectException(ValidateException::class);
    $this->app->invokeMethod([$case, 'level'], ['level' => 11]);
  }
}

/**
 * 静态方法缓存测试夹具类
 *
 * createFromMethodName 不支持匿名类名，静态方法字符串形式调用需具名类
 */
class CachedStaticFixture
{
  /**
   * 幂运算
   */
  public static function calc(int $base, int $exp = 2): int
  {
    return $base ** $exp;
  }
}
