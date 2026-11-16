<?php
/**
 * 职责：统一任务入口的主题名称约束；不查询注册状态或变更名称大小写。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use InvalidArgumentException;

/**
 * 主题名称必须是非空字符串，字符串零也是合法名称。
 */
final class TaskTopicName
{
  /**
   * 校验来自公共接口或投递信封的主题名称。
   * @param mixed $topic 待校验主题
   * @return string 已校验的原始名称
   * @throws InvalidArgumentException 主题不是非空字符串时抛出
   */
  public static function validate(mixed $topic): string
  {
    if (!is_string($topic) || $topic === '') throw new InvalidArgumentException('任务主题必须为非空字符串');
    return $topic;
  }
}
