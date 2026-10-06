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

namespace Viswoole\Database\Entity;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use ValueError;
use Viswoole\Core\Common\Str;
use Viswoole\Database\Entity;

/**
 * 实体水合器
 *
 * 负责实体属性元数据的反射构建与进程级缓存，并提供两个方向的
 * 类型转换：
 * - coerce()：数据库行值 → 属性类型值（水合），如 '1' → true、
 *   '2026-01-01 00:00:00' → DateTimeImmutable、标量 → 回退枚举；
 * - dehydrate()：属性类型值 → 可绑定数据库的标量（脱水），
 *   如枚举 → 回退值、日期对象 → 'Y-m-d H:i:s' 字符串、array → JSON 字符串；
 * - serialize()：属性类型值 → 序列化安全的值（toArray 输出方向），
 *   与 dehydrate 的区别是 array 保留数组结构不编码。
 *
 * 元数据按实体类缓存一次，协程环境下复用，零重复反射开销。
 *
 * @see Entity
 */
final class EntityHydrator
{
  /** @var int 数字字符串强转为 int 的最大长度（超出防溢出饱和） */
  private const int MAX_INT_STRING_LENGTH = 19;

  /** @var array<class-string,array{byColumn:array<string,PropertyMeta>,byProperty:array<string,PropertyMeta>}> 元数据缓存，键为实体类名 */
  private static array $cache = [];

  /** @var array<string,true>|null 基类（Entity 及其父类）声明的配置属性名集合，构建元数据时排除 */
  private static ?array $excludedNames = null;

  /**
   * 获取实体类全部属性元数据（以列名为键）
   *
   * @param class-string $entityClass 实体类名
   * @return array<string,PropertyMeta> 列名 => 元数据
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  public static function metasByColumn(string $entityClass): array
  {
    return self::resolve($entityClass)['byColumn'];
  }

  /**
   * 获取（并按需构建）实体类的元数据缓存
   *
   * @param class-string $entityClass 实体类名
   * @return array{byColumn:array<string,PropertyMeta>,byProperty:array<string,PropertyMeta>}
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  private static function resolve(string $entityClass): array
  {
    if (isset(self::$cache[$entityClass])) return self::$cache[$entityClass];
    return self::$cache[$entityClass] = self::build($entityClass);
  }

  /**
   * 通过反射构建实体类的属性元数据
   *
   * 仅收集 Entity 与具体实体类之间声明的属性（基类配置属性不参与映射），
   * 父实体类的属性会被子类继承复用，同名属性以子类声明为准。
   *
   * @param class-string $entityClass 实体类名
   * @return array{byColumn:array<string,PropertyMeta>,byProperty:array<string,PropertyMeta>}
   */
  private static function build(string $entityClass): array
  {
    $byColumn = [];
    $byProperty = [];
    $excluded = self::excludedPropertyNames();
    /** @noinspection PhpUnhandledExceptionInspection */
    $current = new ReflectionClass($entityClass);
    // 沿继承链向上收集，越过 Entity 基类即停止（基类属性为配置而非字段）
    while ($current !== false && $current->getName() !== Entity::class) {
      foreach ($current->getProperties() as $prop) {
        // getProperties 返回可见的继承属性，仅处理当前类显式声明的
        if ($prop->isStatic()) continue;
        if ($prop->getDeclaringClass()->getName() !== $current->getName()) continue;
        // 子类重声明的基类配置属性（$table/$hidden 等）不是字段，排除
        if (isset($excluded[$prop->getName()])) continue;
        $meta = self::buildMeta($prop);
        $byColumn[$meta->column] = $meta;
        $byProperty[$meta->propertyName] = $meta;
      }
      $current = $current->getParentClass();
    }
    return ['byColumn' => $byColumn, 'byProperty' => $byProperty];
  }

  /**
   * 收集 Entity 及其全部父类声明的属性名（配置属性排除集）
   *
   * 结果进程内缓存，仅计算一次。
   *
   * @return array<string,true> 属性名 => true
   */
  private static function excludedPropertyNames(): array
  {
    if (self::$excludedNames !== null) return self::$excludedNames;
    $names = [];
    for (
      $class = new ReflectionClass(Entity::class);
      $class !== false;
      $class = $class->getParentClass()
    ) {
      foreach ($class->getProperties() as $prop) {
        $names[$prop->getName()] = true;
      }
    }
    return self::$excludedNames = $names;
  }

  /**
   * 构建单个属性的元数据
   *
   * @param ReflectionProperty $prop 属性反射
   * @return PropertyMeta 属性元数据
   * @throws RuntimeException 属性为 readonly 或声明了不支持的字段类型时抛出
   */
  private static function buildMeta(ReflectionProperty $prop): PropertyMeta
  {
    if ($prop->isReadOnly()) {
      throw new RuntimeException(
        sprintf(
          '实体属性 %s::$%s 不支持 readonly：实体水合与 save() 需要对属性赋值，请去掉 readonly 修饰',
          $prop->getDeclaringClass()->getName(), $prop->getName()
        )
      );
    }
    // 默认值须先 hasDefaultValue() 判定，getDefaultValue() 在无默认值时会抛异常
    $hasDefault = $prop->hasDefaultValue();
    $rawDefault = $hasDefault ? $prop->getDefaultValue() : null;
    $type = $prop->getType();
    if ($type === null) {
      return new PropertyMeta(
        $prop->getName(),
        Str::camelCaseToSnakeCase($prop->getName()),
        $prop,
        PropertyMeta::KIND_MIXED,
        true,
        null,
        $hasDefault,
        $rawDefault
      );
    }
    if (!$type instanceof ReflectionNamedType) {
      throw new RuntimeException(
        sprintf(
          '实体属性 %s::$%s 声明了不支持的联合/交叉类型，仅支持单一类型或 ?可空类型',
          $prop->getDeclaringClass()->getName(), $prop->getName()
        )
      );
    }
    [$kind, $targetClass] = self::detectKind($prop, $type);
    return new PropertyMeta(
      $prop->getName(),
      Str::camelCaseToSnakeCase($prop->getName()),
      $prop,
      $kind,
      $type->allowsNull(),
      $targetClass,
      $hasDefault,
      $rawDefault
    );
  }

  /**
   * 根据属性类型声明检测类型种类
   *
   * @param ReflectionProperty $prop 属性反射，用于错误信息定位
   * @param ReflectionNamedType $type 属性类型
   * @return array{0:string,1:?string} [类型种类, 枚举类名或日期类名]
   * @throws RuntimeException 类型不受支持时抛出
   */
  private static function detectKind(ReflectionProperty $prop, ReflectionNamedType $type): array
  {
    $name = $type->getName();
    $owner = $prop->getDeclaringClass()->getName();
    // 内置类型
    if ($type->isBuiltin()) {
      return match ($name) {
        'int' => [PropertyMeta::KIND_INT, null],
        'float' => [PropertyMeta::KIND_FLOAT, null],
        'string' => [PropertyMeta::KIND_STRING, null],
        'bool' => [PropertyMeta::KIND_BOOL, null],
        // array：JSON 列编解码（读解码/写编码）；mixed：原样透传
        'array' => [PropertyMeta::KIND_ARRAY, null],
        'mixed' => [PropertyMeta::KIND_MIXED, null],
        default => throw new RuntimeException(
          sprintf(
            '实体属性 %s::$%s 声明了不支持的字段类型 %s，支持：int|float|string|bool|array|mixed|DateTimeInterface|BackedEnum',
            $owner, $prop->getName(), $name
          )
        ),
      };
    }
    // 回退枚举
    if (is_subclass_of($name, BackedEnum::class)) {
      return [PropertyMeta::KIND_ENUM, $name];
    }
    // 日期时间：接口默认落到不可变的 DateTimeImmutable
    if ($name === DateTimeInterface::class) {
      return [PropertyMeta::KIND_DATETIME, DateTimeImmutable::class];
    }
    if (is_subclass_of($name, DateTimeInterface::class)) {
      /** @var class-string<DateTime> $name */
      return [PropertyMeta::KIND_DATETIME, $name];
    }
    throw new RuntimeException(
      sprintf(
        '实体属性 %s::$%s 声明了不支持的字段类型 %s，支持：int|float|string|bool|array|mixed|DateTimeInterface|BackedEnum',
        $owner, $prop->getName(), $name
      )
    );
  }

  /**
   * 按列名获取单个属性元数据
   *
   * @param class-string $entityClass 实体类名
   * @param string $column 列名
   * @return PropertyMeta|null 元数据，该列未声明为属性时返回 null
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  public static function metaByColumn(string $entityClass, string $column): ?PropertyMeta
  {
    return self::resolve($entityClass)['byColumn'][$column] ?? null;
  }

  /**
   * 按属性名获取单个属性元数据
   *
   * @param class-string $entityClass 实体类名
   * @param string $propertyName 属性名（驼峰）
   * @return PropertyMeta|null 元数据，属性未声明时返回 null
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  public static function metaByProperty(string $entityClass, string $propertyName): ?PropertyMeta
  {
    return self::resolve($entityClass)['byProperty'][$propertyName] ?? null;
  }

  /**
   * 将数据库行值强转为属性声明类型的值（水合方向）
   *
   * null 值原样返回，可空性由调用方（水合流程）校验。
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 数据库行值
   * @return mixed 强转后的属性值
   * @throws InvalidArgumentException 值无法转换为属性声明类型时抛出
   */
  public static function coerce(PropertyMeta $meta, mixed $value): mixed
  {
    if ($value === null) return null;
    return match ($meta->kind) {
      PropertyMeta::KIND_INT => self::coerceInt($meta, $value),
      PropertyMeta::KIND_FLOAT => self::coerceFloat($meta, $value),
      PropertyMeta::KIND_STRING => self::coerceString($meta, $value),
      PropertyMeta::KIND_BOOL => self::coerceBool($meta, $value),
      PropertyMeta::KIND_DATETIME => self::coerceDatetime($meta, $value),
      PropertyMeta::KIND_ENUM => self::coerceEnum($meta, $value),
      // array：JSON 列场景（PDO 将 JSON 列返回为字符串，需解码为 array）
      PropertyMeta::KIND_ARRAY => self::coerceArray($meta, $value),
      // mixed：原样透传（任意形态由业务自定）
      default => $value,
    };
  }

  /**
   * 强转为 int：接受 int、整值 float 与纯数字字符串（防溢出饱和）
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @return int 强转后的整型
   * @throws InvalidArgumentException 值无法转换为 int 时抛出
   */
  private static function coerceInt(PropertyMeta $meta, mixed $value): int
  {
    if (is_int($value)) return $value;
    if (is_float($value) && floor($value) === $value) return (int)$value;
    if (
      is_string($value) && preg_match('/^-?\d+$/', $value)
      && strlen(ltrim($value, '-')) <= self::MAX_INT_STRING_LENGTH
    ) {
      return (int)$value;
    }
    throw self::coerceError($meta, $value);
  }

  /**
   * 构建类型强转失败的异常
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @param string $reason 附加原因说明
   * @return InvalidArgumentException
   */
  private static function coerceError(PropertyMeta $meta, mixed $value, string $reason = ''
  ): InvalidArgumentException
  {
    $exported = var_export($value, true);
    // 超长原值按 UTF-8 字符截断：敏感列内容（密码/证件号）与大 JSON 串
    // 不应整段进入异常消息（随日志泄露），保留类型轮廓即可定位问题
    if (strlen($exported) > 50) {
      $cut = preg_match('/^.{0,50}/us', $exported, $m) === 1
        ? $m[0]
        : substr($exported, 0, 50);
      $exported = $cut . '…(长度 ' . strlen($exported) . '，已截断)';
    }
    $detail = sprintf(
      '无法将值 %s 强转为 %s::$%s（%s 声明，列 %s）',
      $exported,
      $meta->prop->getDeclaringClass()->getName(),
      $meta->propertyName,
      $meta->kind . ($meta->targetClass ? '<' . $meta->targetClass . '>' : ''),
      $meta->column
    );
    if ($reason !== '') $detail .= "：$reason";
    return new InvalidArgumentException($detail);
  }

  /**
   * 强转为 float：接受 int、float 与数字字符串
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @return float 强转后的浮点型
   * @throws InvalidArgumentException 值无法转换为 float 时抛出
   */
  private static function coerceFloat(PropertyMeta $meta, mixed $value): float
  {
    if (is_int($value) || is_float($value)) return (float)$value;
    if (is_string($value) && is_numeric($value)) return (float)$value;
    throw self::coerceError($meta, $value);
  }

  /**
   * 强转为 string：接受标量值
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @return string 强转后的字符串
   * @throws InvalidArgumentException 值无法转换为 string 时抛出
   */
  private static function coerceString(PropertyMeta $meta, mixed $value): string
  {
    if (is_string($value)) return $value;
    if (is_int($value) || is_float($value)) return (string)$value;
    throw self::coerceError($meta, $value);
  }

  /**
   * 强转为 bool：仅接受 bool 与 0/1（含 '0'/'1'）的严格映射
   *
   * 不做真值推断（如 'true'/'false'），避免脏数据被静默转换。
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @return bool 强转后的布尔值
   * @throws InvalidArgumentException 值非 bool/0/1 时抛出
   */
  private static function coerceBool(PropertyMeta $meta, mixed $value): bool
  {
    if (is_bool($value)) return $value;
    if ($value === 0 || $value === '0') return false;
    if ($value === 1 || $value === '1') return true;
    throw self::coerceError($meta, $value);
  }

  /**
   * 强转为日期时间对象：接受 datetime 字符串与 Unix 时间戳
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @return DateTimeInterface 日期时间对象
   * @throws InvalidArgumentException 值无法解析为日期时间时抛出
   */
  private static function coerceDatetime(PropertyMeta $meta, mixed $value): DateTimeInterface
  {
    $class = $meta->targetClass ?? DateTimeImmutable::class;
    // 已是目标类型实例时原样返回（构造实体时直接传日期对象）
    if ($value instanceof $class) {
      /** @var DateTimeInterface $value */
      return $value;
    }
    try {
      if (is_string($value)) return new $class($value);
      if (is_int($value)) return new $class('@' . $value);
    } catch (\Exception $e) {
      throw self::coerceError($meta, $value, $e->getMessage());
    }
    throw self::coerceError($meta, $value);
  }

  /**
   * 强转为回退枚举实例：接受与回退类型一致的标量值
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 原始值
   * @return BackedEnum 枚举实例
   * @throws InvalidArgumentException 值不在枚举定义范围内或类型不匹配时抛出
   */
  private static function coerceEnum(PropertyMeta $meta, mixed $value): BackedEnum
  {
    /** @var class-string<BackedEnum> $class */
    $class = $meta->targetClass;
    // 已是目标枚举实例时原样返回（构造实体时直接传枚举）
    if ($value instanceof $class) {
      /** @var BackedEnum $value */
      return $value;
    }
    if (is_int($value) || is_string($value)) {
      try {
        return $class::from($value);
      } catch (ValueError) {
        $allowed = implode(
          ', ', array_map(
            static fn($case) => var_export($case->value, true), $class::cases()
          )
        );
        throw self::coerceError($meta, $value, "合法值：$allowed");
      }
    }
    throw self::coerceError($meta, $value);
  }

  /**
   * 强转为 array：接受数组与 JSON 对象字符串（JSON 列水合）
   *
   * MySQL/SQLite 的 JSON 列经 PDO 返回的是原始 JSON 字符串，而声明为
   * array 的类型化属性无法直接承接字符串（TypeError），此处统一解码。
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 数据库行值
   * @return array 解码后的数组（数组原样透传）
   * @throws InvalidArgumentException 值不是数组且不是合法 JSON 对象/数组字符串时抛出
   */
  private static function coerceArray(PropertyMeta $meta, mixed $value): array
  {
    if (is_array($value)) return $value;
    if (is_string($value) && $value !== '') {
      // JSON_THROW_ON_ERROR：捕获解码异常并将原因附入错误信息，
      // 避免语法错误/深度超限被静默吞掉后只报笼统的类型不匹配
      try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) return $decoded;
      } catch (JsonException $e) {
        throw self::coerceError($meta, $value, "JSON 解析失败：{$e->getMessage()}");
      }
    }
    throw self::coerceError($meta, $value);
  }

  /**
   * 将属性值转换为可绑定数据库的标量（落库脱水方向）
   *
   * 枚举取回退值、日期对象格式化为 'Y-m-d H:i:s' 字符串、array 编码为
   * JSON 字符串（JSON 列写方向，与 coerce 读方向对称），其余标量原样返回。
   *
   * 面向 API 序列化输出（toArray/jsonSerialize）时请改用
   * {@see serialize()}，避免 array 属性在 JSON 响应中被双重编码。
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 属性值
   * @return mixed 可绑定数据库的标量值
   * @throws InvalidArgumentException array 属性值不是数组时抛出
   * @throws RuntimeException JSON 编码失败时抛出
   */
  public static function dehydrate(PropertyMeta $meta, mixed $value): mixed
  {
    $serialized = self::serialize($meta, $value);
    // 仅落库路径编码 array；序列化路径（serialize）保留数组结构
    if ($serialized !== null && $meta->kind === PropertyMeta::KIND_ARRAY) {
      return self::dehydrateArray($meta, $serialized);
    }
    return $serialized;
  }

  /**
   * 将属性值转换为序列化安全的值（toArray/jsonSerialize 输出方向）
   *
   * 枚举取回退值、日期对象格式化为 'Y-m-d H:i:s'，其余原样返回；
   * array 属性保留数组结构（json_encode 原生支持嵌套数组，无需预编码）。
   *
   * 与 {@see dehydrate()} 的区别：dehydrate 面向数据库绑定（array 编码为
   * JSON 字符串），serialize 面向 API 序列化输出（array 保持数组）。
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 属性值
   * @return mixed 序列化安全的值
   */
  public static function serialize(PropertyMeta $meta, mixed $value): mixed
  {
    if ($value === null) return null;
    return match ($meta->kind) {
      PropertyMeta::KIND_ENUM => $value->value,
      PropertyMeta::KIND_DATETIME => $value->format('Y-m-d H:i:s'),
      default => $value,
    };
  }

  /**
   * array 属性值落库前编码为 JSON 字符串（JSON 列写方向）
   *
   * @param PropertyMeta $meta 属性元数据
   * @param mixed $value 属性值
   * @return string JSON 字符串
   * @throws InvalidArgumentException 值不是数组时抛出
   * @throws RuntimeException JSON 编码失败时抛出
   */
  private static function dehydrateArray(PropertyMeta $meta, mixed $value): string
  {
    if (!is_array($value)) {
      throw self::coerceError($meta, $value);
    }
    try {
      return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      throw new RuntimeException(
        sprintf(
          '实体属性 %s::$%s JSON 编码失败：%s',
          $meta->prop->getDeclaringClass()->getName(),
          $meta->propertyName,
          $e->getMessage()
        )
      );
    }
  }
}
