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
 * 任务代理
 *
 * @property float $dispatch_time 任务派发时间
 * @property int $id 任务id
 * @property int $worker_id 任务所在的worker进程id
 * @property int $flags 任务的flags，默认为SW_TASK_NONBLOCK
 */
class TaskProxy
{
  /**
   * @var mixed 任务接收到数据
   */
  public readonly mixed $data;
  /**
   * @var string|mixed 缓存队列id，在任务执行完成时会自动删除。主要用途是服务重启时自动恢复未执行完的任务
   */
  public readonly string|null $queue_id;
  /**
   * @var string 主题
   */
  public readonly string $topic;
  /**
   * @var bool 是否已经完成
   */
  public bool $is_finish = false;

  /**
   * @param SwooleTask $swooleTask swoole task
   */
  public function __construct(
    private readonly SwooleTask $swooleTask,
    private readonly Closure    $finish_callback
  )
  {
    $this->data = $this->swooleTask->data['data'];
    $this->queue_id = $this->swooleTask->data['queueId'];
    $this->topic = $this->swooleTask->data['topic'];
    if (isset($this->swooleTask->data['dispatch_time'])) {
      $this->swooleTask->dispatch_time = $this->swooleTask->data['dispatch_time'];
    }
  }

  /**
   * 序列化任务数据
   *
   * @access public
   * @param mixed $data Task data to be packed.
   * @return string|false The packed task data. Returns false if failed.
   */
  public static function pack(mixed $data): string|false
  {
    return SwooleTask::pack($data);
  }

  /**
   * 反序列化任务数据
   *
   * @param string $data The packed task data.
   * @return mixed The unpacked data. Returns false if failed.
   * @since 5.0.1
   */
  public static function unpack(string $data): mixed
  {
    return SwooleTask::unpack($data);
  }

  /**
   * @param string $name
   * @return mixed
   * @throws Exception
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
   * 完成任务
   *
   * @param mixed $data 要传递给worker进程的任务结果.
   * @return bool
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
