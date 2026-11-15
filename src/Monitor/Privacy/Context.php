<?php

declare(strict_types=1);

/* 职责：携带不可变的请求身份和隐私作用域，不持有 HTTP 对象。 */

namespace Viswoole\Monitor\Privacy;

/** 脱敏器派生时复用的请求身份。 */
final readonly class Context
{
  /**
   * 构建作用域上下文
   *
   * 原始路径只在匹配器中解码一次，方法在规则匹配时统一为大写。
   *
   * @param string $path 原始请求路径
   * @param string $method 请求方法，未知为空串
   * @param Target $target 具体采集分组
   */
  public function __construct(public string $path = '/', public string $method = '', public Target $target = Target::OPERATION_METADATA) {}
}
