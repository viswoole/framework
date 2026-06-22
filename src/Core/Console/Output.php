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

namespace Viswoole\Core\Console;

use Exception;

/**
 * 控制台输出工具，提供带颜色标签的格式化消息输出与变量打印能力
 *
 * 通过静态方法按日志级别输出彩色消息，支持自定义标签与颜色；
 * 也支持通过 __callStatic 以日志级别名作为方法名直接调用。
 *
 * @method static void success(string $message, int $backtrace = 1) 输出成功级别消息（绿色）
 * @method static void warning(string $message, int $backtrace = 1) 输出警告级别消息（黄色）
 * @method static void info(string $message, int $backtrace = 1) 输出信息级别消息（默认颜色）
 * @method static void error(string $message, int $backtrace = 1) 输出错误级别消息（红色）
 * @method static void emergency(string $message, int $backtrace = 1) 输出紧急级别消息（红色）
 * @method static void alert(string $message, int $backtrace = 1) 输出警戒级别消息（红色）
 * @method static void critical(string $message, int $backtrace = 1) 输出严重级别消息（红色）
 * @method static void notice(string $message, int $backtrace = 1) 输出通知级别消息（蓝色）
 * @method static void debug(string $message, int $backtrace = 1) 输出调试级别消息（灰色）
 */
class Output
{
  /**
   * ANSI 控制台颜色转义序列映射
   */
  public const array COLORS = [
    'DEFAULT' => "\033[0m",    // 默认颜色
    'RED' => "\033[0;31m",     // 红色
    'GREEN' => "\033[0;32m",   // 绿色
    'YELLOW' => "\033[0;33m",  // 黄色
    'BLUE' => "\033[0;34m",    // 蓝色
    'MAGENTA' => "\033[0;35m", // 洋红色
    'CYAN' => "\033[0;36m",    // 青色
    'GREY' => "\033[0;37m",    // 灰色
    'WHITE' => "\033[1;37m",   // 白色（加粗）
  ];
  /**
   * 日志级别到颜色转义序列的映射
   */
  public const array LABEL_COLOR = [
    'EMERGENCY' => self::COLORS['RED'],
    'ALERT' => self::COLORS['RED'],
    'CRITICAL' => self::COLORS['RED'],
    'DEBUG' => self::COLORS['GREY'],
    'ERROR' => self::COLORS['RED'],
    'WARNING' => self::COLORS['YELLOW'],
    'INFO' => self::COLORS['DEFAULT'],
    'SUCCESS' => self::COLORS['GREEN'],
    'NOTICE' => self::COLORS['BLUE'],
  ];

  /**
   * 输出 SYSTEM 级别的格式化消息
   *
   * @param string $message 输出的消息内容
   * @param string $color 颜色名称或 ANSI 转义序列，默认 'SUCCESS'
   * @param int $backtrace 调用栈回溯层级，0 不输出调用源，1 输出直接调用源
   */
  public static function system(
    string $message,
    string $color = 'SUCCESS',
    int    $backtrace = 0
  ): void
  {
    self::echo($message, 'SYSTEM', $color, $backtrace);
  }

  /**
   * 输出带时间戳和标签的格式化消息到控制台
   *
   * @param string|int $message 输出的消息内容
   * @param string $label 日志级别标签，默认 'INFO'
   * @param string|null $color 颜色名称或 ANSI 转义序列；为 null 时按标签自动映射
   * @param int $backtrace 调用栈回溯层级，0 不输出调用源，1 输出直接调用源
   */
  public static function echo(
    string|int $message,
    string     $label = 'INFO',
    ?string    $color = null,
    int        $backtrace = 1
  ): void
  {
    if ($backtrace !== 0) {
      $trace = ' - ' . self::getTrace($backtrace);
    } else {
      $trace = '';
    }
    $date = date('c');
    $label = strtoupper($label);
    if (array_key_exists($label, self::LABEL_COLOR) && !$color) {
      $color = self::LABEL_COLOR[$label];
    } elseif ($color) {
      $console_color_pattern = '/^(\033)\[[0-9;]+m$/';
      $isColor = preg_match($console_color_pattern, $color);
      if (!$isColor) {
        if (array_key_exists($color, self::LABEL_COLOR)) {
          $color = self::LABEL_COLOR[$color];
        } elseif (array_key_exists($color, self::COLORS)) {
          $color = self::COLORS[$color];
        } else {
          $color = self::COLORS['DEFAULT'];
        }
      }
    } else {
      $color = self::COLORS['DEFAULT'];
    }
    echo "{$color}[$date][$label]: $message$trace" . PHP_EOL . self::COLORS['DEFAULT'];
  }

  /**
   * 获取调用源的文件路径与行号
   *
   * @param int $backtrace 回溯层级，1 为直接调用源
   * @return string 格式为 "in /path/to/file:line"
   */
  private static function getTrace(int $backtrace): string
  {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $backtrace + 1);
    $caller = end($trace);
    $filename = $caller['file'];
    $line = $caller['line'];
    return 'in ' . $filename . ':' . $line;
  }

  /**
   * 格式化打印变量内容，带标题栏与调用源信息
   *
   * @param mixed $data 待打印的变量
   * @param string $title 标题栏文本
   * @param string $color 颜色名称或 ANSI 转义序列
   * @param int $backtrace 调用栈回溯层级，0 不输出调用源
   */
  public static function dump(
    mixed  $data,
    string $title = 'variable output',
    string $color = self::COLORS['GREEN'],
    int    $backtrace = 1
  ): void
  {
    if (array_key_exists($color, self::LABEL_COLOR)) {
      $color = self::LABEL_COLOR[$color];
    } else {
      $console_color_pattern = '/^(\033)\[[0-9;]+m$/';
      $isColor = preg_match($console_color_pattern, $color);
      $color = $isColor ? $color : self::COLORS['GREEN'];
    }
    $titleLength = strlen($title);
    $trace = $backtrace === 0 ? '' : self::getTrace($backtrace);
    $traceLength = strlen($trace);
    $minLength = 50;
    $rowLength = max($titleLength, $traceLength, $minLength);
    // 输出标题
    echo $color . str_pad($title, $rowLength, '-', STR_PAD_BOTH) . PHP_EOL;
    // 输出内容
    echo self::COLORS['DEFAULT'] . var_export($data, true) . PHP_EOL;
    // 输出结尾
    echo $color . str_pad(
        $trace, $rowLength, '-', STR_PAD_BOTH
      ) . PHP_EOL;
    if ($traceLength === $rowLength) {
      echo $color . str_repeat('-', $rowLength) . PHP_EOL . self::COLORS['DEFAULT'];
    } else {
      echo self::COLORS['DEFAULT'] . PHP_EOL;
    }
  }

  /**
   * 静态方法调用代理，支持以日志级别名作为方法名直接调用 echo
   *
   * @param string $name 日志级别名称
   * @param array $arguments [0=>消息内容, 1=>回溯层级]
   * @throws Exception 日志级别不存在时抛出
   */
  public static function __callStatic(string $name, array $arguments)
  {
    $name = strtoupper($name);
    if (array_key_exists($name, self::LABEL_COLOR)) {
      self::echo($arguments[0] ?? '', $name, backtrace: $arguments[1] ?? 2);
    } else {
      throw new Exception("Call to undefined method $name");
    }
  }
}
