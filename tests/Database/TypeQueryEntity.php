<?php

declare(strict_types=1);

/*
 * 职责：提供实体查询类型回归样例，不承载真实业务数据。
 */

namespace Viswoole\Tests\Database;

use Viswoole\Database\Entity;
use Viswoole\Database\Exception\DbException;

/**
 * 归属查询的最小实体样例。
 */
final class TypeQueryEntity extends Entity
{
  /**
   * @var string 测试表名
   */
  protected string $table = 'type_query';

  /**
   * @var int 记录标识
   */
  public int $id;

  /**
   * @var int 归属标识
   */
  public int $clubId;

  /**
   * 查询指定归属下的记录。
   *
   * 保留原始问题的直接链式返回，禁止通过局部断言掩盖类型丢失。
   *
   * @param int $clubId 归属标识
   * @param int $id 记录标识
   * @return self|null 命中的实体或空值
   * @throws DbException 数据库查询失败时抛出
   */
  public static function findOwned(int $clubId, int $id): ?self
  {
    return self::where('club_id', $clubId)->where('id', $id)->find();
  }

  /**
   * 查询归属记录的首条结果
   *
   * 保留 first 别名的 IDE 复现场景，不添加局部类型断言。
   *
   * @param int $clubId 归属标识
   * @param int $id 记录标识
   * @return self|null 命中实体或空值
   * @throws DbException 数据库查询失败时抛出
   */
  public static function firstOwned(int $clubId, int $id): ?self
  {
    return self::where('club_id', $clubId)->where('id', $id)->first();
  }

  /**
   * 通过静态魔术入口读取实体
   *
   * 静态直调同样不能把合法实体返回误报为类型不兼容。
   *
   * @param int $id 记录标识
   * @return self|null 命中实体或空值
   * @throws DbException 数据库查询失败时抛出
   */
  public static function findDirect(int $id): ?self
  {
    return self::find($id);
  }

}
