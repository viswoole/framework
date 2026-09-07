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

namespace Viswoole\Core;

/**
 * 框架内置事件枚举
 *
 * 聚合定义框架所有内置事件，作为事件名的单一事实来源，
 * 避免硬编码字符串导致的拼写错误，支持 IDE 自动补全与跳转。
 * 枚举值统一小写（与 Event 管理器的归一化规则保持一致）。
 *
 * 使用示例：
 * ```
 * use Viswoole\Core\Facade\Event;
 * use Viswoole\Core\FrameworkEvent;
 * // 监听服务启动完成事件
 * Event::on(FrameworkEvent::ServerStarted, function (\Viswoole\Core\Server $server) {
 *   // ...
 * });
 * // 也可以直接使用事件名字符串监听（向后兼容）
 * Event::on('serverstarted', function (\Viswoole\Core\Server $server) { ... });
 * ```
 */
enum FrameworkEvent: string
{
  /**
   * App 初始化完成事件
   *
   * 触发时机：App 构造完成、依赖绑定与 initialize() 执行完毕后。
   * 回调参数：无
   */
  case AppInitialized = 'appinitialized';

  /**
   * App 即将销毁事件
   *
   * 触发时机：App 对象析构时，供服务清理资源（如关闭连接、刷盘）。
   * 回调参数：无
   */
  case AppDestroying = 'appdestroying';

  /**
   * 路由初始化前事件
   *
   * 触发时机：路由初始化开始前、加载配置路由与注解路由之前，供其他模块注册路由。
   * 回调参数：无
   */
  case RouterInitializing = 'routerinitializing';

  /**
   * 路由初始化完成事件
   *
   * 触发时机：配置路由、注解路由加载并解析完成后。
   * 回调参数：无
   */
  case RouterInitialized = 'routerinitialized';

  /**
   * 服务创建前事件
   *
   * 触发时机：加载服务配置之前，此时 Swoole Server 尚未创建。
   * 回调参数：[\Viswoole\Core\Server $server] 框架 Server 实例
   */
  case ServerCreating = 'servercreating';

  /**
   * 服务创建后事件
   *
   * 触发时机：Swoole Server 创建完成并绑定到容器之后、服务启动之前。
   * 回调参数：[\Viswoole\Core\Server $server] 框架 Server 实例
   */
  case ServerCreated = 'servercreated';

  /**
   * 服务启动前事件
   *
   * 触发时机：调用 start() 之后、Swoole 事件循环启动之前。
   * 回调参数：[\Viswoole\Core\Server $server] 框架 Server 实例
   */
  case ServerStarting = 'serverstarting';

  /**
   * 服务启动完成事件
   *
   * 触发时机：Swoole onStart 回调中，主进程事件循环已启动。
   * 回调参数：[\Viswoole\Core\Server $server] 框架 Server 实例
   */
  case ServerStarted = 'serverstarted';

  /**
   * 服务关闭前事件
   *
   * 触发时机：Swoole onBeforeShutdown 回调中，供执行清理工作（如日志刷盘）。
   * 回调参数：[\Viswoole\Core\Server $server] 框架 Server 实例
   */
  case ServerShuttingDown = 'servershuttingdown';
}
