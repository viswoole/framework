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

namespace Viswoole\Log;

use BadMethodCallException;
use InvalidArgumentException;
use Stringable;
use Viswoole\Core\Config;
use Viswoole\Core\Facade;
use Viswoole\Log\Contract\DriveInterface;
use Viswoole\Log\Drives\File;
use Viswoole\Log\Exception\LogException;

/**
 * 日志管理器
 *
 * @method void mixed(string $level, string|Stringable $message, array $context = []) 记录具有任意级别的日志。
 * @method void alert(string|Stringable $message, array $context = []) 必须立即采取行动。
 * @method void error(string|Stringable $message, array $context = []) 不需要立即采取行动的运行时错误，但通常应记录和监视。
 * @method void warning(string|Stringable $message, array $context = []) 不是错误的异常情况。
 * @method void info(string|Stringable $message, array $context = []) 普通日志信息。
 * @method void debug(string|Stringable $message, array $context = []) 详细的调试信息。
 * @method void sql(string|Stringable $message, array $context = []) SQL日志。
 * @method void task(string|Stringable $message, array $context = []) 任务日志。
 * @method void write(Stringable|string $message, array $context = [], string $level = 'info') 直接写入日志
 * @method bool save(array $logRecords) 保存日志（一般无需手动调用, 协程结束会自动调用）
 * @method bool clearRecord() 清除缓存日志
 * @method array getRecord() 获取缓存日志
 */
class LogManager
{
  /**
   * @var bool 是否输出至控制台
   */
  private static bool $toTheConsole;
  /**
   * @var DriveInterface[] 通道列表
   */
  private array $channels;
  /**
   * @var string 默认通道
   */
  private string $defaultChannel;
  /**
   * @var array<string,string> 日志类型指定通道
   */
  private array $type_channel;
  /**
   * @var bool 是否记录日志来源
   */
  private bool $recordLogTraceSource;

  /**
   * @throws LogException
   */
  public function __construct(Config $config)
  {
    $options = $config->get('log');
    $channels = $options['channels'] ?? ['default' => new File()];
    if (!empty($channels)) {
      $this->defaultChannel = $options['default'] ?? array_key_first($channels);
      // 添加到通道列表
      foreach ($channels as $channelName => $channel) {
        $this->addChannel($channelName, $channel);
      }
    }
    $this->type_channel = $options['type_channel'] ?? [];
    $this->recordLogTraceSource = $options['trace_source'] ?? false;
    self::$toTheConsole = $options['console'] ?? true;
    foreach ($this->type_channel as $channel) {
      if (!$this->hasChannel($channel)) {
        throw new LogException('type channel ' . $channel . ' not exists');
      }
    }
  }

  /**
   * 添加一个日志通道
   *
   * 注意：该方法需在swoole服务器启动之前调用，在工作进程添加的通道不会同步到其他进程。
   *
   * @param string $name 通道名称
   * @param DriveInterface|string|array{driver:string,options:array} $channel 驱动类
   * @return void
   * @throws LogException 配置错误
   */
  public function addChannel(string $name, DriveInterface|string|array $channel): void
  {
    if (is_string($channel)) {
      if (!class_exists($channel)) {
        throw new LogException("{$name}日志通道配置错误，{$channel}不是一个有效的类", -1);
      }
      $channel = invoke($channel);
    } elseif (is_array($channel)) {
      if (!is_string($channel['driver']) || !class_exists($channel['driver'])) {
        throw new LogException("{$name}日志通道配置错误，通道类不存在", -1);
      }
      $options = $channel['options'] ?? [];
      if (!is_array($options)) {
        throw new LogException($name . '日志通道配置错误，options需为数组', -1);
      }
      $channel = invoke($channel['driver'], $options);
    }
    if (!$channel instanceof DriveInterface) {
      throw new LogException(
        $name . '日志通道配置错误，通道类必须实现' . DriveInterface::class . '接口或继承' . Drive::class,
        -1
      );
    }
    $this->channels[strtolower($name)] = $channel;
  }

  /**
   * 判断通道是否存在
   *
   * @access public
   * @param string|array $channel 通过通道名称，判断是否存在
   * @return bool
   */
  public function hasChannel(string|array $channel): bool
  {
    if (is_array($channel)) {
      foreach ($channel as $item) {
        if (!isset($this->channels[strtolower($item)])) return false;
      }
      return true;
    }
    return isset($this->channels[strtolower($channel)]);
  }

  /**
   * 创建日志数据
   *
   * @param string $level
   * @param string|Stringable $message
   * @param array $context
   * @return array<int,array{timestamp:int,level:string,message:string,context:array,source:string}>
   */
  public static function createLogData(
    string            $level,
    string|Stringable $message,
    array             $context = []
  ): array
  {
    $source = $context['_trace_source'] ?? '';
    unset($context['_trace_source']);
    return [
      'timestamp' => time(),
      'level' => $level,
      'message' => (string)$message,
      'context' => $context,
      'source' => $source
    ];
  }

  /**
   * 格式化日志数据为字符串
   *
   * @access public
   * @param array{
   *    timestamp: int,
   *    level: string,
   *    message: string,
   *    context: array,
   *    source: string,
   * } $logData 需要写入日志的记录
   * @param string $formatRule 格式化规则，示例:[%timestamp][%level] %message : %context -in %source
   * @return string
   */
  public static function formatLogDataToString(string $formatRule, array $logData): string
  {
    // 通过正则表达式匹配格式化规则中的占位符
    preg_match_all('/%(\w+)/', $formatRule, $matches);
    if (!empty($matches[1])) {
      // 获取匹配到的占位符
      $placeholders = $matches[1];
      // 重新排序 $logData 数组的键
      $sortedData = [];
      foreach ($placeholders as $placeholder) {
        if (array_key_exists($placeholder, $logData)) {
          $sortedData[$placeholder] = $logData[$placeholder];
          unset($logData[$placeholder]);
        }
      }
      // 根据格式化规则生成新的字符串
      $newStr = $formatRule;
      // 如果上下文为空则使用{}代替
      empty($sortedData['context']) && $sortedData['context'] = '{}';
      foreach ($sortedData as $key => $value) {
        $value = is_string($value)
          ? $value
          : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '无法序列化context：' . json_last_error_msg());
        $newStr = str_replace("%$key", (string)$value, $newStr);
      }
    } else {
      $newStr = sprintf($formatRule, ...$logData);
    }
    return $newStr;
  }

  /**
   * 输出日志到控制台
   *
   * @access public
   * @param string $color 输出的颜色，传入内置日志等级会有预设颜色
   * @param string $content 日志内容,通过Manager::formatLogDataToString方法生成
   * @return void
   */
  public static function echoConsole(string $color, string $content): void
  {
    if (!self::$toTheConsole) return;
    $console_color_pattern = '/^(\033)\[[0-9;]+m$/';
    $isColor = preg_match($console_color_pattern, $color);
    if (!$isColor) {
      $color = match ($color) {
        'emergency', 'alert', 'critical' => "\033[1;31m",
        'debug' => "\033[0;37m",
        'error' => "\033[0;31m",
        'warning', 'task' => "\033[0;33m",
        'notice' => "\033[0;34m",
        'sql' => "\033[0;32m",
        default => "\033[0m"
      };
    }
    echo "$color$content\033[0m\n";
  }

  /**
   * 将调用的方法转发至日志驱动
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public function __call(string $name, array $arguments)
  {
    if (
      method_exists(Collector::class, $name)
      || in_array($name, ['write', 'record'])
    ) {
      if (in_array($name, ['write', 'record', 'mixed'])) {
        $level = $arguments[0];
        $arguments[2] = $this->buildTraceSource($arguments[2] ?? []);
      } else {
        $level = $name;
        $arguments[1] = $this->buildTraceSource($arguments[1] ?? []);
      }
      if (isset($this->type_channel[$level])) {
        $channels = is_string($this->type_channel[$level])
          ? [$this->type_channel[$level]]
          : $this->type_channel[$level];
        // 兼容多通道记录日志
        foreach ($channels as $channel) {
          call_user_func_array([$this->channel($channel), $name], $arguments);
        }
      } else {
        // 使用默认通道记录日志
        return call_user_func_array([$this->channel($this->defaultChannel), $name], $arguments);
      }
    } elseif (method_exists($this->channel($this->defaultChannel), $name)) {
      call_user_func_array([$this->channel($this->defaultChannel), $name], $arguments);
    }
    throw new BadMethodCallException("log $name method not exists.");
  }

  /**
   * 在上下文中加入日志来源
   *
   * @param array $context 上下文
   * @return array
   */
  private function buildTraceSource(array $context = []): array
  {
    if ($this->recordLogTraceSource) {
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
      foreach (array_reverse($backtrace) as $trace) {
        if (isset($trace['class']) && ($trace['class'] === Facade::class || $trace['class'] === self::class)) {
          $backtrace = $trace;
          break;
        }
      }
      $trace = ($backtrace['file'] ?? '') . ':' . ($backtrace['line']) ?? '';
      $context['_trace_source'] = $trace;
    } else {
      $context['_trace_source'] = 'not record';
    }
    return $context;
  }

  /**
   * 设置日志通道
   *
   * @param string|null $channel 设置记录日志的通道
   * @return DriveInterface
   * @throws InvalidArgumentException 通道不存在
   */
  public function channel(?string $channel): DriveInterface
  {
    $channel = $channel ?? $this->defaultChannel;
    if (!$this->hasChannel($channel)) {
      throw new InvalidArgumentException('log channel ' . $channel . ' not exists');
    }
    return $this->channels[strtolower($channel)];
  }
}
