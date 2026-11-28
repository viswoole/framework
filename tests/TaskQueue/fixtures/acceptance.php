<?php

/*
 * 职责：观测真实异步入口的受理边界，所有持久化操作仅使用随机前缀。
 */
declare(strict_types=1);

namespace Viswoole\Core\Facade {
  /**
   * 隔离应用启动，仅注入首次 IPC。
   */
  final class Server
  {
    /**
     * @var \AcceptanceTransport 首次投递替身
     */
    public static \AcceptanceTransport $instance;

    /**
     * 取得投递替身
     *
     * @return \AcceptanceTransport 当前 IPC 入口
     */
    public static function getServer(): \AcceptanceTransport { return self::$instance; }

    /**
     * 返回固定测试 Worker
     *
     * @return int 测试 Worker ID
     */
    public static function getWorkerId(): int { return 0; }
  }
}

namespace {
  require __DIR__ . '/bootstrap.php';
  require __DIR__ . '/review-support.php';
  require __DIR__ . '/acceptance-support.php';

  use Viswoole\Core\Facade\Server;
  use Viswoole\Core\Server\Task\TaskQueue;
  use Viswoole\Core\Server\Task\TaskAcceptanceUnknownException;
  use Viswoole\Core\Server\Shutdown\ShutdownRuntime;

  $prefix = 'queue-acceptance:' . bin2hex(random_bytes(8)) . ':';
  $store = new AcceptanceStore($prefix);
  $transport = new AcceptanceTransport();
  $transport->store = $store;
  Server::$instance = $transport;
  $manager = new AcceptanceManager($store);
  $results = [];
  $accepted = [];
  try {
    foreach (['accepted' => '', 'ipcFalse' => 'false', 'ipcException' => 'throw',
      'readFailure' => '', 'retryWriteFailure' => 'retry-write'] as $name => $mode) {
      $transport->mode = $mode;
      $store->hasReadFailure = $name === 'readFailure';
      try {
        $id = $manager->emit('demo', ['businessId' => $name]);
        $results[$name] = is_string($id) && strlen($id) === 48 && $store->getQueueMetadata($id) !== null;
        if (is_string($id)) { $accepted[] = $id; }
      } catch (Throwable $exception) {
        $results[$name] = false;
      }
      $store->hasReadFailure = false;
      $store->connection->mode = '';
    }
    $results['sharedReady'] = $transport->calls === 0;
    foreach (['unknownCommitted' => 'after', 'unknownNotCommitted' => 'before', 'unknownFalse' => 'false'] as $name => $mode) {
      $store->connection->mode = $mode;
      try {
        $manager->emit('demo', []);
        $results[$name] = false;
      } catch (TaskAcceptanceUnknownException $exception) {
        $record = $store->getQueueMetadata($exception->queueId);
        $results[$name] = strlen($exception->queueId) === 48 && ($mode === 'after' ? $record !== null : $record === null)
          && $exception->getPrevious() !== null;
      } catch (Throwable $exception) {
        $results[$name] = false;
      }
    }
    $before = count($store->connection->keys($prefix . '*'));
    $evalCalls = $store->connection->evalCalls;
    // TIME 和 GET 成功后，仅让 EVAL 所需的连接获取失败，避免与命令回执丢失混淆。
    $store->connectionsBeforeFailure = 2;
    try {
      $manager->emit('demo', []);
      $results['connectionRejected'] = false;
    } catch (RedisException $exception) {
      $results['connectionRejected'] = $exception->getMessage() === 'connection acquisition failed before eval'
        && $store->connection->evalCalls === $evalCalls
        && $before === count($store->connection->keys($prefix . '*'));
    } catch (Throwable $exception) {
      $results['connectionRejected'] = false;
    } finally {
      $store->connectionsBeforeFailure = null;
    }
    try {
      $manager->emit('demo', static fn (): bool => true);
      $results['serializationRejected'] = false;
    } catch (Throwable $exception) {
      $results['serializationRejected'] = !$exception instanceof TaskAcceptanceUnknownException && $before === count($store->connection->keys($prefix . '*'));
    }
    foreach (['directZero' => '', 'directFalse' => 'false', 'directException' => 'throw'] as $name => $mode) {
      $transport->mode = $mode;
      try {
        $result = $manager->emit('demo', [], false);
        $results[$name] = $mode === '' ? $result === 0 : ($mode === 'false' && $result === false);
      } catch (RuntimeException $exception) {
        $results[$name] = $mode === 'throw' && $exception->getMessage() === 'IPC unavailable';
      }
    }
    $queue = new TaskQueue($store);
    $calls = 0;
    foreach ($accepted as $id) {
      $before = $store->getQueueMetadata($id);
      $after = $before;
      $after['leaseUntil'] = $after['retryAt'] = 0;
      $store->updateQueue($id, ['expected' => $before, 'value' => $after]);
      $dispatch = static function (array $delivery) use ($queue, &$calls): int {
        $lease = $queue->consume($delivery);
        if ($lease !== null && $queue->ack($lease)) { $calls++; }
        return 0;
      };
      $queue->dispatch($id, $dispatch);
      $queue->dispatch($id, $dispatch);
    }
    $results['recoveredOnce'] = count($accepted) === 5 && $calls === 5;
    ShutdownRuntime::prepare();
    // 仅验证受理入口读取共享停机标记；原生停机生命周期由真实服务场景覆盖。
    (new ReflectionProperty(ShutdownRuntime::class, 'isStopping'))->getValue()->set(1);
    $before = count($store->connection->keys($prefix . '*'));
    $results['stoppingRejected'] = $manager->emit('demo', []) === false && $before === count($store->connection->keys($prefix . '*'));
    echo json_encode($results, JSON_THROW_ON_ERROR);
  } finally {
    $keys = $store->connection->keys($prefix . '*');
    if ($keys !== []) { $store->connection->del($keys); }
  }
}
