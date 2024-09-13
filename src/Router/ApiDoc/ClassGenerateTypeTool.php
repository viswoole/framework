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

namespace Viswoole\Router\ApiDoc;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Viswoole\Core\App;

/**
 * 该类用于将类转换为类型声明文件
 */
class ClassGenerateTypeTool
{
  // PHP内置原子类型
  public const array TYPES = [
    'bool' => ['typescript' => 'boolean', 'dart' => 'bool'],
    'null' => ['typescript' => 'null', 'dart' => 'Null'],
    'int' => ['typescript' => 'number', 'dart' => 'int'],
    'float' => ['typescript' => 'number', 'dart' => 'double'],
    'string' => ['typescript' => 'string', 'dart' => 'String'],
    'array' => ['typescript' => 'Array<any>', 'dart' => 'List<dynamic>'],
    'object' => ['typescript' => '{[key: string]: any}', 'dart' => 'Map<String, dynamic>'],
    'true' => ['typescript' => 'true', 'dart' => 'bool'],
    'false' => ['typescript' => 'false', 'dart' => 'bool'],
    'boolean' => ['typescript' => 'boolean', 'dart' => 'bool'],
    'integer' => ['typescript' => 'number', 'dart' => 'int'],
    'double' => ['typescript' => 'number', 'dart' => 'double'],
    'iterable' => ['typescript' => 'Iterable<any>', 'dart' => 'Iterable<dynamic>'],
    'mixed' => ['typescript' => 'any', 'dart' => 'dynamic'],
    'callable' => ['typescript' => 'Function', 'dart' => 'Function'],
    'Closure' => ['typescript' => 'Function', 'dart' => 'Function']
  ];

  /**
   * 将类转换为类型声明文件
   *
   * @param string $class 要生成的类
   * @param string $lang 要生成的语言，暂只支持 `typescript`。
   * @param array $attach 引用类
   * @param array $map 类映射,短命名
   * @return array{name:string,content:string,attach:array<string,string>,map:string[]}
   */
  public static function class(
    string $class,
    string $lang = 'typescript',
    array  &$attach = [],
    array  &$map = [],
  ): array
  {
    if (!class_exists($class)) {
      throw new InvalidArgumentException("not found class $class");
    }
    // 反射类
    $reflector = new ReflectionClass($class);
    // 获取类文件名
    $file = $reflector->getFileName();
    // 获取哈希值
    $hash = hash_file('md5', $file);
    // 缓存文件名
    $cacheFileName = $file . $lang;
    // 获取缓存
    $cacheData = DocCacheTool::getCache($cacheFileName);
    // 缓存存在
    if ($cacheData) {
      // 判断哈希值是否一致，不一致则删除缓存
      if ($cacheData['hash'] === $hash) {
        // 返回缓存的数据
        return $cacheData['data'];
      } else {
        DocCacheTool::deleteCache($cacheFileName);
      }
    }
    // 类doc描述
    $desc = $reflector->getDocComment();
    $desc = $desc ? "$desc\n" : '';
    // 名称
    $name = self::formatClassName(
      $reflector->getNamespaceName(), $reflector->getName(), $attach
    );
    // 获取构造函数
    $construct = $reflector->getConstructor();
    if ($construct) {
      $shapes = self::parseParamShape($construct);
    } else {
      $shapes = self::parsePropertyShape($reflector->getProperties());
    }
    $content = self::generateTsInterface($name, $desc, $shapes, $attach, $map);
    return compact('name', 'content', 'attach', 'map');
  }

  /**
   * 格式化引用类名
   *
   * @param string $namespace
   * @param string $class
   * @param array $attach
   * @return string
   */
  private static function formatClassName(
    string $namespace,
    string $class,
    array  $attach,
  ): string
  {
    // 类名称
    $name = str_replace($namespace . '\\', '', $class);
    // 如果已经存在则保留完整名称
    if (isset($attach[$name])) $name = str_replace('\\', '.', $class);
    return $name;
  }

  /**
   * 解析方法参数结构
   *
   * @param ReflectionMethod $method
   * @return array<string,array{type:array,desc:string,default:mixed}>
   */
  private static function parseParamShape(ReflectionMethod $method): array
  {
    // 方法描述
    $doc = $method->getDocComment() ?: '';
    $params = $method->getParameters();
    $shapes = [];
    foreach ($params as $param) {
      $name = $param->name;
      $default = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
      $shapes[$name] = [
        'type' => self::typeToArray($param),
        'desc' => Common::extractParamDoc($doc, $name),
        'default' => $default
      ];
    }
    return $shapes;
  }

  /**
   * 参数类型转换为数组
   *
   * @param ReflectionProperty|ReflectionParameter $reflector
   * @return array
   */
  private static function typeToArray(
    ReflectionProperty|ReflectionParameter $reflector
  ): array
  {
    $type = (string)($reflector->getType() ?: 'mixed');
    if (str_starts_with($type, '?')) $type = substr($type, 1) . '|null';
    return explode('|', $type);
  }

  /**
   * 解析类属性结构
   *
   * @param ReflectionProperty[] $property_s
   * @return array<string,array{type:array,desc:string,default:mixed}>
   */
  private static function parsePropertyShape(array $property_s): array
  {
    $shapes = [];
    foreach ($property_s as $property) {
      if (!$property->isPublic()) continue;
      $name = $property->name;
      $default = $property->getDefaultValue();
      $shapes[$name] = [
        'type' => self::typeToArray($property),
        'desc' => Common::extractPropertyDoc($property->getDocComment() ?: ''),
        'default' => $default
      ];
    }
    return $shapes;
  }

  /**
   * 解析参数结构为接口
   *
   * @param string $name 接口名称
   * @param string $description 接口描述
   * @param array<string,array{type:array,desc:string}> $shapes
   * @param array $attach
   * @param array $classMap
   * @return string
   */
  private static function generateTsInterface(
    string $name,
    string $description,
    array  $shapes,
    array  &$attach,
    array  &$classMap
  ): string
  {
    // 提取命名空间
    [$name, $namespace] = self::extractNamespace($name);
    // 描述 拼接接口 关键词 名称
    $interface = "{$description}export interface $name {\n";
    foreach ($shapes as $name => $shape) {
      $desc = $shape['desc'];
      $types = $shape['type'];
      // 默认值
      $default = $shape['default'];
      // 是否允许为空
      $allowEmpty = in_array('null', $types) ? '?' : '';
      // 解析类型
      $type = self::formatTsType($types, $attach, $classMap);
      $default = $default ? "(默认值:$default)" : '';
      $desc = "  /** $desc$default */\n";
      $interface .= $desc . "  $name$allowEmpty: $type\n";
    }
    $interface .= "}\n";
    // 如果没有命名空间则返回
    if (empty($namespace)) {
      $content = $interface;
    } else {
      $content = self::generateTsNamespace($namespace, $interface);
    }
    return $content;
  }

  /**
   * 提取命名空间
   *
   * @param string $name
   * @return array{0:string,1:array} [名称，命名空间数组]
   */
  private static function extractNamespace(string $name): array
  {
    // 命名空间
    $namespace = [];
    // 判断是否有命名空间
    if (str_contains($name, '.')) {
      // 重新赋值命名空间
      $namespace = explode('.', $name);
      // 提取短名称
      $name = array_pop($namespace);
    }
    return [$name, $namespace];
  }

  /**
   * 解析类型声明
   *
   * @param string[] $types
   * @param array<string,string> $attach
   * @param string[] $classMap
   * @return string
   */
  private static function formatTsType(array $types, array &$attach, array &$classMap): string
  {
    $parseTypes = [];
    foreach ($types as $type) {
      if (array_key_exists($type, self::TYPES)) {
        $parseTypes[] = self::TYPES[$type]['typescript'];
      } elseif (App::factory()->has($type)) {
        continue;
      } elseif (str_contains($type, '&')) {
        // 交集类型 不适用 因为php接口不支持约束参数，而交集类型一般都是约束对象必须同时实现多个接口
        $parseTypes[] = 'any';
      } elseif (interface_exists($type)) {
        $parseTypes[] = 'any';
      } elseif (class_exists($type)) {
        // 重复引用
        if (array_key_exists($type, $classMap)) {
          $parseTypes[] = $classMap[$type];
        } else {

          // 生成声明
          $result = enum_exists($type)
            ? self::enum($type, 'typescript', $attach)
            : self::class($type, 'typescript', $attach, $classMap);
          $name = $result['name'];
          // 记录映射
          $classMap[$type] = $name;
          // 记录声明
          $attach[$name] = $result['content'];
          $parseTypes[] = $name;
        }
      } else {
        $parseTypes[] = 'any';
      }
    }
    return implode(' | ', $parseTypes);
  }

  /**
   * 转换枚举
   *
   * @param string $enum
   * @param string $lang
   * @param array $attach
   * @return array{name:string,content:string}
   * @noinspection PhpDocMissingThrowsInspection
   */
  public static function enum(
    string $enum,
    string $lang = 'typescript',
    array  $attach = [],
  ): array
  {
    /** @noinspection PhpUnhandledExceptionInspection */
    $reflector = new ReflectionClass($enum);
    // 获取枚举值
    $cases = array_keys($reflector->getConstants());
    // 名称
    $name = self::formatClassName(
      $reflector->getNamespaceName(), $reflector->getName(), $attach
    );
    $desc = $reflector->getDocComment();
    $desc = $desc ? "$desc\n" : '';
    if ($lang === 'typescript') {
      return self::generateTsEnum($cases, $name, $desc);
    } else {
      throw new RuntimeException('暂时只支持typescript');
    }
  }

  /**
   * 枚举转ts
   *
   * @param array $cases 枚举的所有选项
   * @param string $name 类名
   * @param string $description 描述
   * @return array
   */
  private static function generateTsEnum(array $cases, string $name, string $description): array
  {
    [$name, $namespace] = self::extractNamespace($name);
    // 拼接描述
    $enum = "{$description}export enum $name {\n";
    $space = '  ';
    foreach ($cases as $case) $enum .= "$space$case\n";
    $enum .= "}\n";
    if (!empty($namespace)) {
      $enum = self::generateTsNamespace($namespace, $enum);
    }
    return ['name' => $name, 'content' => $enum];
  }

  /**
   * 生成命名空间
   *
   * @param array $namespace
   * @param string $content
   * @return string
   */
  private static function generateTsNamespace(array $namespace, string $content): string
  {
    // 生成命名空间并嵌入接口定义
    $namespaceContent = '';
    $endString = '';
    foreach ($namespace as $index => $nsPart) {
      $export = $index === 0 ? '' : 'export ';
      // 缩进空格
      $space = str_repeat('  ', $index);
      $namespaceContent .= "$space{$export}namespace $nsPart {\n";
      $endString = "$space}\n$endString";
    }
    $content = explode("\n", $content);
    $space = str_repeat('  ', count($namespace));
    array_walk($content, function (&$item) use ($space) {
      $item = empty($item) ? '' : $space . $item;
    });
    $content = implode("\n", $content);
    return $namespaceContent . $content . $endString;
  }
}
