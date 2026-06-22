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

namespace Viswoole\Log\Drives;

use Override;
use Swoole\Server;
use Swoole\Timer;
use Viswoole\Core\Facade\Event;
use Viswoole\Core\Server\ServerEventHook;
use Viswoole\Log\Drive;
use Viswoole\Log\LogManager;

/**
 * 文件日志驱动，将日志写入本地文件系统
 *
 * 支持按级别分目录、按日期分目录、按文件大小切割、JSON 格式存储。
 * 通过 Swoole 定时器在每日凌晨自动清理过期日志。
 *
 * 注意：并发写入可能导致日志顺序不完全准确，对日志可靠性要求极高的场景
 * 建议使用第三方日志库或自行实现驱动。
 *
 * @see Drive 日志驱动抽象基类
 */
class File extends Drive
{
  /**
   * @param int $storageDays 日志保留天数，超过天数的日志目录将被自动清理
   * @param int $maxFiles 单个级别下最大日志文件数量，超出时删除最早的文件
   * @param int $fileSize 单个日志文件最大字节数，超出后自动切割新文件
   * @param string $dateFormat 时间戳格式化规则，传入 'timestamp' 保留原始时间戳
   * @param string $logFormat 日志文本格式规则，支持 %占位符 和 sprintf 两种模式
   * @param bool $json 是否以 JSON 格式存储日志
   * @param int $json_flags JSON 编码标志位
   * @param string $log_dir 日志文件根目录路径
   */
  public function __construct(
    protected int $storageDays = 7,
    protected int $maxFiles = 30,
    protected int $fileSize = 1024 * 1024 * 10,
    protected string $dateFormat = 'c',
    protected string $logFormat = '[%timestamp][%level]: %message %context in %source',
    protected bool $json = true,
    protected int $json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    protected string $log_dir = BASE_PATH . '/runtime/logs',
  )
  {
    $this->startDailyTimer();
    $this->clearExpireLog();
  }

  /**
   * 注册 Swoole 定时器，在每日凌晨触发过期日志清理
   *
   * 首次延迟到下一个午夜执行，之后每 24 小时重复执行。
   * 服务器关闭时自动清理定时器资源。
   */
  private function startDailyTimer(): void
  {
    ServerEventHook::addEvent('start', function (Server $server) {
      // 计算距离下一个午夜的秒数
      $now = time();
      $nextMidnight = strtotime('tomorrow');
      $secondsUntilMidnight = $nextMidnight - $now;
      $tickId = null;
      // 启动Swoole定时器，在距离午夜的秒数之后执行 deleteExpiredLogs 方法
      $afterId = Timer::after($secondsUntilMidnight * 1000, function () use (&$tickId) {
        $this->clearExpireLog();
        // 之后每隔一天（86400 秒）再次执行 deleteExpiredLogs 方法
        $tickId = Timer::tick(86400 * 1000, function () {
          $this->clearExpireLog();
        });
      });
      // 监听服务关闭之前的事件，清理定时器
      Event::on('ServerShutdownBefore', function () use (&$afterId, &$tickId, $server) {
        if (is_int($afterId)) Timer::clear($afterId);
        if (is_int($tickId)) Timer::clear($tickId);
      });
    });
  }

  /**
   * 清理超过保留天数的过期日志目录
   *
   * 仅删除日期目录名符合 Ymd 格式（8位数字）的目录，避免误删非日期命名的目录。
   *
   * @param int|null $days 保留天数，null 时使用配置的 storageDays
   * @param string|null $level 指定只清理的日志级别，null 时清理整个日期目录
   */
  public function clearExpireLog(?int $days = null, ?string $level = null): void
  {
    $days = is_null($days) ? $this->storageDays : $days;
    $rootDir = rtrim($this->log_dir, '/');
    // 匹配所有文件和目录
    $levelDirs = glob("$rootDir/*");
    // 当前日期
    $currentDate = (int)date('Ymd');
    foreach ($levelDirs as $dateDir) {
      $dirName = basename($dateDir);
      // 修复：验证目录名是否为 Ymd（8位数字）日期格式，避免误删非日期命名的目录
      if (!preg_match('/^\d{8}$/', $dirName)) continue;
      // 目录名则是日期
      $date = (int)$dirName;
      // 如果当前日期减去目录日期 大于最大存储的过期天数 则删除日志
      if ($currentDate - $date > $days) $this->rmdir($dateDir, $level);
    }
  }

  /**
   * 递归删除目录下的文件，目录为空时一并删除目录本身
   *
   * @param string $dir 待删除的目录路径
   * @param string|null $level 指定只删除某级别子目录，null 时删除整个目录
   */
  private function rmdir(string $dir, ?string $level = null): void
  {
    $dir = $level ? "$dir/$level" : $dir;
    if (is_dir($dir)) {
      // 列出指定路径内的文件和目录
      $resources = scandir($dir);
      foreach ($resources as $name) {
        if ($name != '.' && $name != '..') {
          // 如果是目录则继续递归，是文件则直接删除
          $subDir = $dir . '/' . $name;
          if (is_dir($subDir)) {
            $this->rmdir($subDir);
          } else {
            unlink($subDir);
          }
        }
      }
      // 如果目录为空，删除目录
      if (count(glob($dir . '/*')) === 0) rmdir($dir);
    }
  }

  /**
   * 批量将日志记录持久化到文件系统
   *
   * 协程结束时由 Recorder 析构自动调用。每条日志按级别和日期写入对应目录，
   * 文件超过 fileSize 时自动切割，超过 maxFiles 时删除最早文件。
   *
   * @param array<int,array{timestamp:int,level:string,message:string,context:array,source:string}> $logRecords 待写入的日志记录列表
   */
  #[Override] public function save(array $logRecords): void
  {
    $oldestLogFile = [];
    foreach ($logRecords as $logRecord) {
      $level = $logRecord['level'];
      // 格式化日期
      $logRecord['timestamp'] = date($this->dateFormat, $logRecord['timestamp']);
      // 如果以json格式存储则直接转为json字符串
      $logString = $this->json
        ? json_encode($logRecord, $this->json_flags)
        : LogManager::formatLogDataToString($this->logFormat, $logRecord);
      if (false === $logString && json_last_error() !== JSON_ERROR_NONE) {
        $msg = '无法序列化context：' . json_last_error_msg();
        trigger_error($msg, E_USER_WARNING);
        $logRecord['context'] = $msg;
        $logString = json_encode($logRecord, $this->json_flags | JSON_INVALID_UTF8_SUBSTITUTE);
      }
      // 修复问题#20：二次编码仍可能失败时使用安全的 fallback，避免写入空内容
      if ($logString === false) {
        $logString = '{"error":"log encoding failed"}';
      }
      $logDir = $this->getLogDir($level);
      // 获取日志文件夹下所有日志文件
      $logFiles = glob("$logDir/*.log");
      // 修复：查找已有日志文件的最大编号，避免使用 count 导致文件被删除后编号回退覆盖已有日志
      $maxIndex = -1;
      foreach ($logFiles as $logFile) {
        // 文件名格式: {level}_{number}.log
        if (preg_match('/_(\d+)\.log$/', basename($logFile), $matches)) {
          $index = (int)$matches[1];
          if ($index > $maxIndex) $maxIndex = $index;
        }
      }
      // 当前日志文件编号（取最大编号，若无文件则从0开始）
      $logFileCount = max($maxIndex, 0);
      // 当前日志文件名
      $currentLogFile = "$logDir/{$level}_{$logFileCount}.log";
      // 如果当前日志文件不存在或超过设定的文件大小，则创建新的日志文件 +1
      if ($maxIndex >= 0 && (!file_exists($currentLogFile) || filesize(
            $currentLogFile
          ) >= $this->fileSize)) {
        $logFileCount++;
        $currentLogFile = "$logDir/" . $level . '_' . $logFileCount . '.log';
      }
      // 输出日志到控制台
      LogManager::echoConsole($level, $logString);
      // 写入日志数据
      // 修复问题#9：file_put_contents 无并发保护，添加 LOCK_EX 标志防止并发写入交叉
      file_put_contents($currentLogFile, $logString . PHP_EOL, FILE_APPEND | LOCK_EX);
      // 如果当前日志文件数量超过设定的最大文件数量，则删除最早创建的一个
      // 修复问题#11：maxFiles 限制 off-by-one，文件编号从0开始，
      // 实际文件数量 = 编号 + 1，应使用实际文件数量与 maxFiles 比较
      if ($logFileCount + 1 > $this->maxFiles) {
        // 按文件创建时间排序
        usort($logFiles, function ($a, $b) {
          return filemtime($a) <=> filemtime($b);
        });
        // 如果存在日志文件，则删除最早创建的一个
        if (!empty($logFiles)) $oldestLogFile[] = array_shift($logFiles);
      }
    }
    // 遍历删除旧的日志文件
    foreach ($oldestLogFile as $file) unlink($file);
    clearstatcache();
  }

  /**
   * 根据日志级别和当前日期构建日志文件存储目录，不存在时自动创建
   *
   * 目录结构: {log_dir}/{yyyyMMdd}/{level}/
   *
   * @param string $level 日志级别名称
   * @return string 日志文件存储目录的绝对路径
   */
  protected function getLogDir(string $level): string
  {
    $date = date('Ymd');
    $logDir = rtrim($this->log_dir, '/');
    $logDir .= "/$date/$level";
    // 创建目录（如果不存在）
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    return $logDir;
  }
}
