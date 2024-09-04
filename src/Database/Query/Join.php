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
 * Join联表查询
 */
trait Join
{
  /**
   * 关联查询（LEFT）
   *
   * @param string $table 要关联的表
   * @param string $localKey 主键
   * @param string $operator 关联条件运算符表达式
   * @param string $foreignKey 外键
   * @return static
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
   * 关联查询
   *
   * ```
   * $query->table('user')->join('user_info', 'user.id', 'user_info.uid');
   * // 设置别名
   * $query->table('user','u')->join('user_info as info', 'u.id', 'info.uid');
   * ```
   *
   * @param string $table 要关联的表
   * @param string $localKey 主键
   * @param string $foreignKey 外键
   * @param string $operator 关联条件运算符表达式，默认为等号
   * @param string $type ['INNER', 'LEFT', 'RIGHT', 'FULL']，不区分大小写
   * @return static
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
   * 关联查询（FULL）
   *
   * @param string $table 要关联的表
   * @param string $localKey 主键
   * @param string $foreignKey 外键
   * @param string $operator 关联条件运算符表达式，默认为等号
   * @return static
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
