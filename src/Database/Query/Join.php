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
   * 左连接查询（LEFT JOIN 的快捷方法）
   *
   * @param string $table 关联表名，支持别名（table AS alias）
   * @param string $localKey 主键字段
   * @param string $operator 关联条件运算符
   * @param string $foreignKey 外键字段
   * @return static 支持链式调用
   * @see self::join()
   */
  public function LeftJoin(
    string $table,
    string $localKey,
    string $operator,
    string $foreignKey
  ): static
  {
    return $this->join($table, $localKey, $operator, $foreignKey, 'LEFT');
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
   * @throws InvalidArgumentException 连接类型无效时抛出
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
