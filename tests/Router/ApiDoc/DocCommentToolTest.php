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

namespace Viswoole\Tests\Router\ApiDoc;

use PHPUnit\Framework\TestCase;
use Viswoole\Router\ApiDoc\DocCommentTool;

/**
 * 文档注释解析工具测试
 *
 * 重点覆盖标签描述的跨行收集：续行去除注释前缀后以空格拼接为一段文本，
 * 同时回归单行描述与多参数场景下描述的归属边界
 */
class DocCommentToolTest extends TestCase
{
  /**
   * 单行描述回归：类型与描述在同一行，普通与 PHPStan 复杂类型均可正确提取
   *
   * @return void
   */
  public function testExtractParamDocSingleLine(): void
  {
    $doc = <<<'DOC'
/**
 * @param int $id 用户ID
 * @param array{id: int, name: string} $user 复杂类型参数描述
 */
DOC;
    $this->assertSame('用户ID', DocCommentTool::extractParamDoc($doc, 'id'));
    $this->assertSame('复杂类型参数描述', DocCommentTool::extractParamDoc($doc, 'user'));
  }

  /**
   * 跨行描述：续行紧随其后且以 docblock 结束，应以空格拼接为一段文本
   *
   * @return void
   */
  public function testExtractParamDocMultiLine(): void
  {
    $doc = <<<'DOC'
/**
 * @param array<int,string> $ids 等级雪花 ID 列表
 *                              （数字串，按新顺序排列：首元素=最高档）
 */
DOC;
    $this->assertSame(
      '等级雪花 ID 列表 （数字串，按新顺序排列：首元素=最高档）',
      DocCommentTool::extractParamDoc($doc, 'ids')
    );
  }

  /**
   * 跨行描述后跟随其它标签：描述应止于下一个标签行，且不影响后续参数的提取
   *
   * @return void
   */
  public function testExtractParamDocMultiLineBeforeNextTag(): void
  {
    $doc = <<<'DOC'
/**
 * @param array<int,string> $ids 等级雪花 ID 列表
 *                              （数字串，按新顺序排列：首元素=最高档）
 * @param int $level 等级
 */
DOC;
    $this->assertSame(
      '等级雪花 ID 列表 （数字串，按新顺序排列：首元素=最高档）',
      DocCommentTool::extractParamDoc($doc, 'ids')
    );
    $this->assertSame('等级', DocCommentTool::extractParamDoc($doc, 'level'));
  }

  /**
   * 跨行描述中存在空的 "*" 行：空行应被过滤，不产生多余空白
   *
   * @return void
   */
  public function testExtractParamDocMultiLineWithBlankStarLine(): void
  {
    $doc = <<<'DOC'
/**
 * @param int $id 第一段描述
 *
 * 第二段补充说明
 */
DOC;
    $this->assertSame(
      '第一段描述 第二段补充说明',
      DocCommentTool::extractParamDoc($doc, 'id')
    );
  }

  /**
   * 参数无描述或参数名不在注释中时应返回空字符串
   *
   * @return void
   */
  public function testExtractParamDocWithoutDescription(): void
  {
    $doc = <<<'DOC'
/**
 * @param int $id
 */
DOC;
    $this->assertSame('', DocCommentTool::extractParamDoc($doc, 'id'));
    $this->assertSame('', DocCommentTool::extractParamDoc($doc, 'not_exists'));
  }

  /**
   * 属性 @\var 描述跨行收集
   *
   * @return void
   */
  public function testExtractPropertyDocMultiLine(): void
  {
    $doc = <<<'DOC'
/**
 * @var array<string,int> 统计表
 *                        键为名称，值为次数
 */
DOC;
    $this->assertSame(
      '统计表 键为名称，值为次数',
      DocCommentTool::extractPropertyDoc($doc)
    );
  }

  /**
   * 通用标签描述跨行收集
   *
   * @return void
   */
  public function testExtractTagMultiLine(): void
  {
    $doc = <<<'DOC'
/**
 * @date 2026-09-12
 *        更新于当日
 */
DOC;
    $this->assertSame(
      '2026-09-12 更新于当日',
      DocCommentTool::extract($doc, 'date')
    );
  }

  /**
   * 标题跨行：空行之前的所有行均为标题（空格拼接），空行之后为描述
   *
   * @return void
   */
  public function testExtractDocTitleMultiLine(): void
  {
    $doc = <<<'DOC'
/**
 * 审核通过（单事务：乐观流转 → 锁读重读申请行 → 创建俱乐部 → 所有者写管理员
 * 成员 → club_id 回填）
 *
 * 资料自申请迁入（logo/name/intro/license_image/credit_code/等级/计费周期）；
 * expire_time 按计费周期自通过时刻起算；license_name 无申请来源，空待补录。
 *
 * @param int $applyId 申请雪花 ID
 */
DOC;
    $this->assertSame(
      '审核通过（单事务：乐观流转 → 锁读重读申请行 → 创建俱乐部 → 所有者写管理员 成员 → club_id 回填）',
      DocCommentTool::extractDocTitle($doc)
    );
    $this->assertSame(
      "资料自申请迁入（logo/name/intro/license_image/credit_code/等级/计费周期）；\n"
      . 'expire_time 按计费周期自通过时刻起算；license_name 无申请来源，空待补录。',
      DocCommentTool::extractDocDescription($doc)
    );
  }

  /**
   * 标题与描述以空行分隔的常规场景回归
   *
   * @return void
   */
  public function testExtractDocTitleWithBlankLineSeparatedDescription(): void
  {
    $doc = <<<'DOC'
/**
 * 用户登录
 *
 * 支持手机号与邮箱登录
 */
DOC;
    $this->assertSame('用户登录', DocCommentTool::extractDocTitle($doc));
    $this->assertSame('支持手机号与邮箱登录', DocCommentTool::extractDocDescription($doc));
  }

  /**
   * 无空行分隔时标题延伸至首个标签行，描述为空
   *
   * @return void
   */
  public function testExtractDocTitleWithoutBlankLine(): void
  {
    $doc = <<<'DOC'
/**
 * 用户登录
 * 支持手机号与邮箱登录
 * @param string $account 账号
 */
DOC;
    $this->assertSame(
      '用户登录 支持手机号与邮箱登录',
      DocCommentTool::extractDocTitle($doc)
    );
    $this->assertSame('', DocCommentTool::extractDocDescription($doc));
  }

  /**
   * 描述内含多个空行时保留多段落结构
   *
   * @return void
   */
  public function testExtractDocDescriptionKeepsParagraphs(): void
  {
    $doc = <<<'DOC'
/**
 * 用户登录
 *
 * 第一段描述
 *
 * 第二段描述
 */
DOC;
    $this->assertSame(
      "第一段描述\n\n第二段描述",
      DocCommentTool::extractDocDescription($doc)
    );
  }

  /**
   * 仅有标题无空行、无描述时返回空描述
   *
   * @return void
   */
  public function testExtractDocTitleOnly(): void
  {
    $doc = <<<'DOC'
/**
 * 用户登录
 */
DOC;
    $this->assertSame('用户登录', DocCommentTool::extractDocTitle($doc));
    $this->assertSame('', DocCommentTool::extractDocDescription($doc));
    $this->assertSame('', DocCommentTool::extractDocTitle(''));
  }
}
