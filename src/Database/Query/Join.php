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

namespace Viswoole\Database\Query;

use InvalidArgumentException;

/**
 * JOIN 联表查询 Trait
 *
 * 提供 INNER、LEFT、RIGHT、FULL 四种联表方式，
 * 支持表别名和自定义关联条件运算符。
 */
trait Join
{
  /**
   * @var array 关联条件支持的比较运算符白名单
   *
   * 取 Where::OPERATORS 的比较子集——IS NULL/IN/BETWEEN 等一元或集合运算符
   * 对 ON 条件无意义；独立声明而非跨 trait 引用 self::OPERATORS，
   * 避免 Join 对 Where 组合顺序的隐式依赖
   */
  private const array JOIN_OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE'];

  /**
   * 左连接查询（LEFT JOIN 的快捷方法）
   *
   * @param string $table 关联表名，支持别名（table AS alias）
   * @param string $localKey 主键字段
   * @param string $foreignKey 外键字段
   * @param string $operator 关联条件运算符，默认 '='
   * @return static 支持链式调用
   * @throws InvalidArgumentException 旧参数顺序调用或运算符无效时抛出
   * @see self::join()
   */
  public function leftJoin(
    string $table,
    string $localKey,
    string $foreignKey,
    string $operator = '='
  ): static
  {
    // 兼容旧版参数顺序 (table, localKey, operator, foreignKey)：
    // 旧顺序调用在本签名下会把运算符传入 $foreignKey 并静默生成坏 SQL，
    // 此处显式检测并抛出迁移提示，避免无声破坏（方法名大小写不敏感，
    // 旧代码的 LeftJoin 调用同样会进入本方法被拦截）
    if (in_array($foreignKey, self::JOIN_OPERATORS, true)) {
      throw new InvalidArgumentException(
        "leftJoin 参数顺序已变更为 (table, localKey, foreignKey, operator)，检测到旧顺序调用：第 3 参数收到运算符 \"$foreignKey\""
      );
    }
    return $this->join($table, $localKey, $foreignKey, $operator, 'LEFT');
  }

  /**
   * 添加 JOIN 联表查询
   *
   * @param string $table 关联表名，支持别名（table AS alias）
   * @param string $localKey 主键字段
   * @param string $foreignKey 外键字段
   * @param string $operator 关联条件运算符，默认 '='
   * @param string $type 连接类型 INNER|LEFT|RIGHT|FULL，默认 INNER
   * @return static 支持链式调用
   * @throws InvalidArgumentException 连接类型或运算符无效时抛出
   */
  public function join(
    string $table,
    string $localKey,
    string $foreignKey,
    string $operator = '=',
    string $type = 'INNER'
  ): static
  {
    $type = strtoupper($type);
    $typeArr = ['INNER', 'LEFT', 'RIGHT', 'FULL'];
    if (!in_array($type, $typeArr)) {
      throw new InvalidArgumentException('关联查询type错误，请使用：INNER, LEFT, RIGHT, FULL 之一');
    }
    // 与 where() 的 operator 白名单保持同等防御：operator 会被 SqlBuilder 原样
    // 拼接进 ON 条件，不校验会留下注入面（SqlBuilder 对 join 的表名/字段做了
    // quote，operator 是唯一未经处理的插槽）
    if (!in_array($operator, self::JOIN_OPERATORS, true)) {
      throw new InvalidArgumentException("无效的关联条件运算符：$operator");
    }
    $this->options->join[] = [
      'table' => $table,
      'localKey' => $localKey,
      'operator' => $operator,
      'foreignKey' => $foreignKey,
      'type' => $type
    ];
    return $this;
  }

  /**
   * 关联查询（RIGHT）
   *
   * @param string $table 要关联的表
   * @param string $localKey 主键
   * @param string $foreignKey 外键
   * @param string $operator 关联条件运算符表达式，默认为等号
   * @return static
   * @see self::join()
   */
  public function rightJoin(
    string $table,
    string $localKey,
    string $foreignKey,
    string $operator = '='
  ): static
  {
    return $this->join($table, $localKey, $foreignKey, $operator, 'RIGHT');
  }

  /**
   * 全连接查询（FULL JOIN 的快捷方法）
   *
   * @param string $table 关联表名，支持别名（table AS alias）
   * @param string $localKey 主键字段
   * @param string $foreignKey 外键字段
   * @param string $operator 关联条件运算符，默认 '='
   * @return static 支持链式调用
   * @see self::join()
   */
  public function fullJoin(
    string $table,
    string $localKey,
    string $foreignKey,
    string $operator = '='
  ): static
  {
    return $this->join($table, $localKey, $foreignKey, $operator, 'FULL');
  }
}
