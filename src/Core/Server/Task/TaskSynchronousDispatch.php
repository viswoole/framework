<?php
/**
 * 职责：管理同步任务投递；不操作持久化任务状态。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Viswoole\Core\Facade\Server;

/**
 * 同步调用的 Swoole 契约。
 */
trait TaskSynchronousDispatch
{
  /**
   * 同步阻塞投递任务并等待执行结果
   *
   * 任务处理器通过 finish() 或 return 回传的数据类型原样透传
   * （数组、整型、null、'' 等任意非空值）。
   *
   * 注意以下实测语义（Swoole 6.2 taskwait）：
   * - 处理器无任何返回值或 return null：Swoole 视为"未投递结果"，
   *   本方法将一直阻塞到超时才返回 false（性能陷阱：任务可能早已完成，
   *   但调用方仍需干等整个超时时长）。
   * - return false / finish(false)：立即返回 false，与超时返回的 false
   *   无法从值上区分。
   * - finish(null)：null 作为真实结果立即送达（与 return null 不同）。
   * - 超时或投递失败：返回 false。
   * 因此处理器应显式回传结果；需表达"业务失败"时，建议回传结构化数据
   * （如 ['ok' => false]）而不是裸 false/null。
   *
   * @param string $topic 已注册的任务主题名称
   * @param mixed $data 传递给任务处理器的业务数据
   * @param float $timeout 等待超时时间，单位秒，默认 0.5
   * @return mixed 处理器回传的原始结果（类型原样保留）；处理器无返回值/return null 时阻塞满超时后返回 false；return false 或超时/投递失败返回 false
   * @throws \Throwable 服务解析或任务投递失败时抛出
   */
  public function emitWait(
    string $topic,
    mixed  $data,
    float  $timeout = 0.5
  ): mixed
  {
    $this->has($topic);
    $data = [
      'data' => $data,
      'topic' => $topic,
      'queueId' => null,
      'dispatch_time' => null,
    ];
    return Server::getServer()->taskwait($data, $timeout);
  }

  /**
   * 同步阻塞投递多个任务并等待全部执行结果
   *
   * @param array<array-key,array> $tasks 任务列表，键为主题名称，值为传递给处理器的业务数据
   * @param float $timeout 等待超时时间，单位秒，默认 0.5
   * @param bool $isCo 是否启用协程并发调度，启用时使用 taskCo，否则使用 taskWaitMulti
   * @return array 各任务的执行结果列表
   * @throws \Throwable 主题校验、服务解析或任务投递失败时抛出
   */
  public function emitsWait(
    array $tasks,
    float $timeout = 0.5,
    bool  $isCo = false
  ): array
  {
    $topics = [];
    foreach ($tasks as $topic => $data) {
      // PHP 会把数字字符串主题转成整数数组键，投递前恢复字符串契约。
      $topic = (string)$topic;
      $this->has($topic);
      $topics[] = [
        'data' => $data,
        'topic' => $topic,
        'queueId' => null,
        'dispatch_time' => null,
      ];
    }
    if ($isCo) {
      return Server::getServer()->taskCo($topics, $timeout);
    } else {
      return Server::getServer()->taskWaitMulti($topics, $timeout);
    }
  }
}
