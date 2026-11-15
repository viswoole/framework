<?php

declare(strict_types=1);

/* 职责：保存路由表候选对应的规范模板，并提供只读精确接口目录。 */

namespace Viswoole\Router;

use InvalidArgumentException;
use LogicException;

/** 元数据来自真实注册候选；不通过实际 URL 重新匹配接口。 */
final class InterfaceCatalog
{
  public const array HTTP_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH', 'CONNECT', 'TRACE'];
  /** @var array<string,array<string,array{template:string,domains:list<string>}>> */
  private array $candidates = [];
  private bool $isReady = false;
  /** @var array<string,array<string,RouteIdentity>> 不可变注册身份缓存 */
  private array $identities = [];

  /**
   * 记录实际路由表候选，重复槽位覆盖行为与路由表一致
   * @param string $slot 静态路径或动态正则槽位
   * @param array{template:string,domains:list<string>,methods:list<string>} $definition 注册元数据
   * @return void
   */
  public function register(string $slot, array $definition): void
  {
    unset($this->identities[$slot]);
    $methods = in_array('*', $definition['methods'], true) ? ['*'] : $definition['methods'];
    foreach ($methods as $method) {
      $this->candidates[$slot][$method] = ['template' => $definition['template'], 'domains' => $definition['domains']];
    }
  }

  /** @return void 路由注册结束后允许发布规则 */
  public function complete(): void { $this->isReady = true; }

  /**
   * 从已经选中的候选构建可信身份
   * @param string $slot 实际命中槽位
   * @param string $method 实际具体方法
   * @return RouteIdentity|null OPTIONS 预检不提供覆盖身份
   */
  public function selected(string $slot, string $method): ?RouteIdentity
  {
    if ($method === 'OPTIONS') { return null; }
    if (isset($this->identities[$slot][$method])) { return $this->identities[$slot][$method]; }
    $entry = $this->candidates[$slot][$method] ?? $this->candidates[$slot]['*'] ?? null;
    return $entry === null ? null
      : ($this->identities[$slot][$method] = new RouteIdentity($method, $entry['template'], $entry['domains']));
  }

  /**
   * 枚举可配置的精确接口
   * 路由允许的定义范围更宽；超出监控身份边界的候选不属于此目录。
   * @return list<RouteIdentity> 每个方法及别名单独列项
   * @throws LogicException 路由尚未初始化
   */
  public function getInterfaces(): array
  {
    if (!$this->isReady) { throw new LogicException('接口目录尚未初始化，请在 RouterInitialized 后操作'); }
    $identities = [];
    foreach ($this->candidates as $slot => $methods) {
      $names = isset($methods['*']) ? array_unique([...self::HTTP_METHODS, ...array_filter(array_keys($methods), static fn (string $method): bool => $method !== '*')]) : array_keys($methods);
      foreach ($names as $method) {
        try {
          $identity = $this->selected((string)$slot, $method);
        } catch (InvalidArgumentException) {
          // 仅排除身份验证失败的候选，不能让无关路由阻断合法接口的规则管理。
          continue;
        }
        if ($identity !== null) { $identities[] = $identity; }
      }
    }
    return $identities;
  }

  /**
   * 验证目录中存在唯一目标
   * @param RouteIdentity $identity 目标身份
   * @return void
   * @throws InvalidArgumentException 目标不存在或有歧义
   */
  public function assertRegistered(RouteIdentity $identity): void
  {
    $key = $identity->key();
    $count = count(array_filter($this->getInterfaces(), static fn (RouteIdentity $entry): bool => $entry->key() === $key));
    if ($count !== 1) { throw new InvalidArgumentException('覆盖目标接口不存在或身份有歧义'); }
  }
}
