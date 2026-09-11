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
 * 文档注释解析工具，提供从 docblock 中提取描述、类型、标题等信息的静态方法
 */
class DocCommentTool
{
  /**
   * 从属性文档注释中提取描述文本
   *
   * 描述支持跨行书写，续行会去除注释前缀后以空格拼接为一段文本
   *
   * @param string $doc 属性文档注释
   * @return string 描述文本
   */
  public static function extractPropertyDoc(string $doc): string
  {
    if (empty($doc)) return $doc;
    if (preg_match(
      '/@var\s+.*?\s+([\s\S]*?)(?=\s*(?:\*\/|\* @))/', $doc, $matches
    )) {
      return self::normalizeDocText($matches[1] ?? '');
    }
    return '';
  }

  /**
   * 从方法文档注释中提取指定参数的类型声明
   *
   * 支持含空格的 PHPStan 复杂类型（如 array{id: int, name: string}），
   * 非贪婪匹配确保类型部分在参数名前截止
   *
   * @param string $docComment 完整的文档注释
   * @param string $param_name 参数名称
   * @return string 类型声明字符串，无类型声明时返回空字符串
   */
  public static function extractParamType(string $docComment, string $param_name): string
  {
    if (empty($docComment)) return '';
    $pattern = '/@param\s+([^\n]*?)\s*\$' . preg_quote($param_name, '/') . '\b/';
    if (preg_match($pattern, $docComment, $matches)) {
      return trim($matches[1]);
    }
    return '';
  }

  /**
   * 从方法文档注释中提取指定参数的描述文本
   *
   * 类型部分使用非贪婪匹配，兼容含空格的 PHPstan 复杂类型（如 array{id: int}）
   * 描述支持跨行书写，续行会去除注释前缀后以空格拼接为一段文本
   *
   * @param string $docComment 完整的文档注释
   * @param string $param_name 参数名称
   * @return string 参数描述文本
   */
  public static function extractParamDoc(string $docComment, string $param_name): string
  {
    if (empty($docComment)) return $docComment;
    if (preg_match(
      '/@param\s+[^\n]*?\s+\$' . preg_quote(
        $param_name, '/'
      ) . '\s+([\s\S]*?)(?=\s*(?:\*\/|\* @))/', $docComment,
      $matches
    )) {
      return self::normalizeDocText($matches[1] ?? '');
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
   * 描述支持跨行书写，续行会去除注释前缀后以空格拼接为一段文本
   *
   * @param string $docComment
   * @param string $tag
   * @return string
   */
  public static function extract(string $docComment, string $tag): string
  {
    if (empty($docComment)) return '';
    $pattern = "/@$tag\s+([\s\S]*?)(?=\s*(?:\*\/|\* @))/";
    if (preg_match($pattern, $docComment, $matches)) {
      return self::normalizeDocText($matches[1] ?? '');
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
   * 标题为首个空行之前的所有内容（支持跨行书写，以空格拼接）；
   * 无空行时标题延伸至首个标签行，此时描述为空
   *
   * @param string $docComment 文档注释
   * @return string 标题文本
   */
  public static function extractDocTitle(string $docComment): string
  {
    [$title] = self::extractTitleAndDescription($docComment);
    return $title;
  }

  /**
   * 提取文档注释中的描述正文
   *
   * 描述为首个空行之后、首个标签之前的内容；标题与描述之间必须以空行分隔，
   * 无空行时视为仅有标题，返回空字符串
   *
   * @param string $docComment 文档注释
   * @return string 描述文本，无正文时返回空字符串
   */
  public static function extractDocDescription(string $docComment): string
  {
    [, $description] = self::extractTitleAndDescription($docComment);
    return $description;
  }

  /**
   * 拆分文档注释中的标题与描述
   *
   * 以首个空行作为分界：空行之前的所有行为标题（空格拼接为一段），
   * 空行之后到首个标签之间的正文为描述（保留换行以维持多段落结构）
   *
   * @param string $docComment 文档注释
   * @return array{0:string,1:string} [标题, 描述]
   */
  private static function extractTitleAndDescription(string $docComment): array
  {
    $lines = self::extractDocLines($docComment);
    // 定位首个空行：空行之前为标题段，之后为描述段
    $blankIndex = array_search('', $lines, true);
    if ($blankIndex === false) {
      // 无空行分隔，整体视为标题，描述为空
      return [implode(' ', $lines), ''];
    }
    return [
      implode(' ', array_slice($lines, 0, $blankIndex)),
      trim(implode("\n", array_slice($lines, $blankIndex + 1))),
    ];
  }

  /**
   * 规范化捕获到的标签描述文本
   *
   * 去除续行的 "*" 注释前缀与对齐空白，过滤空行后以空格拼接，
   * 使跨行书写的描述合并为一段完整文本
   *
   * @param string $text 正则捕获的原始描述文本
   * @return string 规范化后的描述文本
   */
  private static function normalizeDocText(string $text): string
  {
    $lines = [];
    foreach (explode("\n", $text) as $line) {
      // 去掉续行行首的注释星号及其后至多一个空格（首个捕获行本身无前缀，此替换不影响其内容）
      $line = preg_replace('/^\s*\*\s?/', '', $line) ?? $line;
      $line = trim($line);
      if ($line === '') continue;
      $lines[] = $line;
    }
    return implode(' ', $lines);
  }

  /**
   * 提取文档注释中首个标签之前的行列表
   *
   * @param string $docComment 文档注释
   * @return string[] 行列表（已去除注释标记与首尾空行，中间空行保留，作为标题/描述分界）
   */
  private static function extractDocLines(string $docComment): array
  {
    if (empty($docComment)) return [];
    $lines = [];
    foreach (explode("\n", $docComment) as $line) {
      // 去掉注释定界符（/** 与 */）及行首星号
      $text = trim($line);
      $text = preg_replace(['/^\/\*\*/', '/\*\/$/'], '', $text);
      $text = preg_replace('/^\*\s?/', '', $text);
      $text = trim($text);
      // 遇到标签行结束提取
      if ($text !== '' && $text[0] === '@') break;
      $lines[] = $text;
    }
    // 去除首尾空行
    while (!empty($lines) && reset($lines) === '') array_shift($lines);
    while (!empty($lines) && end($lines) === '') array_pop($lines);
    return $lines;
  }
}
