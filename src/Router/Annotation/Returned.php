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

namespace Viswoole\Router\Annotation;

use Attribute;
use JsonSerializable;
use Override;

/**
 * 返回值注解
 *
 * 遵循 OpenAPI 规范
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Returned implements JsonSerializable
{
  /** json */
  const string TYPE_JSON = 'application/json';
  /** XML */
  const string TYPE_XML = 'application/xml';
  /** html */
  const string TYPE_HTML = 'text/html';
  /** 纯文本 */
  const string TYPE_TEXT = 'text/plain';
  /** 二进制流 */
  const string TYPE_STREAM = 'application/octet-stream';

  /**
   * @param string $title 标题
   * @param mixed $data 响应数据
   * @param int $statusCode 状态码，默认为200
   * @param string $type 响应类型，默认为application/json
   */
  public function __construct(
    public string $title,
    public mixed  $data,
    public int    $statusCode = 200,
    public string $type = self::TYPE_JSON
  )
  {

  }

  /**
   * @inheritDoc
   */
  #[Override] public function jsonSerialize(): array
  {
    return [
      'title' => $this->title,
      'data' => $this->data,
      'statusCode' => $this->statusCode,
      'type' => $this->type,
    ];
  }
}
