<?php

declare(strict_types=1);

/* 职责：声明隐私规则的明确作用域，不通过业务字段名推测容器。 */

namespace Viswoole\Monitor\Privacy;

/** 采集器内部使用的方向与分组标识。 */
enum Target: string
{
  case REQUEST_BODY = 'request.body';
  case REQUEST_QUERY = 'request.query';
  case REQUEST_HEADERS = 'request.headers';
  case REQUEST_METADATA = 'request.metadata';
  case RESPONSE_BODY = 'response.body';
  case RESPONSE_HEADERS = 'response.headers';
  case RESPONSE_METADATA = 'response.metadata';
  case OPERATION_METADATA = 'operation.metadata';
}
