<?php
/**
 * 职责：管理主题注册与有效期解析；不操作持久化任务状态。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Swoole\Server;
use Viswoole\Core\App;

/**
 * 任务主题注册与校验。
 */
trait TaskTopicRegistry
{
  /**
   * 校验任务主题是否已注册
   *
   * @param string $topic 任务主题名称
   * @return void
   * @throws InvalidArgumentException 主题为空或未注册时抛出
   */
  public function has(string $topic): void
  {
    TaskTopicName::validate($topic);
    $isRegistered = isset($this->topics[strtolower($topic)]);
    if (!$isRegistered) throw new InvalidArgumentException("没有找到任务主题：$topic");
  }

  /**
   * 注册任务主题及其处理器
   *
   * 支持两种注册方式：
   * - 传入 callable：直接注册为指定主题的处理器
   * - 传入类名：自动扫描该类的所有公开方法，以 "主题.方法名" 的形式批量注册
   *
   * 示例：
   *
   * ```
   * $taskManager->register('test', function (TaskProxy $task) {
   *   // 任务执行完毕调用finish
   *   $task->finish('success');
   * })
   * // 该类用于模拟发送短信
   * class Sms {
   *   // 静态方法 发送登录验证码
   *   public static function sendLoginCode(TaskProxy $task) {
   *      $phone = $task->data['phone'];
   *     // ...发送验证码业务逻辑
   *     // 发送验证码完成
   *     $task->finish('success');
   *   }
   *   // 动态方法 发送注册验证码
   *   public function sendRegisterCode(TaskProxy $task) {
   *      $phone = $task->data['phone'];
   *     // ...发送验证码业务逻辑
   *     // 发送验证码完成
   *     $task->finish('success');
   *   }
   * }
   * // 注册一个类，支持静态方法、动态方法
   * $taskManager->register('sms', Sms::class);//将会注册sms.sendLoginCode、sms.sendRegisterCode这两个主题
   * ```
   *
   * @param string $topic 任务主题名称，不区分大小写；传入类名时作为命名前缀
   * @param callable|string $handle 任务处理回调，或待扫描的类名
   * @return void
   * @throws InvalidArgumentException 主题为空、类名无效或反射失败时抛出
   */
  public function register(string $topic, callable|string $handle): void
  {
    TaskTopicName::validate($topic);
    if (is_callable($handle)) {
      $this->topics[strtolower($topic)] = $handle;
      return;
    }
    try {
      $methods = new ReflectionClass($handle)->getMethods(ReflectionMethod::IS_PUBLIC);
    } catch (ReflectionException $exception) {
      throw new InvalidArgumentException(
        "Invalid handle: $handle , " . $exception->getMessage(), previous: $exception
      );
    }
    foreach ($methods as $method) {
      $methodName = $method->getName();
      // 魔术方法不属于业务任务入口，避免构造与析构逻辑被意外注册。
      if (str_starts_with($methodName, '__')) continue;
      // 每次调用通过容器重新构造接收对象，避免任务间共享实例字段。
      $handler = $method->isStatic() ? "$handle::$methodName"
        : static function (TaskProxy $task, Server $server) use ($handle, $methodName): mixed {
          return App::factory()->invokeClass($handle)->{$methodName}($task, $server);
        };
      $this->topics[strtolower($topic . '.' . $methodName)] = $handler;
    }
  }

  /**
   * 解析指定主题的任务有效期（秒）
   *
   * 优先级：task.topics 中按主题配置 > task.expire 全局默认。
   * 主题名本身可包含点号（如 sms.sendLoginCode），无法使用点号分隔的
   * 配置路径查询，因此对 topics 配置做整体扫描匹配（不区分大小写）。
   * 0、负数等无效值统一归一化为 null（长期有效）。
   *
   * @param string $topic 任务主题名称
   * @return int|null 有效期秒数，null 表示长期有效
   */
  protected function resolveExpire(string $topic): ?int
  {
    $expire = null;
    $isMatched = false;
    $topics = $this->config->get('task.topics');
    if (is_array($topics)) {
      foreach ($topics as $key => $value) {
        // 主题名不区分大小写，与主题注册规则保持一致
        if (strcasecmp((string)$key, $topic) === 0) {
          $expire = $value;
          $isMatched = true;
          break;
        }
      }
    }
    // 未命中主题配置时回退到全局默认；命中且值为 null 表示该主题豁免（长期有效）
    if (!$isMatched) $expire = $this->config->get('task.expire');
    return is_numeric($expire) && (int)$expire > 0 ? (int)$expire : null;
  }

  /**
   * 检测任务是否已超过有效期
   *
   * @param array $taskData 任务数据（含 expire 与 dispatch_time）
   * @return bool 已过期返回 true；未配置有效期、未记录投递时间或未过期返回 false
   */
  protected function isExpired(array $taskData): bool
  {
    $expire = $taskData['expire'] ?? null;
    $dispatchTime = $taskData['dispatch_time'] ?? null;
    // 0、null 或负数均视为长期有效（与缓存过期时间约定一致）
    if (!is_numeric($expire) || (int)$expire <= 0) return false;
    if (!is_numeric($dispatchTime)) return false;
    return microtime(true) > ((float)$dispatchTime + (int)$expire);
  }
}
