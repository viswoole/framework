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

namespace Viswoole\Core;

use RuntimeException;
use Viswoole\Core\Common\Str;

/**
 * 配置文件管理类
 */
class Config
{
  /**
   * 配置文件目录
   * @var string
   */
  public readonly string $path;
  /**
   * 配置文件扩展名
   * @var string
   */
  public readonly string $ext;
  /**
   * @var bool 是否区分大小写
   */
  public readonly bool $matchCase;
  /**
   * 配置参数
   * @var array
   */
  protected array $config = [];

  /**
   * @param Event $event 事件管理器
   */
  public function __construct(Event $event)
  {
    $this->path = getConfigPath() . DIRECTORY_SEPARATOR;
    $this->ext = '*';
    $this->matchCase = true;
    $this->load($this->path);
    $event->on('AppInitialized', function () {
      // 监听AppInitialized事件，在App初始化完成后 加载懒加载文件
      $this->load($this->path . 'lazy' . DIRECTORY_SEPARATOR);
    }, 1);
  }

  /**
   * 加载配置文件
   * @param string $path
   * @return void
   */
  private function load(string $path): void
  {
    // 配置文件
    $defaultConfigFiles = glob($path . '*.' . $this->ext);
    //如果出错了 则赋值为空数组
    if ($defaultConfigFiles === false) $defaultConfigFiles = [];
    $this->config = array_merge($this->config, $this->parse($defaultConfigFiles));
  }

  /**
   * 解析配置文件
   *
   * @access public
   * @param array $files
   * @return array
   */
  protected function parse(array $files): array
  {
    $configs = [];
    foreach ($files as $file) {
      $type = pathinfo($file, PATHINFO_EXTENSION);//文件类型
      $key = pathinfo($file, PATHINFO_FILENAME);//文件名
      $config = match ($type) {
        'php' => include $file,
        'yml', 'yaml' => function_exists('yaml_parse_file') ? yaml_parse_file($file) : [],
        'ini' => parse_ini_file($file, true, INI_SCANNER_TYPED) ?: [],
        'json' => (static function () use ($file) {
          $content = file_get_contents($file);
          $data = json_decode($content, true);
          if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("JSON配置文件解析失败: $file - " . json_last_error_msg());
          }
          return $data ?? [];
        })(),
        default => [],
      };
      if (isset($config) && is_array($config)) {
        $configs[$key] = isset($configs[$key]) ? array_merge($configs[$key], $config) : $config;
      }
    }

    if (!$this->matchCase) $configs = $this->recursiveArrayKeyToLower($configs);
    return $configs;
  }

  /**
   * 递归转换键为小写
   *
   * @param array $array
   * @return array
   */
  protected function recursiveArrayKeyToLower(array $array): array
  {
    $result = [];
    foreach ($array as $key => $value) {
      // 如果值是数组，递归调用函数
      if (is_array($value)) $value = $this->recursiveArrayKeyToLower($value);
      // 将键转换为蛇形
      $newKey = $this->formatConfigKey($key);
      // 将新的键值对添加到结果数组中
      $result[$newKey] = $value;
    }
    return $result;
  }

  /**
   * 格式化key
   *
   * @param string $key 配置参数名
   * @return string 如果不区分大小写，则转换为蛇形
   */
  public function formatConfigKey(string $key): string
  {
    if (!$this->matchCase) return Str::camelCaseToSnakeCase($key);
    return $key;
  }

  /**
   * 检测配置是否存在
   *
   * @access public
   * @param string $name 配置参数名（支持多级配置 .号分割）
   * @return bool 注意：如果检测配置值为null时也会返回false
   */
  public function has(string $name): bool
  {
    $name = $this->formatConfigKey($name);
    if (!str_contains($name, '.') && !array_key_exists($name, $this->config)) {
      return false;
    }
    return !is_null($this->get($name));
  }

  /**
   * 获取配置参数 name为null则获取所有配置
   * @access public
   * @param string|null $name 配置名称（支持多级配置 .号分割）
   * @param mixed $default 默认值(null)
   * @return mixed
   */
  public function get(string $name = null, mixed $default = null): mixed
  {
    // 修复#3: empty()误判配置键'0'，改为严格判断null和空字符串
    if ($name === null || $name === '') return $this->config;
    // 不区分大小写处理
    $nameParts = explode('.', $this->formatConfigKey($name));
    $config = $this->config;

    foreach ($nameParts as $part) {
      if (!is_array($config) || !array_key_exists($part, $config)) return $default;
      // 修复#4: ??将null配置值误判为缺失，前一行已用array_key_exists确认键存在，直接取值
      $config = $config[$part];
    }
    return $config;
  }

  /**
   * 设置或更新配置，仅在当前进程中下有效，重启进程则会丢失。
   *
   * @param string|array $key 键
   * @param mixed|null $value 值
   * @return void
   */
  public function set(string|array $key, mixed $value = null): void
  {
    if (is_array($key)) {
      foreach ($key as $k => $v) {
        $this->set($k, $v);
      }
    } else {
      $key = $this->formatConfigKey($key);
      $keys = explode('.', $key);
      $refArray = &$this->config;
      foreach ($keys as $k) {
        // 修复#5: isset()覆盖null值配置，改为array_key_exists检查键是否存在，并确保值为数组才继续深入
        if (!array_key_exists($k, $refArray) || !is_array($refArray[$k])) {
          // 如果键不存在，则创建它并将其设置为一个空数组
          $refArray[$k] = [];
        }
        $refArray = &$refArray[$k];
      }
      // 在最后一个子数组中设置新值
      $refArray = $value;
    }
  }
}
