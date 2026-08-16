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

namespace Viswoole\Router\ApiDoc;

use Viswoole\Router\ApiDoc\Structure\ArrayTypeStructure;
use Viswoole\Router\ApiDoc\Structure\EnumStructure;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\ObjectStructure;
use Viswoole\Router\ApiDoc\Structure\TypeStructure;
use Viswoole\Router\ApiDoc\Structure\Types;

/**
 * 文档注释类型声明解析器
 *
 * 将 @param 标签中的 PHPStan 风格类型字符串解析为类型结构：
 *   - 基础类型：int、string、bool、float、mixed、object、null
 *   - 数组后缀：int[]、string[][]、array{id: int}[]（多维数组递归解析）
 *   - 联合类型：int|string、(int|string)[]
 *   - 关联结构：array{id: int, name?: string}，键名 ? 后缀标记可选字段，支持嵌套
 *   - 类/枚举：须完全限定名称或全局命名空间短名（不解析 use 导入别名）
 *
 * 解析失败（含未知类名等）时返回空数组，由调用方回退到反射类型
 */
class DocTypeParser
{
  /**
   * 解析类型字符串为类型结构列表
   *
   * @param string $typeString docblock类型声明字符串
   * @param array<string,string> $dependMap 对象依赖映射[完整类名=>显示名]，用于处理递归引用
   * @return TypeStructure[] 以类型名称为键的类型结构列表，解析失败返回空数组
   */
  public static function parse(string $typeString, array &$dependMap = []): array
  {
    $typeString = trim($typeString);
    if ($typeString === '') return [];
    $result = [];
    foreach (self::splitTopLevel($typeString, '|') as $member) {
      $parsed = self::parseMember($member, $dependMap);
      // 任一部分未知则整体回退，避免给出误导性的部分类型
      if ($parsed === null) return [];
      foreach ($parsed as $item) $result[$item->getName()] = $item;
    }
    return $result;
  }

  /**
   * 解析联合类型中的单个成员
   *
   * @param string $member 成员类型字符串
   * @param array<string,string> $dependMap 对象依赖映射
   * @return TypeStructure[]|null 类型结构列表，无法识别时返回 null
   */
  private static function parseMember(string $member, array &$dependMap): ?array
  {
    $member = trim($member);
    if ($member === '') return null;
    // nullable前缀：可空性由参数allowNull表达，此处仅剥离语法
    if ($member[0] === '?') $member = trim(substr($member, 1));
    // 数组后缀：递归解析元素类型后包装为数组结构（int[][] 逐层剥离）
    if (str_ends_with($member, '[]')) {
      $items = self::parse(substr($member, 0, -2), $dependMap);
      return $items === [] ? null : [new ArrayTypeStructure(...$items)];
    }
    // 括号包裹的联合类型：(int|string) 剥离括号后按联合解析
    if ($member[0] === '(' && self::isParenthesisWrapped($member)) {
      return self::parse(substr($member, 1, -1), $dependMap) ?: null;
    }
    return self::parseAtom($member, $dependMap);
  }

  /**
   * 解析原子类型（内置类型、array{}关联结构或类名）
   *
   * @param string $atom 原子类型字符串
   * @param array<string,string> $dependMap 对象依赖映射
   * @return TypeStructure[]|null 类型结构列表，无法识别时返回 null
   */
  private static function parseAtom(string $atom, array &$dependMap): ?array
  {
    // null性由allowNull表达，跳过该成员
    if ($atom === 'null') return [];
    // 关联数组结构 array{key: type, ...}
    if (str_starts_with($atom, 'array{') && str_ends_with($atom, '}')) {
      return [self::parseShape(substr($atom, 6, -1), $dependMap)];
    }
    $builtin = match ($atom) {
      'bool', 'true', 'false' => [new TypeStructure(Types::Bool)],
      'float' => [new TypeStructure(Types::Float)],
      'int' => [new TypeStructure(Types::Int)],
      'string' => [new TypeStructure(Types::String)],
      'mixed', 'iterable', 'callable' => [new TypeStructure()],
      'object' => [new TypeStructure(Types::Object)],
      // 纯array无法得知元素类型，与反射解析行为保持一致
      'array' => [new ArrayTypeStructure(new TypeStructure())],
      default => null,
    };
    return $builtin ?? self::parseClass($atom, $dependMap);
  }

  /**
   * 解析类名或枚举名为对应结构
   *
   * 仅接受完全限定名称或全局命名空间短名，不解析 use 导入别名
   *
   * @param string $class 类名字符串（可带 \ 前缀）
   * @param array<string,string> $dependMap 对象依赖映射
   * @return TypeStructure[]|null 未知类名返回 null
   */
  private static function parseClass(string $class, array &$dependMap): ?array
  {
    $class = ltrim($class, '\\');
    if (enum_exists($class)) return [new EnumStructure($class)];
    if (class_exists($class)) return [new ObjectStructure($class, dependMap: $dependMap)];
    return null;
  }

  /**
   * 解析 array{} 关联结构体为对象结构
   *
   * @param string $body 花括号内的字段声明体
   * @param array<string,string> $dependMap 对象依赖映射
   * @return ObjectStructure 对象结构
   */
  private static function parseShape(string $body, array &$dependMap): ObjectStructure
  {
    $fields = [];
    foreach (self::splitTopLevel($body, ',') as $item) {
      $field = self::parseShapeField($item, $dependMap);
      if ($field !== null) $fields[] = $field;
    }
    return new ObjectStructure($fields);
  }

  /**
   * 解析关联结构中的单个字段声明（格式：key?: type）
   *
   * @param string $item 字段声明字符串
   * @param array<string,string> $dependMap 对象依赖映射
   * @return FieldStructure|null 声明格式非法时返回 null
   */
  private static function parseShapeField(string $item, array &$dependMap): ?FieldStructure
  {
    if (!preg_match('/^([^:]+):\s*([\s\S]+)$/', trim($item), $matches)) return null;
    $key = trim($matches[1]);
    // PHPStan可选字段语法：key?: type
    $allowNull = str_ends_with($key, '?');
    if ($allowNull) $key = rtrim(substr($key, 0, -1));
    // 键名支持引号包裹（含特殊字符的键）
    $key = trim($key, "\"'");
    if ($key === '') return null;
    $types = self::parse($matches[2], $dependMap);
    return new FieldStructure(
      $key,
      '',
      $allowNull,
      null,
      // 嵌套值类型无法识别时按mixed处理，不整体回退
      $types === [] ? [new TypeStructure()] : $types
    );
  }

  /**
   * 按顶层分隔符拆分字符串，忽略花括号内的分隔符
   *
   * 用于联合类型拆分（|）与关联结构字段拆分（,）
   *
   * @param string $body 待拆分字符串
   * @param string $delimiter 顶层分隔符
   * @return string[] 拆分并去除空白后的非空成员列表
   */
  private static function splitTopLevel(string $body, string $delimiter): array
  {
    $parts = [];
    $current = '';
    $depth = 0;
    $length = strlen($body);
    for ($i = 0; $i < $length; $i++) {
      $char = $body[$i];
      // 花括号（嵌套结构）与圆括号（联合分组）内的分隔符均不拆分
      if ($char === '{' || $char === '(') $depth++;
      elseif ($char === '}' || $char === ')') $depth--;
      if ($char === $delimiter && $depth === 0) {
        $parts[] = trim($current);
        $current = '';
      } else {
        $current .= $char;
      }
    }
    $parts[] = trim($current);
    return array_values(array_filter($parts, static fn($part) => $part !== ''));
  }

  /**
   * 判断字符串是否被首尾完整配对的括号包裹
   *
   * 首个 ( 的闭合位置必须恰好是末尾字符，否则视为普通字符
   *
   * @param string $type 类型字符串
   * @return bool 是完整包裹返回 true
   */
  private static function isParenthesisWrapped(string $type): bool
  {
    $depth = 0;
    $length = strlen($type);
    for ($i = 0; $i < $length; $i++) {
      $char = $type[$i];
      if ($char === '(') $depth++;
      elseif ($char === ')') {
        $depth--;
        if ($depth === 0) return $i === $length - 1;
      }
    }
    return false;
  }
}
