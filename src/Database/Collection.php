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

namespace Viswoole\Database;

use ArrayObject;
use Viswoole\Database\Query\Options;

/**
 * 数据集合
 */
class Collection extends ArrayObject
{
  /**
   * @param Channel $channel 数据库通道
   * @param Options $options 查询配置
   * @param array $data 查询结果
   */
  public function __construct(
    protected Channel       $channel,
    public readonly Options $options,
    array                   $data
  )
  {
    parent::__construct($data);
  }

  /**
   * @param string $name
   * @return mixed|null
   */
  public function __get(string $name)
  {
    return $this->offsetGet($name);
  }

  /**
   * @param string $name
   * @param $value
   * @return void
   */
  public function __set(string $name, $value): void
  {
    $this->offsetSet($name, $value);
  }
}
