<?php

declare(strict_types=1);

/* 职责：请求元信息及分来源参数；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

/**
 * 请求元信息及分来源参数 DTO
 *
 * method/path 始终是字符串；集合和正文数据与采集状态分离。
 * 合成或不完整的采集上下文使用空字符串表示未知，不伪造 GET 或路径。
 */
final readonly class RequestSnapshot extends SnapshotRecord
{
  public string $method;
  public string $path;
  public string $host;
  public string $ip;
  public HeaderSnapshot $headers;
  public CollectionSnapshot $query;
  public CollectionSnapshot $post;
  public BodySnapshot $body;
  public CollectionSnapshot $files;
  public ?RouteSnapshot $route;
  public CaptureState $routeState;

  /**
   * 构建定稿记录
   *
   * @param array<string,mixed> $data 已脱敏的记录字段
   */
  public function __construct(array $data)
  {
    parent::__construct($data);
    $safe = $this->toArray();
    $this->method = is_string($safe['method'] ?? null) ? $safe['method'] : '';
    $this->path = is_string($safe['path'] ?? null) ? $safe['path'] : '';
    $this->host = is_string($safe['host'] ?? null) ? $safe['host'] : '';
    $this->ip = is_string($safe['ip'] ?? null) ? $safe['ip'] : '';
    $this->headers = new HeaderSnapshot($safe['headers'] ?? null);
    $this->query = new CollectionSnapshot($safe['query'] ?? null);
    $this->post = new CollectionSnapshot($safe['post'] ?? null);
    $this->body = new BodySnapshot($safe['body'] ?? null);
    $this->files = new CollectionSnapshot($safe['files'] ?? null);
    $this->routeState = CaptureState::resolve($safe['route'] ?? null);
    $this->route = is_array($safe['route'] ?? null) ? new RouteSnapshot($safe['route']) : null;
  }
}
