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

namespace Viswoole\Monitor;

use InvalidArgumentException;
use Viswoole\Core\Service\Provider;
use Viswoole\Log\Drives\File;
use Viswoole\Monitor\Contract\TraceWriterInterface;
use Viswoole\Monitor\Writer\JsonTraceWriter;

/**
 * 慢请求监控服务提供者
 *
 * 职责：注册监控配置与可替换的记录交付接口，并在启动时预热单例。
 * 边界：不在进程单例上持有请求数据；修改配置后需重启服务生效。
 */
final class MonitorService extends Provider
{
  /**
   * 注册监控配置与显式存储选择
   *
   * TraceWriterInterface 绑定为闭包形式，应用可在 register 之后覆盖绑定
   * 以接入外部存储。
   */
  public function register(): void
  {
    $this->app->bind(MonitorOptions::class, function (): MonitorOptions {
      return new MonitorOptions(
        $this->app->config->get('monitor', []),
        (bool)$this->app->config->get('router.case_sensitive', false)
      );
    });
    $this->app->bind(TraceWriterInterface::class, function (): TraceWriterInterface {
      $writer = $this->app->make(MonitorOptions::class)->writer;
      if ($writer === null) { throw new InvalidArgumentException('启用 monitor 必须配置 writer 或绑定 TraceWriterInterface'); }
      if ($writer === JsonTraceWriter::class) {
        return new JsonTraceWriter(new File(
          json_flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
          log_dir: getRootPath() . '/runtime/slow-requests'
        ));
      }
      $instance = $this->app->make($writer);
      if (!$instance instanceof TraceWriterInterface) { throw new InvalidArgumentException('monitor.writer 必须实现 TraceWriterInterface'); }
      return $instance;
    });
  }

  /**
   * 启动时校验配置并预热监控单例
   *
   * 关闭时不创建文件日志驱动；配置非法在此处即抛出，使错误尽早暴露。
   */
  public function boot(): void
  {
    $options = $this->app->make(MonitorOptions::class);
    if ($options->isEnabled) { $this->app->make(SlowRequestMonitor::class); }
  }
}
