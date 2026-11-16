<?php
/**
 * 职责：提供处理器数据访问、Swoole 结果回传和主动续租；不提前确认队列任务。
 */
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

declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Exception;
use Swoole\Server\Task as SwooleTask;

/**
 * 任务代理对象，封装 Swoole Task 提供任务数据访问与生命周期控制
 *
 * 作为任务处理器接收的参数，提供对原始任务数据的只读访问，
 * 并通过代理方式暴露 SwooleTask 的属性与方法。
 *
 * @property float $dispatch_time 任务投递时间戳
 * @property int $id 任务ID
 * @property int $worker_id 任务所在的 Worker 进程ID
 * @property int $flags 任务标志位，默认为 SW_TASK_NONBLOCK
 * @method bool finish(mixed $data) 向 Worker 进程发送处理结果；遵循 Swoole 语义，可在任务处理器中多次调用
 */
class TaskProxy
{
  /**
   * @var mixed 任务处理器接收的业务数据
   */
  public readonly mixed $data;
  /**
   * @var string|null 队列唯一标识，非队列任务为 null；处理器正常结束并 ACK 后才从队列移除
   */
  public readonly string|null $queue_id;
  /**
   * @var string 任务主题名称
   */
  public readonly string $topic;

  /**
   * 初始化任务代理并缓存任务数据访问入口
   * @param SwooleTask $swooleTask Swoole 原始任务对象
   * @param \Closure|null $renew 当前执行者的主动续租入口
   */
  public function __construct(
    /**
     * @var SwooleTask 当前投递的原始任务对象
     */
    private readonly SwooleTask $swooleTask,
    /**
     * @var \Closure|null 当前任务的租约续期入口
     */
    private readonly ?\Closure $renew = null
  ) {
    // 修复: 使用 null 合并运算符，避免数据访问无验证导致未定义键警告
    $this->data = $this->swooleTask->data['data'] ?? null;
    $this->queue_id = $this->swooleTask->data['queueId'] ?? null;
    $this->topic = $this->swooleTask->data['topic'] ?? null;
    if (isset($this->swooleTask->data['dispatch_time'])) {
      $this->swooleTask->dispatch_time = $this->swooleTask->data['dispatch_time'];
    }
  }

  /**
   * 主动延长当前执行租约；失去执行权时返回 false。
   * @return bool 是否续期成功，非持久化任务返回 false
   * @throws \Throwable 续租存储操作失败时抛出
   */
  public function renewLease(): bool
  {
    return $this->renew !== null && ($this->renew)();
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
  public function __get(string $name): mixed
  {
    if (property_exists($this->swooleTask, $name)) {
      return $this->swooleTask->{$name};
    } else {
      throw new Exception('Undefined property: ' . __CLASS__ . '::' . $name);
    }
  }

  /**
   * 代理调用 SwooleTask 的方法（如 finish），直接透传不做任何状态限制
   *
   * Swoole 官方语义允许在任务处理器中多次调用 finish，
   * 向 Worker 进程发送多个处理结果，因此此处不拦截重复调用。
   *
   * @param string $name 方法名称
   * @param array $arguments 参数列表
   * @return mixed 方法返回值
   * @throws Exception 方法不存在时抛出
   */
  public function __call(string $name, array $arguments): mixed
  {
    if (method_exists($this->swooleTask, $name)) {
      return $this->swooleTask->{$name}(...$arguments);
    } else {
      throw new Exception('Undefined method: ' . __CLASS__ . '::' . $name . '()');
    }
  }
}
