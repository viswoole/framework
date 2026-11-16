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

declare(strict_types=1);

namespace Viswoole\Core\Server\Shutdown;

use Viswoole\Core\Exception\ServerException;

/**
 * 统一 Swoole 任务退出窗口和命令等待预算，不负责发送信号
 */
final readonly class ShutdownPolicy
{
  /**
   * 默认优雅退出窗口（秒）
   */
  public const int DEFAULT_GRACE_SECONDS = 60;
  /**
   * 覆盖 Swoole manager 两倍窗口后的命令侧预留时间（秒）
   */
  public const int EXIT_MARGIN_SECONDS = 5;
  // Swoole manager 使用两倍窗口调用 alarm，结果必须落在无符号 32 位秒数范围。
  private const int MAX_GRACE_SECONDS = 2147483647;

  /**
   * @param int $graceSeconds 优雅退出窗口（秒）
   * @throws ServerException 窗口超出合法范围时抛出
   */
  public function __construct(public int $graceSeconds = self::DEFAULT_GRACE_SECONDS)
  {
    if ($graceSeconds < 1 || $graceSeconds > self::MAX_GRACE_SECONDS) {
      throw new ServerException('max_wait_time 必须为 1 至 2147483647 秒，以便协调优雅退出与强停超时');
    }
  }

  /**
   * 校验合并后的选项，旧配置也启用协程优雅退出。
   * @param array $options 全局与服务级配置合并结果
   * @return array 传递给 Swoole 的选项
   */
  public static function normalizeOptions(array $options): array
  {
    $policy = self::fromOptions($options);
    if (array_key_exists('reload_async', $options) && !$options['reload_async']) {
      throw new ServerException('安全停止协程任务要求 reload_async=true，请调整服务配置');
    }
    $options['reload_async'] = true;
    $options['max_wait_time'] = $policy->graceSeconds;
    return $options;
  }

  /**
   * 解析实际启动选项中的退出预算
   *
   * @param array $options 实际启动选项
   * @return self 退出预算
   * @throws ServerException max_wait_time 不是整数秒时抛出
   */
  public static function fromOptions(array $options): self
  {
    $value = $options['max_wait_time'] ?? self::DEFAULT_GRACE_SECONDS;
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
      throw new ServerException('max_wait_time 必须是整数秒');
    }
    return new self((int)$value);
  }

  /**
   * 计算命令侧的等待预算
   *
   * @return int 覆盖 Swoole manager 的两倍退出预算，并预留主进程清理时间
   */
  public function waitSeconds(): int
  {
    return $this->graceSeconds * 2 + self::EXIT_MARGIN_SECONDS;
  }
}
