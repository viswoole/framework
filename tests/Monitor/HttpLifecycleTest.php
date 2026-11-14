<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Viswoole\Core\App;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Contract\ResponseInterface;
use Viswoole\HttpServer\HttpEventHandle;
use Viswoole\HttpServer\Response;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\Router;

/**
 * HTTP 入口生命周期测试
 *
 * 走真实 HTTP 入口，验证请求快照、路由操作和最终响应归入同一记录。
 */
final class HttpLifecycleTest extends TestCase
{
  /**
   * 正常请求：快照、路由内操作、JSON 响应归入同一记录，结束后上下文清空
   */
  public function testHttpEntryCapturesWholeRequestAndCleansContext(): void
  {
    $app = App::factory();
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]);
    $writer = new InMemoryTraceWriter();
    $originalOptions = $app->make(MonitorOptions::class);
    $originalRouter = $app->router;
    $app->bind(MonitorOptions::class, $options);
    $app->bind(SlowRequestMonitor::class, new SlowRequestMonitor($options, $writer));
    $request = $this->createRequest();
    $rawResponse = $this->createResponse();
    $app->bind(ResponseInterface::class, new Response($rawResponse));
    $router = $this->createMock(Router::class);
    $router->method('dispatch')->willReturnCallback(static function (): array {
      TraceContext::measure(['type' => 'cache', 'operation' => 'get'], static fn (): bool => false);
      return ['token' => 'response-private', 'ok' => true];
    });
    $app->bind(Router::class, $router);
    try {
      HttpEventHandle::onRequest($request, $rawResponse);
      self::assertCount(1, $writer->records);
      $record = $writer->records[0];
      self::assertSame('/monitor', $record['request']['path']);
      self::assertSame('[REDACTED]', $record['request']['query']['token']);
      self::assertSame('cache', $record['operations'][0]['type']);
      self::assertSame(['token' => '[REDACTED]', 'ok' => true], $record['response']['body']['content']);
      self::assertNull(TraceContext::current());
    } finally {
      $this->restoreBindings($originalOptions, $originalRouter);
    }
  }

  /**
   * 异常请求：异常类型入记录、兜底 500 响应入记录，结束后上下文清空
   */
  public function testRenderedExceptionIsCapturedBeforeFinalCleanup(): void
  {
    $app = App::factory();
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0, 'request_id' => [
      'generator' => static fn (): string => 'error-id', 'response_header' => 'X-Request-Id',
    ]]);
    $writer = new InMemoryTraceWriter();
    $originalOptions = $app->make(MonitorOptions::class);
    $originalRouter = $app->router;
    $app->bind(MonitorOptions::class, $options);
    $app->bind(SlowRequestMonitor::class, new SlowRequestMonitor($options, $writer));
    $server = $this->createMock(\Viswoole\Core\Server::class);
    $server->method('getConfig')->willReturn(['exception_handle' => RendersTestException::class]);
    $app->bind(\Viswoole\Core\Server::class, $server);
    $router = $this->createMock(Router::class);
    $router->method('dispatch')->willThrowException(new \RuntimeException('private detail'));
    $app->bind(Router::class, $router);
    try {
      $headers = [];
      HttpEventHandle::onRequest($this->createRequest(), $this->createResponse($headers));
      self::assertSame('error-id', $headers['x-request-id']);
      $record = $writer->records[0];
      self::assertSame(\RuntimeException::class, $record['exception']);
      self::assertSame('error-id', $record['request_id']);
      self::assertSame('error-id', $record['response']['headers']['x-request-id']);
      self::assertSame(500, $record['response']['status']);
      self::assertSame(['message' => 'request failed'], $record['response']['body']['content']);
      self::assertNull(TraceContext::current());
    } finally {
      $app->remove(\Viswoole\Core\Server::class);
      $this->restoreBindings($originalOptions, $originalRouter);
    }
  }

  /**
   * 快请求同样返回自定义 ID
   *
   * 阈值只决定落盘，不决定关联标头；标头在实际 end 调用前已经设置。
   *
   * @return void
   */
  public function testFastRequestReceivesConfiguredRequestIdHeader(): void
  {
    $app = App::factory();
    $originalOptions = $app->make(MonitorOptions::class);
    $originalRouter = $app->router;
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 1000000, 'capture_response' => false,
      'request_id' => ['generator' => static fn (): string => 'custom-id', 'response_header' => 'X-Correlation-Id']]);
    $writer = new InMemoryTraceWriter();
    $headers = [];
    $raw = $this->createResponse($headers);
    $app->bind(MonitorOptions::class, $options);
    $app->bind(SlowRequestMonitor::class, new SlowRequestMonitor($options, $writer));
    $app->bind(ResponseInterface::class, new Response($raw));
    $router = $this->createMock(Router::class);
    $router->method('dispatch')->willReturnCallback(static function () use (&$headers): array {
      self::assertSame('custom-id', $headers['x-correlation-id']);
      return ['ok' => true];
    });
    $app->bind(Router::class, $router);
    try {
      HttpEventHandle::onRequest($this->createRequest(), $raw);
      self::assertSame('custom-id', $headers['x-correlation-id']);
      self::assertSame([], $writer->records);
      self::assertNull(TraceContext::current());
    } finally {
      $this->restoreBindings($originalOptions, $originalRouter);
    }
  }

  /**
   * 构造 Swoole 请求替身并绑定框架请求替身
   *
   * URI 协议推断依赖运行中的 Swoole Server，本测试用请求接口替身隔离该依赖。
   *
   * @return SwooleRequest Swoole 原始请求替身
   */
  private function createRequest(): SwooleRequest
  {
    $request = $this->createMock(SwooleRequest::class);
    $request->server = ['request_method' => 'GET', 'path_info' => '/monitor', 'request_uri' => '/monitor', 'remote_addr' => '127.0.0.1'];
    $request->header = ['host' => 'localhost'];
    $request->get = ['token' => 'request-private'];
    $request->method('rawContent')->willReturn('');
    $frameworkRequest = $this->createMock(\Viswoole\HttpServer\Request::class);
    $frameworkRequest->method('getPath')->willReturn('/monitor');
    $frameworkRequest->method('getMethod')->willReturn('GET');
    $frameworkRequest->method('getUri')->willReturn(new \Viswoole\HttpServer\Message\Uri(host: 'localhost'));
    App::factory()->bind(\Viswoole\HttpServer\Request::class, $frameworkRequest);
    return $request;
  }

  /**
   * 构造 Swoole 响应替身
   *
   * @param array<string,string>|null $headers 接收实际设置的标头
   * @return SwooleResponse 可写且 end 成功的响应替身
   */
  private function createResponse(?array &$headers = null): SwooleResponse
  {
    $response = $this->createMock(SwooleResponse::class);
    $headers ??= [];
    $response->method('header')->willReturnCallback(static function (string $key, string $value) use (&$headers): bool {
      $headers[strtolower($key)] = $value;
      return true;
    });
    $response->method('status')->willReturn(true);
    $response->method('isWritable')->willReturn(true);
    $response->method('end')->willReturn(true);
    return $response;
  }

  /**
   * 还原测试期间替换的容器绑定，避免替身泄漏到套件后续测试
   *
   * @param MonitorOptions $options 原监控配置绑定
   * @param Router $router 原路由绑定
   */
  private function restoreBindings(MonitorOptions $options, Router $router): void
  {
    $app = App::factory();
    $app->bind(MonitorOptions::class, $options);
    $app->bind(Router::class, $router);
    $app->remove(SlowRequestMonitor::class);
    $app->remove(RequestInterface::class);
    $app->remove(ResponseInterface::class);
    // createRequest 绑定的框架请求替身一并清理
    $app->remove(\Viswoole\HttpServer\Request::class);
    TraceContext::clear();
  }
}
