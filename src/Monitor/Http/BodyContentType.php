<?php

declare(strict_types=1);

/* 职责：统一正文采集白名单，禁止对非结构化媒体执行试探解析。 */

namespace Viswoole\Monitor\Http;

/** JSON 及 URL 编码表单允许采集；multipart、二进制和未知类型只保留元信息。 */
final class BodyContentType
{
  /**
   * 提取规范化媒体类型
   *
   * @param string $contentType 原始 Content-Type
   * @return string 去参数且小写的媒体类型
   */
  public static function normalize(string $contentType): string
  {
    return strtolower(trim(explode(';', $contentType, 2)[0]));
  }

  /**
   * 判断是否允许解析正文
   *
   * @param string $contentType 原始 Content-Type
   * @return bool 只允许 JSON 及 URL 编码表单
   */
  public static function canCapture(string $contentType): bool
  {
    $type = self::normalize($contentType);
    return $type === 'application/json' || $type === 'application/x-www-form-urlencoded'
      || preg_match('~\Aapplication/[a-z0-9!#$&^_.+-]+\+json\z~D', $type) === 1;
  }

  /**
   * 创建省略正文摘要
   *
   * @param string $contentType 媒体类型
   * @param int|null $bytes 已知实际字节数，未知时为 null，不能把声明长度当实测
   * @return array{content:string,bytes:int,truncated:bool,content_type:string,size_known:bool,omitted_reason:string} 仅元信息
   */
  public static function omitted(string $contentType, ?int $bytes): array
  {
    return [
      'content' => '[UNSTRUCTURED_BODY]', 'bytes' => $bytes ?? 0, 'truncated' => false,
      'content_type' => self::normalize($contentType), 'size_known' => $bytes !== null,
      'omitted_reason' => 'unsupported_content_type',
    ];
  }
}
