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

declare (strict_types=1);

namespace Viswoole\Tests\Core;

use Exception;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\Console\Output;

/**
 * Output::__callStatic 代理回归测试
 *
 * 修复前缺陷：__callStatic 把第 2 个位置参数硬编码为回溯层级——
 * string 标签（如 Output::debug($msg, 'ACCESSOR', backtrace: 0)）经
 * $arguments[1] 传入 echo 第 4 参抛 TypeError（__callStatic 转发契约
 * 不支持自定义标签），且命名参数 backtrace 被静默丢弃。
 *
 * 修复语义：第 2 位 int=回溯层级（旧契约兼容）/ string=自定义标签；
 * 支持 label / backtrace 命名参数；未知级别照旧抛 Exception。
 */
class OutputTest extends TestCase
{
  /**
   * 基础用法：级别名作标签，默认回溯层级含调用源
   */
  public function testBasicLevelCall(): void
  {
    ob_start();
    Output::debug('hello');
    $out = ob_get_clean();

    self::assertStringContainsString('[DEBUG]: hello', $out);
    self::assertStringContainsString('in ', $out);
  }

  /**
   * 旧契约：第 2 位 int 为回溯层级，0 时不输出调用源
   */
  public function testLegacyIntSecondArgIsBacktrace(): void
  {
    ob_start();
    Output::debug('hello', 0);
    $out = ob_get_clean();

    self::assertStringContainsString('[DEBUG]: hello', $out);
    self::assertStringNotContainsString('in ', $out);
  }

  /**
   * 第 2 位 string 为自定义标签（修复前 TypeError），命名参数 backtrace 生效
   */
  public function testCustomLabelWithNamedBacktrace(): void
  {
    ob_start();
    Output::debug('hello', 'ACCESSOR', backtrace: 0);
    $out = ob_get_clean();

    self::assertStringContainsString('[ACCESSOR]: hello', $out);
    self::assertStringNotContainsString('in ', $out);
  }

  /**
   * 命名参数 backtrace 单独使用生效
   */
  public function testNamedBacktraceOnly(): void
  {
    ob_start();
    Output::debug('hello', backtrace: 0);
    $out = ob_get_clean();

    self::assertStringContainsString('[DEBUG]: hello', $out);
    self::assertStringNotContainsString('in ', $out);
  }

  /**
   * 命名参数 label 指定自定义标签
   */
  public function testNamedLabel(): void
  {
    ob_start();
    Output::debug('hello', label: 'SQL', backtrace: 0);
    $out = ob_get_clean();

    self::assertStringContainsString('[SQL]: hello', $out);
  }

  /**
   * 小写级别名归一为大写标签
   */
  public function testLowercaseLevelNameNormalized(): void
  {
    ob_start();
    Output::warning('careful', 0);
    $out = ob_get_clean();

    self::assertStringContainsString('[WARNING]: careful', $out);
  }

  /**
   * 不存在的级别名应抛出异常
   */
  public function testUndefinedLevelThrows(): void
  {
    $this->expectException(Exception::class);
    Output::notALevel('x');
  }
}
