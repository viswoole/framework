<?php

declare(strict_types=1);

namespace Viswoole\Tests\Router;

use PHPUnit\Framework\TestCase;
use Viswoole\Router\Annotation\RouteMapping;
use Viswoole\Router\Route\Route;

/**
 * RouteMapping 注解 suffix/domain 参数类型测试
 *
 * 验证字符串与字符串数组两种形式均可使用：
 * 字符串由 RouteAnnotation 构造期规范化为数组，再经 setSuffix/setDomain 展开。
 */
class AnnotationSuffixDomainTest extends TestCase
{
  /**
   * 测试 suffix/domain 传单个字符串
   *
   * @return void
   */
  public function testStringSuffixAndDomain(): void
  {
    $mapping = new RouteMapping(paths: 'demo', suffix: '.html', domain: 'example.com');
    $route = $mapping->create(function () {
      return 'demo';
    });
    static::assertInstanceOf(Route::class, $route);
    static::assertSame(['.html'], $route->getSuffix());
    static::assertSame(['example.com'], $route->getDomain());
  }

  /**
   * 测试 suffix/domain 传字符串数组（原有形式回归）
   *
   * @return void
   */
  public function testArraySuffixAndDomain(): void
  {
    $mapping = new RouteMapping(
      paths: 'demo',
      suffix: ['.html', '.json'],
      domain: ['a.example.com', 'b.example.com']
    );
    $route = $mapping->create(function () {
      return 'demo';
    });
    static::assertSame(['.html', '.json'], $route->getSuffix());
    static::assertSame(['a.example.com', 'b.example.com'], $route->getDomain());
  }

  /**
   * 测试不传 suffix/domain 时保持默认值
   *
   * @return void
   */
  public function testDefaultSuffixAndDomain(): void
  {
    $mapping = new RouteMapping(paths: 'demo');
    $route = $mapping->create(function () {
      return 'demo';
    });
    // 未显式设置时，后缀与域名默认均为通配符 '*'（config/router.suffix|domain 默认值）
    static::assertSame(['*'], $route->getSuffix());
    static::assertSame(['*'], $route->getDomain());
  }
}
