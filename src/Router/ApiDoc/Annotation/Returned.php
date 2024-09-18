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

namespace Viswoole\Router\ApiDoc\Annotation;

use Attribute;
use Closure;
use UnitEnum;
use Viswoole\Core\Common\Arr;
use Viswoole\Router\ApiDoc\Structure\ArrayTypeStructure;
use Viswoole\Router\ApiDoc\Structure\BaseTypeStructure;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\ObjectStructure;
use Viswoole\Router\ApiDoc\Structure\Types;

/**
 * 返回值注解
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Returned
{
  /** json */
  const string TYPE_JSON = 'application/json';
  /** XML */
  const string TYPE_XML = 'application/xml';
  /** html */
  const string TYPE_HTML = 'text/html';
  /** 纯文本 */
  const string TYPE_TEXT = 'text/plain';
  /** 二进制流 */
  const string TYPE_STREAM = 'application/octet-stream';
  /**
   * @var array|string 示例响应数据
   */
  public array|string $data;
  /**
   * @var BaseTypeStructure data结构
   */
  public BaseTypeStructure $structure;

  /**
   * @param string $title 标题
   * @param array|string $data 支持传入数组或者字符串
   * @param int $statusCode 状态码，默认为200
   * @param string $type 响应类型，默认为application/json
   */
  public function __construct(
    public string $title,
    array|string  $data,
    public int    $statusCode = 200,
    public string $type = self::TYPE_JSON
  )
  {
    if (is_array($data)) {
      if (Arr::isIndexArray($data)) {
        // 解析索引数组
        [$data, $arrayType] = $this->parseValueType($data);
        $this->structure = $arrayType;
        $this->data = $data;
      } else {
        [$data, $props] = $this->parseArrayObject($data);
        $this->data = $data;
        $this->structure = new ObjectStructure($props);
      }
    } else {
      $this->data = $data;
      $this->structure = new BaseTypeStructure(Types::String);
    }
  }

  /**
   * 解析值类型
   *
   * @param mixed $value
   * @return array{0:mixed,1:BaseTypeStructure}
   */
  private function parseValueType(mixed $value): array
  {
    $builtin = [
      'boolean' => Types::Bool,
      'integer' => Types::Int,
      'double' => Types::Float,
      'string' => Types::String,
      'NULL' => Types::Null
    ];
    $type = gettype($value);
    // 内置类型
    if (array_key_exists($type, $builtin)) {
      return [$value, new BaseTypeStructure($builtin[$type])];
    }
    switch ($type) {
      case 'array':
        if (empty($value)) {
          return [$value, new ArrayTypeStructure(new BaseTypeStructure(Types::Mixed))];
        }
        if (Arr::isIndexArray($value)) {
          $items = [];
          foreach ($value as $index => $item) {
            [$item, $type] = $this->parseValueType($item);
            $value[$index] = $item;
            $items[] = $type;
          }
          $arrayType = new ArrayTypeStructure(...$items);
          return [$value, $arrayType];
        } else {
          [$data, $props] = $this->parseArrayObject($value);
          $value = $data;
          // 关联数组，视为对象
          return [$value, new ObjectStructure($props)];
        }
      case 'object':
        if ($value instanceof Closure) {
          return ['Closure', new BaseTypeStructure(Types::Mixed)];
        }
        if ($value instanceof UnitEnum) {
          return [$value->name, new BaseTypeStructure(Types::String)];
        }
        return [$value, new ObjectStructure($value, true)];
      default:
        // 未知类型 或 资源类型
        return ['unknown', new BaseTypeStructure(Types::Mixed)];
    }
  }

  /**
   * 解析数组对象结构
   *
   * @param array $data
   * @return array{0:array,1:FieldStructure[]}
   */
  private function parseArrayObject(array $data): array
  {
    $props = [];
    $showData = [];
    foreach ($data as $key => $value) {
      [$key, $description, $allowNull] = $this->parseKey((string)$key);
      $showData[$key] = $value;
      [$value, $type] = $this->parseValueType($value);
      $props[] = new FieldStructure($key, $description, $allowNull, $value, $type);
    }
    $data = $showData;
    return [$data, $props];
  }

  /**
   * 解析键名
   *
   * @param string $key
   * @return array
   */
  private function parseKey(string $key): array
  {
    $array = explode('|', $key);
    $key = trim($array[0]);
    if (str_ends_with($key, '?')) {
      $key = substr($key, 0, -1);
      $allowNull = true;
    } else {
      $allowNull = false;
    }
    $description = $array[1] ?? '';
    return [trim($key), trim($description), $allowNull];
  }
}
