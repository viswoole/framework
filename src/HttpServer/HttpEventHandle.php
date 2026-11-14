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

namespace Viswoole\HttpServer;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Viswoole\Core\App;
use Viswoole\Core\Exception\Handle;
use Viswoole\Core\Exception\ServerNotFoundException;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\Http\RequestIdHeader;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/**
 * HTTP 请求事件处理器
 *
 * 作为 Swoole HTTP Server 的 onRequest 回调入口，
 * 负责将 Swoole 原始请求/响应对象封装为 PSR-7 风格对象，
 * 交由路由分发并处理响应输出。
 */
class HttpEventHandle
{
  /**
   * Swoole onRequest 回调入口
   *
   * 构造请求/响应对象 → 路由分发 → 处理响应；异常交由异常处理器渲染。
   *
   * @param Request $request Swoole 原始请求对象
   * @param Response $response Swoole 原始响应对象
   * @throws ServerNotFoundException 服务未找到时抛出
   */
  public static function onRequest(
    Request  $request,
    Response $response
  ): void
  {
    $monitor = self::startMonitor($request);
    RequestIdHeader::apply($response);
    try {
      self::dispatch($request, $response);
    } catch (Throwable $error) {
      TraceContext::current()?->recordException($error);
      self::renderException($error, $response);
    } finally {
      $monitor?->finish();
    }
  }

  /**
   * 开启慢请求监控采集
   *
   * 监控属于旁路设施，任何内部失败（服务未注册、配置异常等）只记录告警，
   * 绝不能让异常逃逸 Swoole 导致客户端挂起。
   *
   * @param Request $request 原始请求
   * @return SlowRequestMonitor|null 已开启的监控器，未启用或启动失败时为 null
   */
  private static function startMonitor(Request $request): ?SlowRequestMonitor
  {
    try {
      $app = App::factory();
      $options = $app->make(MonitorOptions::class);
      if (!$options->isEnabled) { return null; }
      $monitor = $app->make(SlowRequestMonitor::class);
      // 路径提取逻辑与 RequestCapture::capture 保持一致，作为 excluded_paths 匹配依据
      $path = RequestCapture::extractPath($request);
      $monitor->start(
        $path,
        fn (PayloadSanitizer $sanitizer): array => new RequestCapture($options, $sanitizer)->capture($request),
        canReceiveSanitizer: true
      );
      return $monitor;
    } catch (Throwable $monitorError) {
      // 监控启动失败不影响业务请求，降级为无监控执行；
      // 只记录异常类型，配置或连接异常的消息可能携带 DSN、账号等敏感信息
      trigger_error('慢请求监控启动失败: ' . $monitorError::class, E_USER_WARNING);
      return null;
    }
  }

  /**
   * 封装请求对象并交由路由分发
   *
   * 将 Swoole 原始请求/响应封装为 PSR-7 风格对象后交给路由，
   * 动态路由匹配到的参数回填到请求参数中。
   *
   * @param Request $request 原始请求
   * @param Response $response 原始响应
   * @throws Throwable 路由分发或响应处理失败时向上抛出，由 onRequest 统一渲染
   */
  private static function dispatch(Request $request, Response $response): void
  {
    $app = App::factory();
    /**
     * @var RequestInterface $psr7Request
     */
    $psr7Request = $app->make(RequestInterface::class, [$request]);
    /**
     * @var ResponseInterface $psr7Response
     */
    $psr7Response = $app->make(ResponseInterface::class, [$response]);
    // 交由路由分发
    $result = $app->router->dispatch(
      $psr7Request->getPath(),
      $psr7Request->getMethod(),
      $psr7Request->getUri()->getHost(),
      callback: function (array $params) use ($psr7Request): void {
        // 将动态路由匹配到的参数添加到请求参数中
        $psr7Request->addParams($params, 'get');
      }
    );
    self::handleResponse($result, $psr7Response);
  }

  /**
   * 根据路由返回值类型选择响应方式
   *
   * ResponseInterface 实例直接 send；数组/对象以 JSON 响应；其它转为字符串响应。
   *
   * @param mixed $result 路由分发返回值
   * @param ResponseInterface $psr7Response 响应对象
   */
  public static function handleResponse(mixed $result, ResponseInterface $psr7Response): void
  {
    if ($psr7Response->isWritable()) {
      if ($result instanceof ResponseInterface) {
        $result->send();
      } elseif (is_array($result) || is_object($result)) {
        // 返回的不是 Response 对象，则对返回的参数进行 JSON 格式化。
        $psr7Response->json($result)->send();
      } else {
        $psr7Response->send((string)$result);
      }
    }
  }

  /**
   * 渲染请求处理过程中抛出的异常
   *
   * 委托给配置的异常处理器（默认 Handle::render）；渲染自身失败时兜底输出
   * 500，兜底也失败（如连接已断开）仅记录告警。
   *
   * @param Throwable $e 业务异常
   * @param Response $response 原始响应
   */
  private static function renderException(Throwable $e, Response $response): void
  {
    $app = App::factory();
    $exceptionHandle = $app->server->getConfig()['exception_handle'] ?? Handle::class;
    try {
      $app->invokeMethod([$exceptionHandle, 'render'], [$e]);
    } catch (Throwable $renderError) {
      // 异常渲染自身失败（如响应已结束、JSON 编码失败）时兜底输出 500，
      // 避免异常逃逸 Swoole 导致客户端挂起至超时
      try {
        $app->make(ResponseInterface::class, [$response])
            ->status(500)
            ->send('Internal Server Error');
      } catch (Throwable) {
        // 响应完全不可用（如连接已断开）时仅记录告警，由 Swoole 断开连接
        trigger_error(
          '异常渲染失败: ' . get_class($renderError) . ': ' . $renderError->getMessage(),
          E_USER_WARNING
        );
      }
    }
  }
}
