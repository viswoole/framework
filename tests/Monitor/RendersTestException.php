<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use Throwable;
use Viswoole\Core\App;

/** HTTP 异常渲染替身，输出确定的业务响应。 */
final class RendersTestException
{
  /**
   * 渲染为确定的 500 JSON 响应，供断言核对
   *
   * @param Throwable $error 请求异常
   */
  public function render(Throwable $error): void
  {
    App::factory()->response->status(500)->json(['message' => 'request failed'])->send();
  }
}
