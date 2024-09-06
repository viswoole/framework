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

use ArrayAccess;
use Exception;
use Override;

/**
 * Env管理类
 */
class Env implements ArrayAccess
{
  /**
   * @var array 环境变量数据
   */
  protected array $data = [];
  /**
   * @var array 数据转换映射
   */
  protected array $convert = [
    'true' => true,
    'false' => false,
    'off' => false,
    'on' => true,
  ];

  /**
   * @param App $app
   */
  public function __construct(App $app)
  {
    $this->data = $_ENV;
    $this->load($app->getEnvPath());
  }

  /**
   * 读取环境变量定义文件
   * @access public
   * @param string $file 环境变量定义文件
   * @return void
   */
  protected function load(string $file): void
  {
    if (is_file($file)) {
      $env = parse_ini_file($file, true, INI_SCANNER_RAW) ?: [];
    } else {
      $env = [];
    }
    $this->set($env);
  }

  /**
   * 设置环境变量值
   * @access public
   * @param array|string $env 环境变量 默认为根目录.env文件
   * @param mixed|null $value 值
   * @return void
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
   * 检测是否存在环境变量
   * @access public
   * @param string $name 参数名
   * @return bool
   */
  public function __isset(string $name): bool
  {
    return $this->has($name);
  }

  /**
   * 检测是否存在环境变量
   *
   * @access public
   * @param string $name 参数名
   * @return bool
   */
  public function has(string $name): bool
  {
    return !is_null($this->get($name));
  }

  /**
   * 获取环境变量值(可获取用户环境变量和系统环境变量)
   *
   * @access public
   * @param string|null $name 环境变量名
   * @param mixed|null $default 默认值
   * @return mixed
   */
  public function get(string $name = null, mixed $default = null): mixed
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
   * 获取环境变量(仅能获取系统缓存变量)
   *
   * @param string $name
   * @param $default
   * @return mixed
   */
  public function getEnv(string $name, $default = null): mixed
  {
    $result = getenv('PHP_' . $name);

    if (false === $result) return $default;

    if ('false' === $result) {
      $result = false;
    } elseif ('true' === $result) {
      $result = true;
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
   * @throws Exception 未实现该方法
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    throw new Exception('not support: unset');
  }
}
