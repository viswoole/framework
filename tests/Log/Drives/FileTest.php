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

namespace Viswoole\Tests\Log\Drives;

use PHPUnit\Framework\TestCase;
use Viswoole\Log\Drives\File;
use Viswoole\Log\LogManager;

/**
 * 文件日志驱动测试
 */
class FileTest extends TestCase
{
  /** @var string 测试用临时目录根路径 */
  private string $tmpRoot;

  /**
   * 每个 测试用例 前创建独立的临时目录
   *
   * @return void
   */
  protected function setUp(): void
  {
    $this->tmpRoot = sys_get_temp_dir() . '/viswoole_log_file_test_' . uniqid();
    mkdir($this->tmpRoot, 0755, true);
  }

  /**
   * 每个 测试用例 后清理临时目录
   *
   * @return void
   */
  protected function tearDown(): void
  {
    if (is_dir($this->tmpRoot)) {
      // 递归删除测试产生的临时文件与目录
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
      rmdir($this->tmpRoot);
    }
  }

  /**
   * 测试日志目录创建失败时 save() 降级为 trigger_error 而非抛出异常
   *
   * save() 的调用方之一是 Recorder::__destruct()，析构函数中的未捕获异常
   * 会变成无法捕获的致命错误杀死 worker，因此目录创建失败必须降级处理
   *
   * @return void
   */
  public function testSaveDegradesWhenDirectoryCreationFails(): void
  {
    // 预创建日期目录，并用同名文件占用级别目录，使 getLogDir 创建必定失败
    $logDir = $this->tmpRoot . '/logs/' . date('Ymd');
    mkdir($logDir, 0755, true);
    touch($logDir . '/info');

    $drive = new File(log_dir: $this->tmpRoot . '/logs');

    // 自定义错误处理器捕获降级产生的 E_USER_WARNING，
    // 避免 PHPUnit 的 failOnWarning 配置将降级告警误判为测试失败
    $warnings = [];
    // errno 为 set_error_handler 固定回调签名的首参，此处无需使用
    set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
      $warnings[] = $errstr;
      return true;
    });
    try {
      // 不抛出异常即代表降级成功，此处若抛出异常测试将直接失败
      $drive->save([LogManager::createLogData('info', 'test', [])]);
    } finally {
      restore_error_handler();
    }

    // warnings 中同时包含被 @ 抑制后仍送达自定义处理器的 mkdir 原生告警
    // 与 save() 降级产生的 trigger_error 消息，此处只断言后者存在
    $degradeMessages = array_values(array_filter(
      $warnings,
      fn (string $message): bool => str_contains($message, '创建目录失败')
    ));
    static::assertNotEmpty($degradeMessages);
    // 降级告警应包含驱动上下文（驱动名、级别、根目录）与底层真实错误，便于定位问题
    static::assertStringContainsString('文件日志驱动写入日志失败', $degradeMessages[0]);
    static::assertStringContainsString('level=info', $degradeMessages[0]);
    static::assertStringContainsString($this->tmpRoot . '/logs', $degradeMessages[0]);
    static::assertStringContainsString('mkdir():', $degradeMessages[0]);
  }

  /**
   * 测试正常场景下日志成功写入文件
   *
   * @return void
   */
  public function testSaveWritesLogFile(): void
  {
    $logDir = $this->tmpRoot . '/logs';
    $drive = new File(log_dir: $logDir);
    $drive->save([LogManager::createLogData('info', 'test', [])]);

    $files = glob($logDir . '/' . date('Ymd') . '/info/*.log');
    static::assertNotEmpty($files);
    static::assertStringContainsString('test', (string)file_get_contents($files[0]));
  }
}
