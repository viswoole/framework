<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Database\Raw;

/**
 * Raw 原生SQL表达式测试
 *
 * 测试 Raw 类的构造、字符串转换、参数合并及 JSON 序列化等功能
 */
class RawTest extends TestCase
{
  /**
   * 测试构造函数正确赋值属性
   *
   * @return void
   */
  public function testConstructor(): void
  {
    $raw = new Raw('SELECT * FROM users', [1, 2]);
    self::assertEquals('SELECT * FROM users', $raw->sql);
    self::assertEquals([1, 2], $raw->bindings);
  }

  /**
   * 测试 __toString 魔术方法
   *
   * @return void
   */
  public function testToString(): void
  {
    $raw = new Raw('SELECT * FROM users WHERE id = ?', [1]);
    self::assertEquals('SELECT * FROM users WHERE id = 1', (string)$raw);
  }

  /**
   * 测试位置占位符绑定
   *
   * @return void
   */
  public function testMergeWithPositionBindings(): void
  {
    $result = Raw::merge('SELECT * FROM users WHERE id = ? AND status = ?', [1, 2]);
    self::assertEquals('SELECT * FROM users WHERE id = 1 AND status = 2', $result);
  }

  /**
   * 测试命名占位符绑定
   *
   * @return void
   */
  public function testMergeWithNamedBindings(): void
  {
    $result = Raw::merge(
      'SELECT * FROM users WHERE id = :id AND name = :name',
      ['id' => 1, 'name' => 'test']
    );
    self::assertEquals("SELECT * FROM users WHERE id = 1 AND name = 'test'", $result);
  }

  /**
   * 测试混合占位符绑定（位置占位符和命名占位符同时存在）
   *
   * @return void
   */
  public function testMergeWithMixedBindings(): void
  {
    $result = Raw::merge(
      'SELECT * FROM users WHERE id = ? AND status = :status',
      [1, 'status' => 'active']
    );
    self::assertEquals("SELECT * FROM users WHERE id = 1 AND status = 'active'", $result);
  }

  /**
   * 测试字符串值加引号转义
   *
   * @return void
   */
  public function testMergeWithStringValue(): void
  {
    $result = Raw::merge("SELECT * FROM users WHERE name = ?", ["O'Brien"]);
    self::assertEquals("SELECT * FROM users WHERE name = 'O\\'Brien'", $result);
  }

  /**
   * 测试 null 值转为 NULL
   *
   * @return void
   */
  public function testMergeWithNullValue(): void
  {
    $result = Raw::merge('SELECT * FROM users WHERE deleted_at = ?', [null]);
    self::assertEquals('SELECT * FROM users WHERE deleted_at = NULL', $result);
  }

  /**
   * 测试数组值 implode 为逗号分隔字符串
   *
   * @return void
   */
  public function testMergeWithArrayValue(): void
  {
    $result = Raw::merge('SELECT * FROM users WHERE id IN (?)', [[1, 2, 3]]);
    self::assertEquals('SELECT * FROM users WHERE id IN (1,2,3)', $result);
  }

  /**
   * 测试空绑定直接返回原始 SQL
   *
   * @return void
   */
  public function testMergeWithEmptyBindings(): void
  {
    $result = Raw::merge('SELECT * FROM users', []);
    self::assertEquals('SELECT * FROM users', $result);
  }

  /**
   * 测试 jsonSerialize 方法
   *
   * @return void
   */
  public function testJsonSerialize(): void
  {
    $raw = new Raw('SELECT * FROM users WHERE id = ?', [1]);
    self::assertEquals('SELECT * FROM users WHERE id = 1', $raw->jsonSerialize());
  }

  /**
   * 测试 toString 方法
   *
   * @return void
   */
  public function testToStringMethod(): void
  {
    $raw = new Raw('SELECT * FROM users WHERE id = ?', [1]);
    self::assertEquals('SELECT * FROM users WHERE id = 1', $raw->toString());
  }
}
