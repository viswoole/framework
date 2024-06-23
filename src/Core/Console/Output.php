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

/**
 * 输出类，使用该类可轻松打印各种颜色的消息到控制台。
 *
 * @method static void success(string $message, int $backtrace = 1) 绿色
 * @method static void warning(string $message, int $backtrace = 1) 黄色
 * @method static void info(string $message, int $backtrace = 1) 默认颜色
 * @method static void error(string $message, int $backtrace = 1) 红色
 * @method static void notice(string $message, int $backtrace = 1) 蓝色
 * @method static void debug(string $message, int $backtrace = 1) 灰色
 */
class Output
{
  /**
   * Console Color 控制台颜色
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
   * 等级颜色映射
   */
  public const array LEVEL_COLOR = [
    'ERROR' => self::COLORS['RED'],
    'WARNING' => self::COLORS['YELLOW'],
    'INFO' => self::COLORS['DEFAULT'],
    'SUCCESS' => self::COLORS['GREEN'],
    'NOTICE' => self::COLORS['BLUE'],
    'DEBUG' => self::COLORS['GREY'],
  ];

  /**
   * 打印变量
   *
   * @access public
   * @param mixed $data 变量内容
   * @param string $title 标题
   * @param string $color 颜色
   * @param int $backtrace 1为输出调用源，0为不输出
   * @return void
   */
  public static function dump(
    mixed  $data,
    string $title = 'variable output',
    string $color = self::COLORS['GREEN'],
    int    $backtrace = 1
  ): void
  {
    if (array_key_exists($color, self::LEVEL_COLOR)) {
      $color = self::LEVEL_COLOR[$color];
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
   * 获取来源
   *
   * @param int $backtrace
   * @return string
   */
  private static function getTrace(int $backtrace): string
  {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $backtrace + 1);
    $caller = end($trace);
    $filename = $caller['file'];
    $line = $caller['line'];
    return 'in ' . $filename . ':' . $line;
  }

  public static function __callStatic(string $name, array $arguments)
  {
    $name = strtoupper($name);
    if (array_key_exists($name, self::LEVEL_COLOR)) {
      self::echo($arguments[0], $name, $arguments[1] ?? 1);
    }
  }

  /**
   * 输出一条文本信息
   *
   * @param string|int $message 要输出的内容
   * @param string $color 颜色，支持传入等级或转义颜色
   * @param int $backtrace 1为输出调用源，0为不输出
   * @return void
   */
  public static function echo(
    string|int $message,
    string     $color = self::COLORS['DEFAULT'],
    int        $backtrace = 1
  ): void
  {
    if ($backtrace !== 0) {
      $trace = ' - ' . self::getTrace($backtrace);
    } else {
      $trace = '';
    }
    $date = date('c');
    if (array_key_exists($color, self::LEVEL_COLOR)) {
      $color = self::LEVEL_COLOR[$color];
    } else {
      $console_color_pattern = '/^(\033)\[[0-9;]+m$/';
      $isColor = preg_match($console_color_pattern, $color);
      $color = $isColor ? $color : self::COLORS['DEFAULT'];
    }
    echo "{$color}[$date]: $message$trace" . PHP_EOL . self::COLORS['DEFAULT'];
  }
}
