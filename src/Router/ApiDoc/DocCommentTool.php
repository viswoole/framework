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
 * 公共类
 */
class DocCommentTool
{
  /**
   * 从注释文档中提取到属性说明
   *
   * @param string $doc 属性doc
   * @return string
   */
  public static function extractPropertyDoc(string $doc): string
  {
    if (empty($doc)) return $doc;
    if (preg_match(
      '/@var\s+.*?\s+([\s\S]*?)(?=\s*(?:\r\n|\r|\n|\* @))/', $doc,
      $matches
    )) {
      $doc = $matches[1] ?? '';
      return trim($doc);
    }
    return '';
  }

  /**
   * 从注释文档中提取到参数说明
   *
   * @param string $docComment 完整的doc注释
   * @param string $param_name 参数名称
   * @return string
   */
  public static function extractParamDoc(string $docComment, string $param_name): string
  {
    if (empty($docComment)) return $docComment;
    if (preg_match(
      '/@param\s+\S+\s+\$' . preg_quote(
        $param_name, '/'
      ) . '\s+([\s\S]*?)(?=\s*(?:\r\n|\r|\n|\* @))/', $docComment,
      $matches
    )) {
      $doc = $matches[1] ?? '';
      return trim($doc);
    }
    return '';
  }

  /**
   * 提取文档注释中的作者
   *
   * @param string $docComment
   * @return string
   */
  public static function extractAuthor(string $docComment): string
  {
    return self::extract($docComment, 'author');
  }

  /**
   * 提取任意标签描述部分
   *
   * @param string $docComment
   * @param string $tag
   * @return string
   */
  public static function extract(string $docComment, string $tag): string
  {
    if (empty($docComment)) return '';
    $pattern = "/@$tag\s+([\s\S]*?)(?=\s*(?:\r\n|\r|\n|\* @))/";
    if (preg_match($pattern, $docComment, $matches)) {
      $doc = $matches[1] ?? '';
      return trim($doc);
    }
    return '';
  }

  /**
   * 提取时间
   *
   * @param string $docComment
   * @return string
   */
  public static function extractDate(string $docComment): string
  {
    return self::extract($docComment, 'date');
  }

  /**
   * 提取文档注释中的标题
   *
   * @param string $docComment
   * @return string
   */
  public static function extractDocTitle(string $docComment): string
  {
    if (empty($docComment)) return '';
    $title = '';
    if (preg_match('/^\s+\*\s+([^@\n][^\n]*)$/m', $docComment, $matches)) {
      $title = trim($matches[1]);
    }
    return $title;
  }
}
