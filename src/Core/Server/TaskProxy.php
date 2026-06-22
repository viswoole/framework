<?php
/*
 *  +----------------------------------------------------------------------
 *  | visual-swoole [Visual and efficient development]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2023
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Core\Server;

use Closure;
use Exception;
use Swoole\Server\Task as SwooleTask;

/**
 * 任务代理对象，封装 Swoole Task 提供任务数据访问与生命周期控制
 *
 * 作为任务处理器接收的参数，提供对原始任务数据的只读访问，
 * 并通过代理方式暴露 SwooleTask 的属性，同时管理队列任务的完成回调。
 *
 * @property float $dispatch_time 任务投递时间戳
 * @property int $id 任务ID
 * @property int $worker_id 任务所在的 Worker 进程ID
 * @property int $flags 任务标志位，默认为 SW_TASK_NONBLOCK
 */
class TaskProxy
{
  /**
   * @var mixed 任务处理器接收的业务数据
   */
  public readonly mixed $data;
  /**
   * @var string|null 队列唯一标识，非队列任务为 null；任务完成后自动从缓存中清除
   */
  public readonly string|null $queue_id;
  /**
   * @var string 任务主题名称
   */
  public readonly string $topic;
  /**
   * @var bool 任务是否已调用 finish 标记完成
   */
  public bool $is_finish = false;

  /**
   * @param SwooleTask $swooleTask Swoole 原始任务对象
   * @param Closure $finish_callback 队列任务完成后的清理回调，接收 (queueId, workerId)
   */
  public function __construct(
    private readonly SwooleTask $swooleTask,
    private readonly Closure    $finish_callback
  )
  {
    // 修复: 使用 null 合并运算符，避免数据访问无验证导致未定义键警告
    $this->data = $this->swooleTask->data['data'] ?? null;
    $this->queue_id = $this->swooleTask->data['queueId'] ?? null;
    $this->topic = $this->swooleTask->data['topic'] ?? null;
    if (isset($this->swooleTask->data['dispatch_time'])) {
      $this->swooleTask->dispatch_time = $this->swooleTask->data['dispatch_time'];
    }
  }

  /**
   * 序列化任务数据为二进制字符串，用于跨进程传输
   *
   * @param mixed $data 待序列化的任务数据
   * @return string|false 序列化成功返回二进制字符串，失败返回 false
   */
  public static function pack(mixed $data): string|false
  {
    return SwooleTask::pack($data);
  }

  /**
   * 反序列化二进制字符串为任务数据
   *
   * @param string $data 已序列化的二进制任务数据
   * @return mixed 反序列化成功返回原始数据，失败返回 false
   */
  public static function unpack(string $data): mixed
  {
    return SwooleTask::unpack($data);
  }

  /**
   * 代理访问 SwooleTask 的属性，如 dispatch_time、id、worker_id 等
   *
   * @param string $name 属性名称
   * @return mixed 属性值
   * @throws Exception 属性不存在时抛出
   */
  public function __get(string $name)
  {
    if (property_exists($this->swooleTask, $name)) {
      return $this->swooleTask->{$name};
    } else {
      throw new Exception('Undefined property: ' . __CLASS__ . '::' . $name);
    }
  }

  /**
   * 标记任务完成，向 Worker 进程返回结果并清理队列缓存
   *
   * @param mixed $data 返回给 Worker 进程的任务结果数据
   * @return bool 完成成功返回 true，重复调用返回 false
   */
  public function finish(mixed $data): bool
  {
    if (!$this->is_finish) {
      $this->is_finish = true;
      if ($this->queue_id) {
        call_user_func_array($this->finish_callback, [$this->queue_id, (string)$this->worker_id]);
      }
      return $this->swooleTask->finish($data);
    }
    return false;
  }
}
