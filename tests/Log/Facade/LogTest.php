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

namespace Viswoole\Tests\Log\Facade;

use PHPUnit\Framework\TestCase;
use Viswoole\Log\Facade\Log;

/**
 * 日志测试
 */
class LogTest extends TestCase
{
  /**
   * 测试直接写入日志
   *
   * @return void
   */
  public function testWrite()
  {
    Log::write('test', '测试写入日志');
    static::assertTrue(true);
  }

  /**
   * 测试记录日志
   *
   * @return void
   */
  public function testRecord()
  {
    Log::record('test', '测试写入日志', ['context' => 'context']);
    static::assertTrue(true);
  }
}
