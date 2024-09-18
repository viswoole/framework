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

/**
 * 接口状态
 */
enum Status: string
{
  /** 已发布 */
  case PUBLISHED = 'published';
  /** 开发中 */
  case DEVELOPMENT = 'development';
  /** 已废弃 */
  case DEPRECATED = 'deprecated';
  /** 有异常 */
  case ERROR = 'error';
  /** 将废弃 */
  case TO_BE_DEPRECATED = 'to_be_deprecated';
  /** 测试中 */
  case TESTING = 'testing';

  /**
   * 获取中文标签
   *
   * @return string
   */
  public function getLabel(): string
  {
    return match ($this) {
      self::PUBLISHED => '已发布',
      self::DEVELOPMENT => '开发中',
      self::DEPRECATED => '已废弃',
      self::ERROR => '有异常',
      self::TO_BE_DEPRECATED => '将废弃',
      self::TESTING => '测试中',
    };
  }

  /**
   * 获取颜色
   *
   * @return string
   */
  public function getColor(): string
  {
    return match ($this) {
      self::PUBLISHED => '#28a745', // 绿色
      self::DEVELOPMENT => '#17a2b8', // 黄色
      self::DEPRECATED => '#6c757d', // 灰色
      self::ERROR => '#dc3545', // 红色
      self::TO_BE_DEPRECATED => '#e9ecef', // 浅灰色
      self::TESTING => '#ffc107', // 蓝色
    };
  }
}
