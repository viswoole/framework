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
use Viswoole\Core\App;
use Viswoole\Core\Console\Output;

class LogTest extends TestCase
{

  public function test()
  {
    try {
      App::factory()->log->debug('test');
    } catch (\Exception $e) {
      echo_log($e->getMessage(), Output::LEVEL_COLOR['ERROR']);
      $this->fail();
    }
    $this->assertTrue(true);
  }
}
