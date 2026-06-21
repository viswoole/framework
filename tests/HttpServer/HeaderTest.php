<?php

declare(strict_types=1);

namespace Viswoole\Tests\HttpServer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\HttpServer\Header;

/**
 * HTTP头部处理测试
 *
 * 测试 Header 类的验证、格式化及头部查找功能
 */
class HeaderTest extends TestCase
{
  /**
   * 测试合法头部验证通过
   *
   * @return void
   */
  public function testValidateValidHeader(): void
  {
    // 不抛异常即验证通过
    Header::validate('Content-Type', 'application/json');
    Header::validate('X-Custom', ['value1', 'value2']);
    self::assertTrue(true);
  }

  /**
   * 测试空名称抛出异常
   *
   * @return void
   */
  public function testValidateEmptyName(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Header::validate('', 'value');
  }

  /**
   * 测试名称含换行符抛出异常
   *
   * @return void
   */
  public function testValidateNameWithNewline(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Header::validate("Content-Type\n", 'value');
  }

  /**
   * 测试名称含回车符抛出异常（CRLF注入防护）
   *
   * @return void
   */
  public function testValidateNameWithCarriageReturn(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Header::validate("Content-Type\r", 'value');
  }

  /**
   * 测试名称含冒号抛出异常
   *
   * @return void
   */
  public function testValidateNameWithColon(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Header::validate('Content:Type', 'value');
  }

  /**
   * 测试空字符串值抛出异常
   *
   * @return void
   */
  public function testValidateEmptyStringValue(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Header::validate('Content-Type', '');
  }

  /**
   * 测试空数组值抛出异常
   * 注意：源码中异常消息含 $value 拼接，数组转字符串会产生 PHP Warning
   *
   * @return void
   */
  public function testValidateEmptyArrayValue(): void
  {
    // 临时拦截源码中 Array to string conversion 的 Warning
    $previousHandler = set_error_handler(function (int $errno) use (&$previousHandler): bool {
      if ($errno === E_WARNING) return true; // 抑制此 Warning
      return $previousHandler ? $previousHandler(...func_get_args()) : false;
    });
    try {
      $this->expectException(InvalidArgumentException::class);
      Header::validate('Content-Type', []);
    } finally {
      restore_error_handler();
    }
  }

  /**
   * 测试数组含空元素抛出异常
   *
   * @return void
   */
  public function testValidateArrayWithEmptyElement(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Header::validate('Content-Type', ['valid', '']);
  }

  /**
   * 测试首字母大写格式化（title 模式）
   *
   * @return void
   */
  public function testFormatNameTitle(): void
  {
    $result = Header::formatName('content-type', 'title');
    self::assertEquals('Content-Type', $result);
  }

  /**
   * 测试大写格式化（upper 模式）
   *
   * @return void
   */
  public function testFormatNameUpper(): void
  {
    $result = Header::formatName('content-type', 'upper');
    self::assertEquals('CONTENT-TYPE', $result);
  }

  /**
   * 测试小写格式化（lower 模式）
   *
   * @return void
   */
  public function testFormatNameLower(): void
  {
    $result = Header::formatName('Content-Type', 'lower');
    self::assertEquals('content-type', $result);
  }

  /**
   * 测试数组模式格式化标头
   *
   * @return void
   */
  public function testFormatHeadersArrayMode(): void
  {
    $headers = [
      'Content-Type' => 'application/json, text/html',
      'Accept' => 'text/plain',
    ];
    $result = Header::formatHeaders($headers, 'array');
    // 字符串值被 explode 为数组，逗号后保留原始空格
    self::assertIsArray($result['Content-Type']);
    self::assertEquals(['application/json', ' text/html'], $result['Content-Type']);
    self::assertIsArray($result['Accept']);
    self::assertEquals(['text/plain'], $result['Accept']);
  }

  /**
   * 测试字符串模式格式化标头
   *
   * @return void
   */
  public function testFormatHeadersStringMode(): void
  {
    $headers = [
      'Content-Type' => ['application/json', 'text/html'],
    ];
    $result = Header::formatHeaders($headers, 'string');
    // 数组值被 implode 为字符串
    self::assertIsString($result['Content-Type']);
    self::assertEquals('application/json,text/html', $result['Content-Type']);
  }

  /**
   * 测试带名称格式化模式格式化标头
   *
   * @return void
   */
  public function testFormatHeadersWithNameModel(): void
  {
    $headers = [
      'content-type' => 'application/json',
    ];
    $result = Header::formatHeaders($headers, 'string', 'title');
    self::assertArrayHasKey('Content-Type', $result);
  }

  /**
   * 测试检查头部是否存在（存在时返回真实键名）
   *
   * @return void
   */
  public function testHasHeader(): void
  {
    $headers = [
      'Content-Type' => 'application/json',
      'X-Custom' => 'value',
    ];
    $result = Header::hasHeader('content-type', $headers);
    self::assertEquals('Content-Type', $result);
  }

  /**
   * 测试头部不存在时返回 false
   *
   * @return void
   */
  public function testHasHeaderNotFound(): void
  {
    $headers = [
      'Content-Type' => 'application/json',
    ];
    $result = Header::hasHeader('X-Not-Exist', $headers);
    self::assertFalse($result);
  }
}
