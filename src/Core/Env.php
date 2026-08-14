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

namespace Viswoole\Core;

use ArrayAccess;
use Exception;
use Override;

/**
 * 环境变量管理器
 *
 * 加载 .env 文件并提供对环境变量的统一读写访问，
 * 自动将字符串 'true'/'false'/'on'/'off' 转换为布尔值，
 * 优先读取 .env 文件定义，回退到系统环境变量（getenv）。
 */
class Env implements ArrayAccess
{
  /**
   * @var array 已加载的环境变量数据，键名统一大写
   */
  protected array $data = [];
  /**
   * @var array 字符串到布尔值的自动转换映射
   */
  protected array $convert = [
    'true' => true,
    'false' => false,
    'off' => false,
    'on' => true,
  ];

  /**
   * 初始化时加载 $_ENV 和 .env 文件
   */
  public function __construct()
  {
    $this->data = $_ENV;
    $this->load(getEnvPath());
  }

  /**
   * 解析 .env 文件（逐行 KEY=VALUE 格式）并合并到环境变量数据中
   *
   * 不使用 parse_ini_file：.env 不是严格 INI 格式，注释中的特殊字符
   * （~、(、; 等）会触发 parse_ini_file 语法错误并返回 false，导致整份
   * .env 静默失效。此处逐行解析，仅将 # 开头行视为注释，值不做 INI 校验。
   *
   * @param string $file .env 文件路径
   */
  protected function load(string $file): void
  {
    $env = [];
    if (is_file($file)) {
      foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $parsed = $this->parseLine($line);
        if ($parsed !== null) {
          $env[$parsed[0]] = $parsed[1];
        }
      }
    }
    $this->set($env);
  }

  /**
   * 解析单行 .env 配置
   *
   * 支持 KEY=VALUE 键值对、#/; 开头注释行与空行，兼容 bash 风格的
   * export 前缀。值支持单/双引号包裹，双引号内支持 \n \r \t \" \\ 转义，
   * 未加引号的值从首个 " #" 处截断行内注释。
   *
   * @param string $line 单行配置（不含换行符）
   * @return array{0:string,1:string}|null [键, 值]；注释、空行或格式错误返回 null
   */
  protected function parseLine(string $line): ?array
  {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
      return null;
    }
    // 兼容 bash 风格的 export 前缀（export KEY=VALUE）
    $line = (string)preg_replace('/^export\s+/i', '', $line);
    $position = strpos($line, '=');
    if ($position === false) {
      return null;
    }
    $key = trim(substr($line, 0, $position));
    if ($key === '') {
      return null;
    }
    $value = trim(substr($line, $position + 1));
    return [$key, $this->parseValue($value)];
  }

  /**
   * 解析 .env 值，处理引号包裹与转义序列
   *
   * @param string $value 原始值
   * @return string 解析后的值
   */
  protected function parseValue(string $value): string
  {
    if ($value === '') {
      return '';
    }
    // 单引号包裹：内容原样保留，不处理转义
    if (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2) {
      return substr($value, 1, -1);
    }
    // 双引号包裹：处理 \n \r \t \" \\ 等转义序列
    if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
      $value = substr($value, 1, -1);
      return (string)preg_replace_callback(
        '/\\\\([nrt"\\\\])/',
        static fn(array $match): string => match ($match[1]) {
          'n' => "\n",
          'r' => "\r",
          't' => "\t",
          default => $match[1],
        },
        $value
      );
    }
    // 未加引号：从首个 " #" 截断行内注释（# 前需有空白，避免误截 URL 中的 #）
    if (($position = strpos($value, ' #')) !== false) {
      $value = substr($value, 0, $position);
    }
    return trim($value);
  }

  /**
   * 设置环境变量值，支持批量设置和单条设置
   *
   * 批量设置时键名自动转大写，INI 分节键以 下划线 连接（如 SECTION_KEY）
   *
   * @param array|string $env 键值对数组或变量名
   * @param mixed|null $value 变量值（仅单条设置时使用）
   */
  public function set(array|string $env, mixed $value = null): void
  {
    if (is_array($env)) {
      $env = array_change_key_case($env, CASE_UPPER);

      foreach ($env as $key => $val) {
        if (is_array($val)) {
          foreach ($val as $k => $v) {
            $this->data[$key . '_' . strtoupper($k)] = $v;
          }
        } else {
          $this->data[$key] = $val;
        }
      }
    } else {
      $name = strtoupper(str_replace('.', '_', $env));

      $this->data[$name] = $value;
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return $this->__isset($offset);
  }

  /**
   * 检测环境变量是否存在（值为 null 视为不存在）
   *
   * @param string $name 变量名
   * @return bool 存在且非 null 返回 true
   */
  public function __isset(string $name): bool
  {
    return $this->has($name);
  }

  /**
   * 检测环境变量是否存在
   *
   * @param string $name 变量名
   * @return bool 存在且非 null 返回 true
   */
  public function has(string $name): bool
  {
    return !is_null($this->get($name));
  }

  /**
   * 获取环境变量值，优先从 .env 数据取，未命中则回退到系统环境变量
   *
   * @param string|null $name 变量名，为 null 时返回全部数据
   * @param mixed|null $default 默认值
   * @return mixed 变量值、默认值或全部数据
   */
  public function get(?string $name = null, mixed $default = null): mixed
  {
    if (is_null($name)) return $this->data;
    $name = strtoupper(str_replace('.', '_', $name));
    if (isset($this->data[$name])) {
      $result = $this->data[$name];
      if (is_string($result) && isset($this->convert[$result])) {
        $result = $this->convert[$result];
      }
      return $result;
    }
    // 非.env定义的环境变量则通过getEnv方法获取系统环境变量
    return $this->getEnv($name, $default);
  }

  /**
   * 通过 getenv() 获取系统级环境变量，命中后缓存到 $data 避免重复调用
   *
   * @param string $name 变量名（大写）
   * @param mixed $default 默认值
   * @return mixed 系统环境变量值或默认值
   */
  public function getEnv(string $name, $default = null): mixed
  {
    $result = getenv($name);

    if (false === $result) return $default;

    if (is_string($result) && isset($this->convert[$result])) {
      $result = $this->convert[$result];
    }

    if (!isset($this->data[$name])) {
      $this->data[$name] = $result;
    }
    return $result;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    return $this->get($offset);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    $this->set($offset, $value);
  }

  /**
   * @throws Exception 环境变量不支持 unset 操作
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    throw new Exception('not support: unset');
  }
}
