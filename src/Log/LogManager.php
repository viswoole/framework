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
 * 日志管理器，负责通道注册、日志路由与格式化
 *
 * 管理多个日志通道，支持按日志级别路由到不同通道，并通过 __call 将日志调用
 * 转发至对应通道的驱动实例。协程环境下日志先缓存至 Recorder，协程结束时批量写入。
 *
 * @see DriveInterface 日志驱动接口
 * @see Recorder 协程日志记录器
 *
 * @method void mixed(string $level, string|Stringable $message, array $context = []) 记录具有任意级别的日志
 * @method void alert(string|Stringable $message, array $context = []) 记录必须立即采取行动的警报
 * @method void error(string|Stringable $message, array $context = []) 记录运行时错误（无需立即处理但需监控）
 * @method void warning(string|Stringable $message, array $context = []) 记录非错误的异常情况
 * @method void info(string|Stringable $message, array $context = []) 记录普通业务信息
 * @method void debug(string|Stringable $message, array $context = []) 记录详细调试信息
 * @method void sql(string|Stringable $message, array $context = []) 记录 SQL 执行日志
 * @method void task(string|Stringable $message, array $context = []) 记录异步任务日志
 * @method void write(string $level, Stringable|string $message, array $context = []) 绕过缓存直接写入日志
 * @method void save(array $logRecords) 批量保存日志（协程结束时自动调用，一般无需手动调用）
 * @method void clearRecord() 清除当前协程缓存的日志
 * @method array getRecord() 获取当前协程缓存的日志
 */
class LogManager
{
  /**
   * @var bool 是否将日志同步输出至控制台
   */
  private static bool $toTheConsole;
  /**
   * @var DriveInterface[] 已注册的日志通道，键名为小写通道名
   */
  private array $channels;
  /**
   * @var string 默认日志通道名称（小写）
   */
  private string $defaultChannel;
  /**
   * @var array<string,string|string[]> 日志级别到通道名的映射，用于按级别路由日志
   */
  private array $type_channel;
  /**
   * @var bool 是否在日志中记录调用来源（文件:行号）
   */
  private bool $recordLogTraceSource;

  /**
   * 根据日志配置初始化管理器，注册通道并校验级别路由
   *
   * @param Config $config 框架配置实例，读取 log 配置项
   * @throws LogException type_channel 中引用了不存在的通道
   */
  public function __construct(Config $config)
  {
    // 修复：options 可能为 null（未配置 log 项），增加空值保护
    $options = $config->get('log');
    $options = is_array($options) ? $options : [];
    $channels = $options['channels'] ?? ['default' => new File()];
    if (!empty($channels)) {
      // 修复：defaultChannel 统一转为小写，与 channel() 读取时的小写转换保持一致
      $this->defaultChannel = strtolower($options['default'] ?? array_key_first($channels));
      // 添加到通道列表
      foreach ($channels as $channelName => $channel) {
        $this->addChannel($channelName, $channel);
      }
    } else {
      // 修复：确保 defaultChannel 始终被初始化，避免后续访问未初始化属性
      $this->defaultChannel = 'default';
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
   * 注册一个日志通道
   *
   * 需在 Swoole 服务器启动前调用，工作进程内添加的通道不会同步到其他进程。
   *
   * @param string $name 通道名称（内部统一转小写存储）
   * @param DriveInterface|string|array{driver:string,options:array} $channel 驱动实例、类名或驱动配置数组
   * @throws LogException 通道类不存在、未实现接口或配置格式错误
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
   * 判断指定通道是否已注册
   *
   * @param string|array $channel 通道名称，传入数组时需全部存在才返回 true
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
   * 构建一条标准日志数据结构
   *
   * @param string $level 日志级别，如 error、info、debug 等
   * @param string|Stringable $message 日志消息内容
   * @param array $context 附加上下文，支持 _trace_source 键传递调用来源
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
   * 将日志数据按格式规则渲染为字符串
   *
   * 支持两种格式：占位符格式（如 %timestamp、%level）和 sprintf 格式。
   * 占位符格式按规则中出现的字段顺序替换；无占位符时回退到 vsprintf。
   *
   * @param string $formatRule 格式化规则，占位符格式示例: [%timestamp][%level]: %message %context in %source
   * @param array{timestamp:int,level:string,message:string,context:array,source:string} $logData 日志数据
   * @return string 格式化后的日志字符串
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
      // 修复问题#7：sprintf 展开关联数组会因键名非数字导致错误，
      // 改为 vsprintf + array_values 确保索引为连续数字，并对数组类型的值做序列化
      $logDataValues = array_map(function ($v) {
        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v;
      }, array_values($logData));
      $newStr = vsprintf($formatRule, $logDataValues);
    }
    return $newStr;
  }

  /**
   * 输出日志内容到控制台并附加颜色
   *
   * 传入 ANSI 颜色码或日志级别名称（自动映射预设颜色）。
   * 当全局开关 $toTheConsole 为 false 时不输出。
   *
   * @param string $color ANSI 颜色码或日志级别名称（如 error、warning）
   * @param string $content 通过 formatLogDataToString 生成的日志字符串
   */
  public static function echoConsole(string $color, string $content): void
  {
    if (!self::$toTheConsole) return;
    // 修复问题#12：原正则 '/^(\033)\[[0-9;]+m$/' 中 \033 在单引号中不被解析为 ESC 字符，
    // 改为双引号使用 \x1b 确保 ESC 字符被正确解析
    $console_color_pattern = "/^\x1b\[[0-9;]+m$/";
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
   * 将方法调用转发至对应通道的驱动实例
   *
   * 优先根据 type_channel 路由到指定通道，否则使用默认通道。
   * 对 write/record/mixed 级别的调用会自动注入调用来源信息。
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed 驱动方法的返回值
   * @throws BadMethodCallException 调用不存在的方法时抛出
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
        $result = null;
        foreach ($channels as $channel) {
          $result = call_user_func_array([$this->channel($channel), $name], $arguments);
        }
        return $result;
      } else {
        // 使用默认通道记录日志
        return call_user_func_array([$this->channel($this->defaultChannel), $name], $arguments);
      }
    } elseif (method_exists($this->channel($this->defaultChannel), $name)) {
      return call_user_func_array([$this->channel($this->defaultChannel), $name], $arguments);
    }
    throw new BadMethodCallException("log $name method not exists.");
  }

  /**
   * 在上下文中注入日志调用来源（文件:行号）
   *
   * 通过 debug_backtrace 回溯到 Facade 或 LogManager 的调用位置，
   * 仅在 recordLogTraceSource 开启时记录。
   *
   * @param array $context 原始上下文数据
   * @return array 注入 _trace_source 后的上下文
   */
  private function buildTraceSource(array $context = []): array
  {
    if ($this->recordLogTraceSource) {
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
      // 修复：初始化为 null，避免 foreach 查找失败时 $backtrace 仍为完整数组导致后续访问数组下标类型错误
      $traceFrame = null;
      foreach (array_reverse($backtrace) as $trace) {
        if (isset($trace['class']) && ($trace['class'] === Facade::class || $trace['class'] === self::class)) {
          $traceFrame = $trace;
          break;
        }
      }
      // 修复：增加类型检查，查找失败（$traceFrame 为 null）时使用默认空值
      $file = is_array($traceFrame) ? ($traceFrame['file'] ?? '') : '';
      $line = is_array($traceFrame) ? ($traceFrame['line'] ?? '') : '';
      // 修复：运算符优先级错误。原代码 ($backtrace['line']) ?? '' 的 ?? 作用于整个拼接表达式，
      // 导致 $backtrace['line'] 不存在时触发未定义索引警告。拆分为独立变量并各自 ?? 兜底。
      $trace = $file . ':' . $line;
      $context['_trace_source'] = $trace;
    } else {
      $context['_trace_source'] = 'not record';
    }
    return $context;
  }

  /**
   * 获取指定通道的驱动实例
   *
   * @param string|null $channel 通道名称，null 时使用默认通道
   * @return DriveInterface 通道驱动实例
   * @throws InvalidArgumentException 通道不存在时抛出
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
