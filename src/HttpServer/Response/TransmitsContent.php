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

declare(strict_types=1);

namespace Viswoole\HttpServer\Response;

use InvalidArgumentException;
use Override;
use RuntimeException;
use Viswoole\Core\Console\Output;
use Viswoole\Monitor\TraceContext;
use Viswoole\Monitor\Http\UriSanitizer;

/**
 * 响应传输边界
 *
 * 职责：承载 Response 的内容发送方法（end/send/write/redirect/sendfile），
 * 并在成功输出后向慢请求监控记录正文与完成状态。
 * 边界：不负责内容构建（由 Response 主体承担）；仅成功输出才记录，
 * 失败的发送不标记响应完成；detach() 后的发送不在此边界内。
 */
trait TransmitsContent
{
  /**
   * 发送响应（end 方法的别名）
   *
   * @param string|null $content 响应内容，为 null 时使用已设置的 content
   * @return bool 发送成功返回 true
   * @see end
   */
  public function send(?string $content = null): bool
  {
    return $this->end($content);
  }

  /**
   * 结束响应并发送内容
   *
   * 若已开启 echoToConsole，会同时将响应内容和耗时输出到控制台。
   * 响应对象不可写时直接返回 false。
   *
   * @param string|null $content 响应内容，为 null 时使用已设置的 content
   * @return bool 发送成功返回 true，响应已结束或不可写返回 false
   */
  #[Override]
  public function end(?string $content = null): bool
  {
    if ($this->isWritable()) {
      if (is_null($content)) $content = $this->content;
      if ($this->echoToConsole) {
        // 请求时间
        $request_time = \Viswoole\HttpServer\Facade\Request::getServer('request_time_float');
        // 获取当前时间
        $current_time_float = microtime(true);
        // 计算耗时
        $elapsed_time = $current_time_float - $request_time;
        // 输出到控制台
        Output::dump($content, "响应内容:耗时{$elapsed_time}秒");
      }
      $result = $this->swooleResponse->end($content);
      // 仅成功发送才记录正文并标记完成，失败的发送不能伪装为已响应
      if ($result) TraceContext::current()?->appendResponse($content, true);
      return $result;
    } else {
      return false;
    }
  }

  /**
   * 发送 HTTP 重定向响应
   *
   * @param string $uri 目标 URI
   * @param int $http_code 重定向状态码，默认 302
   * @return bool 重定向成功返回 true
   */
  #[Override]
  public function redirect(string $uri, int $http_code = 302): bool
  {
    $result = $this->swooleResponse->redirect($uri, $http_code);
    if ($result) {
      TraceContext::current()?->updateResponse(['status' => $http_code, 'redirect' => UriSanitizer::sanitize($uri)]);
      TraceContext::current()?->completeResponse();
    }
    return $result;
  }

  /**
   * 分段写入响应数据（HTTP Chunk 模式）
   *
   * @param string $data 数据内容，最大长度受 buffer_output_size 配置控制
   * @return self 返回当前实例，支持链式调用
   * @throws RuntimeException 写入失败（连接上下文已不存在）时抛出
   */
  #[Override]
  public function write(string $data): self
  {
    $result = $this->swooleResponse->write($data);
    if (!$result) throw new RuntimeException('分段写入数据失败，连接上下文已不存在。');
    // 分段内容先累积，等完整拼接后再统一脱敏（见 RequestTrace::appendResponse）
    TraceContext::current()?->appendResponse($data);
    return $this;
  }

  /**
   * 发送本地文件作为响应体
   *
   * 未指定 MIME 类型时通过 finfo 自动检测，检测失败回退为 application/octet-stream。
   *
   * @param string $filePath 文件绝对路径
   * @param int $offset 发送起始偏移量
   * @param int $length 发送字节数，0 表示全部
   * @param string|null $fileMimeType 强制指定的 MIME 类型
   * @return bool 发送成功返回 true
   * @throws InvalidArgumentException 文件不存在时抛出
   */
  #[Override]
  public function sendfile(
    string  $filePath,
    int     $offset = 0,
    int     $length = 0,
    ?string $fileMimeType = null
  ): bool
  {
    if (!file_exists($filePath)) {
      throw new InvalidArgumentException("没有找到要发送的文件：{$filePath}，请检查路径是否正确。");
    }
    $this->header('Content-Type', $fileMimeType ?: $this->detectFileMimeType($filePath));
    $result = $this->swooleResponse->sendfile($filePath, $offset, $length);
    // 文件内容不采集（由 Swoole 直接传输），仅记录文件信息
    if ($result) {
      TraceContext::current()?->updateResponse(['file' => ['name' => basename($filePath), 'offset' => $offset, 'length' => $length]]);
      TraceContext::current()?->completeFileResponse();
    }
    return $result;
  }

  /**
   * 检测本地文件的 MIME 类型
   *
   * @param string $filePath 文件路径
   * @return string 检测到的 MIME 类型，检测失败回退为 application/octet-stream
   */
  private function detectFileMimeType(string $filePath): string
  {
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) return 'application/octet-stream';
    return finfo_file($fileInfo, $filePath) ?: 'application/octet-stream';
  }
}
