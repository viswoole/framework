<?php

declare(strict_types=1);

/* 职责：验证请求 ID 配置并调用使用方提供的 PHP callable，不承诺随机 ID 绝对唯一。 */

namespace Viswoole\Monitor;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * 请求 ID 配置与生成入口
 *
 * 默认生成 128 位随机 ID，碰撞概率非零；严格唯一由自定义生成器负责。
 * 自定义生成失败时向监控边界抛出，不以随机 ID 冒充其唯一性契约。
 */
final readonly class RequestIdOptions
{
  private ?Closure $generator;
  public ?string $responseHeader;

  /**
   * 验证生成器及响应头配置
   *
   * @param mixed $options request_id 配置数组
   * @throws InvalidArgumentException 生成器不是 callable 或响应头名称非法
   */
  public function __construct(mixed $options = [])
  {
    if (!is_array($options)) { throw new InvalidArgumentException('monitor.request_id 必须是配置数组'); }
    $generator = $options['generator'] ?? null;
    if ($generator !== null && !is_callable($generator)) {
      throw new InvalidArgumentException('monitor.request_id.generator 必须是 PHP callable 或 null');
    }
    $this->generator = $generator === null ? null : Closure::fromCallable($generator);
    $header = $options['response_header'] ?? null;
    if ($header !== null && (!is_string($header) || ($header !== '' && !preg_match('/\A[!#$%&\x27*+.^_`|~0-9A-Za-z-]+\z/D', $header)))) {
      throw new InvalidArgumentException('monitor.request_id.response_header 必须是合法标头名、空串或 null');
    }
    $this->responseHeader = $header === '' ? null : $header;
  }

  /**
   * 生成本次请求标识
   *
   * 支持无参 callable 返回字符串或整数，整数转为字符串以兼容雪花发号器。
   * ID 限制为最多 256 字节可见 ASCII，防止标头注入和无界日志字段。
   *
   * @return string 本次请求的关联标识，不对自定义生成器做去重
   * @throws UnexpectedValueException 返回类型、长度或字符非法
   * @throws \Throwable 自定义生成器或系统随机源执行失败
   */
  public function generate(): string
  {
    $value = $this->generator === null ? bin2hex(random_bytes(16)) : ($this->generator)();
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[\x21-\x7E]{1,256}\z/D', (string)$value)) {
      throw new UnexpectedValueException('请求 ID 生成器必须返回 1~256 字节可见 ASCII 字符串或整数');
    }
    return (string)$value;
  }
}
