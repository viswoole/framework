<?php

declare(strict_types=1);

/**
 * 职责：传递受跟踪用户进程的原生构造选项。
 */

namespace Viswoole\Core\Server\Shutdown;

/**
 * 保留 Swoole 用户进程的输入输出、管道与协程配置。
 */
final readonly class UserProcessOptions
{
  /**
   * 配置原生进程，默认值与 Swoole 的 Process 构造参数一致。
   *
   * @param bool $redirectStdio 是否将标准输入输出重定向到管道
   * @param int $pipeType 管道类型，关闭标准输入输出重定向时传入 0 可禁用管道
   * @param bool $enableCoroutine 是否在协程中运行进程入口
   */
  public function __construct(
    /**
     * @var bool 是否重定向标准输入输出
     */
    public bool $redirectStdio = false,
    /**
     * @var int 原生管道类型
     */
    public int $pipeType = SOCK_DGRAM,
    /**
     * @var bool 是否开启入口协程
     */
    public bool $enableCoroutine = false,
  ) {
  }
}
