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

namespace Viswoole\Database\Model;

/**
 * 关联查询
 */
class RelateQuery
{
  /**
   * @param string $relateModel 关联的模型
   * @param string $foreignKey 关联外键
   * @param string $localKey 当前模型主键
   * @param string|null $pivotModel 中间表模型，默认为null，表示不使用中间表
   * @param bool $many 是否对多关联，默认为false，表示一对一关联
   */
  public function __construct(
    protected string  $relateModel,
    protected string  $foreignKey,
    protected string  $localKey,
    protected ?string $pivotModel = null,
    protected bool    $many = false
  )
  {
  }
}
