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

use RuntimeException;
use Viswoole\Core\Common\Str;

/**
 * 配置管理器
 *
 * 负责加载、解析和访问多格式配置文件（PHP/YAML/INI/JSON），
 * 支持点号分隔的多级配置读写，可选大小写不敏感模式。
 * 配置在 AppInitialized 事件后自动加载 lazy 子目录中的懒加载配置。
 */
class Config
{
  /**
   * @var string 配置文件目录路径
   */
  public readonly string $path;
  /**
   * @var string 配置文件扩展名匹配模式（如 '*' 匹配所有）
   */
  public readonly string $ext;
  /**
   * @var bool 配置键是否区分大小写，为 false 时自动转为蛇形命名
   */
  public readonly bool $matchCase;
  /**
   * @var array 已加载的配置数据，键为文件名（不含扩展名）
   */
  protected array $config = [];

  /**
   * @param Event $event 事件管理器，用于监听 AppInitialized 事件触发懒加载
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
   * 扫描指定目录下的配置文件并合并到配置池
   *
   * @param string $path 配置文件目录路径
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
   * 解析配置文件列表，按文件扩展名分发到对应解析器
   *
   * 支持 PHP（include）、YAML、INI、JSON 四种格式，同名配置文件会合并
   *
   * @param array $files 配置文件路径列表
   * @return array 以文件名为键的配置数组
   */
  protected function parse(array $files): array
  {
    $configs = [];
    foreach ($files as $file) {
      $type = pathinfo($file, PATHINFO_EXTENSION); //文件类型
      $key = pathinfo($file, PATHINFO_FILENAME); //文件名
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
   * 递归将数组所有键转为蛇形命名（仅在 matchCase=false 时调用）
   *
   * @param array $array 待转换的数组
   * @return array 键已转为蛇形命名的新数组
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
   * 格式化配置键名，大小写不敏感模式下转为蛇形命名
   *
   * @param string $key 原始键名
   * @return string 格式化后的键名
   */
  public function formatConfigKey(string $key): string
  {
    if (!$this->matchCase) return Str::camelCaseToSnakeCase($key);
    return $key;
  }

  /**
   * 检测配置项是否存在（值为 null 视为不存在）
   *
   * @param string $name 配置参数名，支持点号分隔多级
   * @return bool 存在且非 null 返回 true
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
   * 获取配置值，支持点号分隔的多级访问
   *
   * @param string|null $name 配置名称，为 null 时返回全部配置
   * @param mixed $default 键不存在时的默认值
   * @return mixed 配置值或默认值
   */
  public function get(?string $name = null, mixed $default = null): mixed
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
   * 设置或更新配置项，仅在当前进程生命周期内有效，进程重启后丢失
   *
   * 支持批量设置（传入关联数组）和点号分隔的多级设置
   *
   * @param string|array $key 配置键名或键值对数组
   * @param mixed|null $value 配置值
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
