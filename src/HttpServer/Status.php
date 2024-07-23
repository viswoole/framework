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

namespace Viswoole\HttpServer;
/**
 * 状态码
 */
final class Status
{
  /** 服务器已收到请求的一部分，客户端应该继续发送其余的请求。 */
  public const int CONTINUE = 100;

  /** 服务器已经理解了客户端的请求，并将通过Upgrade消息头通知客户端采用不同的协议来完成。 */
  public const int SWITCHING_PROTOCOLS = 101;

  /** 服务器正在处理请求，并且有一个稍后地响应。 */
  public const int PROCESSING = 102;

  /** 请求成功，内容已被返回。 */
  public const int OK = 200;

  /** 请求已经被满足，并且有一个新的资源已经创建。 */
  public const int CREATED = 201;

  /** 请求已经被接受处理，但是还没有完成。 */
  public const int ACCEPTED = 202;

  /** 服务器已经成功处理了请求，但返回的信息可能来自另一来源。 */
  public const int NON_AUTHORITATIVE_INFORMATION = 203;

  /** 请求成功处理，但没有返回任何内容。 */
  public const int NO_CONTENT = 204;

  /** 请求处理完成，浏览器可以重置使用的表单。 */
  public const int RESET_CONTENT = 205;

  /** 请求成功处理了一部分内容。 */
  public const int PARTIAL_CONTENT = 206;

  /** 代表了对多个资源的操作的响应。 */
  public const int MULTI_STATUS = 207;

  /** 这个状态码表示已经在之前的响应中报告过，不再重复报告。 */
  public const int ALREADY_REPORTED = 208;

  /** 服务器已经满足了对资源的请求，可能是一个或多个实例。 */
  public const int IM_USED = 226;

  /** 客户端请求的资源有多种表示形式。 */
  public const int MULTIPLE_CHOICES = 300;

  /** 所请求的页面已经永久移动到新位置。 */
  public const int MOVED_PERMANENTLY = 301;

  /** 所请求的页面暂时移动到了新位置。 */
  public const int FOUND = 302;

  /** 服务器返回此响应以便让客户端通过GET请求重定向到另一个URL。 */
  public const int SEE_OTHER = 303;

  /** 资源未修改，可以使用缓存的版本。 */
  public const int NOT_MODIFIED = 304;

  /** 请求必须通过指定的代理才能被处理。 */
  public const int USE_PROXY = 305;

  /** 该状态码已经不再被使用，但是代码依然可用。 */
  public const int SWITCH_PROXY = 306;

  /** 请求的资源现在临时从不同的URI响应请求。 */
  public const int TEMPORARY_REDIRECT = 307;

  /** 请求的资源现在永久从不同的URI响应请求。 */
  public const int PERMANENT_REDIRECT = 308;

  /** 服务器不理解请求的语法。 */
  public const int BAD_REQUEST = 400;

  /** 请求要求用户的身份认证。 */
  public const int UNAUTHORIZED = 401;

  /** 保留，将来使用。 */
  public const int PAYMENT_REQUIRED = 402;

  /** 服务器拒绝请求。 */
  public const int FORBIDDEN = 403;

  /** 请求的资源不存在。 */
  public const int NOT_FOUND = 404;

  /** 请求中指定的方法不被允许。 */
  public const int METHOD_NOT_ALLOWED = 405;

  /** 服务器生成的响应无法被客户端所接受。 */
  public const int NOT_ACCEPTABLE = 406;

  /** 客户端必须通过代理进行身份验证。 */
  public const int PROXY_AUTHENTICATION_REQUIRED = 407;

  /** 请求超时。 */
  public const int REQUEST_TIME_OUT = 408;

  /** 请求导致了资源的冲突。 */
  public const int CONFLICT = 409;

  /** 所请求的资源已经不存在。 */
  public const int GONE = 410;

  /** 缺少Content-Length头部。 */
  public const int LENGTH_REQUIRED = 411;

  /** 请求头中指定的前提条件被服务器评估为false。 */
  public const int PRECONDITION_FAILED = 412;

  /** 请求体过大。 */
  public const int REQUEST_ENTITY_TOO_LARGE = 413;

  /** 请求的URI过长。 */
  public const int REQUEST_URI_TOO_LARGE = 414;

  /** 请求的媒体类型不受支持。 */
  public const int UNSUPPORTED_MEDIA_TYPE = 415;

  /** 服务器不能满足请求中的Range头的要求。 */
  public const int REQUESTED_RANGE_NOT_SATISFIABLE = 416;

  /** 服务器未能满足Expect请求头字段的期望值。 */
  public const int EXPECTATION_FAILED = 417;

  /** 请求被发送到错误的服务器。 */
  public const int MISDIRECTED_REQUEST = 421;

  /** 请求格式正确，但是由于语义错误无法被处理。 */
  public const int UNPROCESSABLE_ENTITY = 422;

  /** 资源被锁定。 */
  public const int LOCKED = 423;

  /** 请求失败，因为它依赖于另一个请求，而这个请求失败。 */
  public const int FAILED_DEPENDENCY = 424;

  /** 服务器不会执行排序集合的排序。 */
  public const int UNORDERED_COLLECTION = 425;

  /** 客户端应该切换到TLS/1.0。 */
  public const int UPGRADE_REQUIRED = 426;

  /** 请求缺少必需的前提条件。 */
  public const int PRECONDITION_REQUIRED = 428;

  /** 客户端发送的请求太多。 */
  public const int TOO_MANY_REQUESTS = 429;

  /** 服务器拒绝处理请求因为请求头字段太大。 */
  public const int REQUEST_HEADER_FIELDS_TOO_LARGE = 431;

  /** 由于法律原因，该请求被拒绝。 */
  public const int UNAVAILABLE_FOR_LEGAL_REASONS = 451;

  /** 服务器内部错误。 */
  public const int INTERNAL_SERVER_ERROR = 500;

  /** 请求的功能尚未实现。 */
  public const int NOT_IMPLEMENTED = 501;

  /** 服务器作为网关或代理，从上游服务器接收到了无效的响应。 */
  public const int BAD_GATEWAY = 502;

  /** 服务器目前无法处理请求。 */
  public const int SERVICE_UNAVAILABLE = 503;

  /** 作为网关或代理的服务器在等待上游服务器的响应时超时。 */
  public const int GATEWAY_TIME_OUT = 504;

  /** 服务器不支持请求中所使用的HTTP协议版本。 */
  public const int HTTP_VERSION_NOT_SUPPORTED = 505;

  /** 服务器有一个内部配置错误，导致处理请求时发生递归。 */
  public const int VARIANT_ALSO_NEGOTIATES = 506;

  /** 存储空间不足 */
  public const int INSUFFICIENT_STORAGE = 507;

  /** 服务器进入死循环 */
  public const int LOOP_DETECTED = 508;

  /** 未扩展。 */
  public const int NOT_EXTENDED = 510;

  /** 需要网络身份认证 */
  public const int NETWORK_AUTHENTICATION_REQUIRED = 511;

  public const array REASON_PHRASES = [
    self::CONTINUE => 'Continue',
    self::SWITCHING_PROTOCOLS => 'Switching Protocols',
    self::PROCESSING => 'Processing',
    self::OK => 'OK',
    self::CREATED => 'Created',
    self::ACCEPTED => 'Accepted',
    self::NON_AUTHORITATIVE_INFORMATION => 'Non-Authoritative Information',
    self::NO_CONTENT => 'No Content',
    self::RESET_CONTENT => 'Reset Content',
    self::PARTIAL_CONTENT => 'Partial Content',
    self::MULTI_STATUS => 'Multi-status',
    self::ALREADY_REPORTED => 'Already Reported',
    self::IM_USED => 'IM Used',
    self::MULTIPLE_CHOICES => 'Multiple Choices',
    self::MOVED_PERMANENTLY => 'Moved Permanently',
    self::FOUND => 'Found',
    self::SEE_OTHER => 'See Other',
    self::NOT_MODIFIED => 'Not Modified',
    self::USE_PROXY => 'Use Proxy',
    self::SWITCH_PROXY => 'Switch Proxy',
    self::TEMPORARY_REDIRECT => 'Temporary Redirect',
    self::PERMANENT_REDIRECT => 'Permanent Redirect',
    self::BAD_REQUEST => 'Bad Request',
    self::UNAUTHORIZED => 'Unauthorized',
    self::PAYMENT_REQUIRED => 'Payment Required',
    self::FORBIDDEN => 'Forbidden',
    self::NOT_FOUND => 'Not Found',
    self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
    self::NOT_ACCEPTABLE => 'Not Acceptable',
    self::PROXY_AUTHENTICATION_REQUIRED => 'Proxy Authentication Required',
    self::REQUEST_TIME_OUT => 'Request Time-out',
    self::CONFLICT => 'Conflict',
    self::GONE => 'Gone',
    self::LENGTH_REQUIRED => 'Length Required',
    self::PRECONDITION_FAILED => 'Precondition Failed',
    self::REQUEST_ENTITY_TOO_LARGE => 'Request Entity Too Large',
    self::REQUEST_URI_TOO_LARGE => 'Request-URI Too Large',
    self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
    self::REQUESTED_RANGE_NOT_SATISFIABLE => 'Requested range not satisfiable',
    self::EXPECTATION_FAILED => 'Expectation Failed',
    self::MISDIRECTED_REQUEST => 'Misdirected Request',
    self::UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
    self::LOCKED => 'Locked',
    self::FAILED_DEPENDENCY => 'Failed Dependency',
    self::UNORDERED_COLLECTION => 'Unordered Collection',
    self::UPGRADE_REQUIRED => 'Upgrade Required',
    self::PRECONDITION_REQUIRED => 'Precondition Required',
    self::TOO_MANY_REQUESTS => 'Too Many Requests',
    self::REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large',
    self::UNAVAILABLE_FOR_LEGAL_REASONS => 'Unavailable For Legal Reasons',
    self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
    self::NOT_IMPLEMENTED => 'Not Implemented',
    self::BAD_GATEWAY => 'Bad Gateway',
    self::SERVICE_UNAVAILABLE => 'Service Unavailable',
    self::GATEWAY_TIME_OUT => 'Gateway Time-out',
    self::HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version not supported',
    self::VARIANT_ALSO_NEGOTIATES => 'Variant Also Negotiates',
    self::INSUFFICIENT_STORAGE => 'Insufficient Storage',
    self::LOOP_DETECTED => 'Loop Detected',
    self::NOT_EXTENDED => 'Not Extended',
    self::NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required',
  ];

  /**
   * 获取全部原因短语
   *
   * @access public
   * @return array|string[]
   */
  public static function getReasonPhrases(): array
  {
    return Status::REASON_PHRASES;
  }

  /**
   * 检索原因短语
   *
   * @access public
   * @param int $code
   * @return string
   */
  public static function getReasonPhrase(int $code): string
  {
    return Status::REASON_PHRASES[$code] ?? 'Unknown';
  }
}
