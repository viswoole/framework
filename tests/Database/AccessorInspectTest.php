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

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;

/**
 * 获取器拼写自检回归测试（P1-10）
 *
 * 修复前缺陷：get{Field}Attr 按蛇形转驼峰精确匹配方法名，拼写错误（字段
 * image_key 误写 getImageAttr）静默直出原始值，无任何提示——业务侧真实踩坑。
 *
 * 修复语义：debug 模式下按模型类一次性自检——「模型定义的获取器方法未对应
 * 本次结果集的任何字段」时输出核对提示（生产 isDebug 短路零开销）；
 * 正确命中的获取器与「字段无获取器」的常态不受影响。
 *
 * ⚠️ 自检去重是进程级静态状态：需要触发提示的用例各自使用独立模型类，
 * 避免用例间标记污染。
 */
class AccessorInspectTest extends TestCase
{
  use ProvidesSqliteStatement;

  private function makeManager(FakeChannel $fake): DbManager
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $fake);
    $manager->setDebug(false);
    return $manager;
  }

  /**
   * 查询单行并以 debug 模式 toArray，返回捕获的控制台输出
   */
  private function queryAndToArray(string $modelClass, bool $debug): string
  {
    $fake = new FakeChannel($this->makeSelectStatement());
    $this->makeManager($fake);
    App::factory()->setDebug($debug);
    ob_start();
    try {
      /** @var DataSet $row */
      $row = $modelClass::where('id', 1)->first();
      $row->toArray();
    } finally {
      $out = ob_get_clean();
      App::factory()->setDebug(false);
    }
    return $out;
  }

  /**
   * 拼写错误的获取器应在 debug 模式输出核对提示，正确获取器正常应用
   */
  public function testAccessorTypoHintedInDebug(): void
  {
    $out = $this->queryAndToArray(AccessorTypoModel::class, true);

    self::assertStringContainsString('getNameTextAttr', $out);
    self::assertStringContainsString('未对应', $out);
  }

  /**
   * 拼写错误的获取器本身不影响正确获取器的应用
   */
  public function testCorrectAccessorStillApplied(): void
  {
    $fake = new FakeChannel($this->makeSelectStatement());
    $this->makeManager($fake);

    $row = AccessorTypoModel::where('id', 1)->first();
    $data = $row->toArray();

    self::assertSame('A', $data['name'], 'getNameAttr 应正常将 name 转大写');
  }

  /**
   * 全部正确命中的模型不应产生任何提示
   */
  public function testCorrectOnlyAccessorNotHinted(): void
  {
    $out = $this->queryAndToArray(AccessorCorrectModel::class, true);

    self::assertStringNotContainsString('未对应', $out);
  }

  /**
   * 同一模型类的提示仅输出一次（进程级去重）
   */
  public function testHintOnlyOncePerModelClass(): void
  {
    $fake = new FakeChannel($this->makeSelectStatement());
    $this->makeManager($fake);
    App::factory()->setDebug(true);

    $count = 0;
    try {
      for ($i = 0; $i < 2; $i++) {
        ob_start();
        AccessorTypoOnceModel::where('id', 1)->first()->toArray();
        $out = ob_get_clean();
        if (str_contains($out, '未对应')) $count++;
      }
    } finally {
      App::factory()->setDebug(false);
    }
    self::assertSame(1, $count, '同一模型类仅应提示一次');
  }

  /**
   * 非 debug 模式零输出（生产零开销）
   */
  public function testSilentWhenNotDebug(): void
  {
    $out = $this->queryAndToArray(AccessorTypoSilentModel::class, false);

    self::assertSame('', $out);
  }
}

/**
 * 测试用模型：含拼写错误的获取器（nameText 字段不存在）+ 正确获取器
 */
class AccessorTypoModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  public function getNameTextAttr(mixed $value): string
  {
    return 'T:' . $value;
  }

  public function getNameAttr(mixed $value): string
  {
    return strtoupper((string)$value);
  }
}

/**
 * 测试用模型：仅正确命中的获取器
 */
class AccessorCorrectModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  public function getNameAttr(mixed $value): string
  {
    return strtoupper((string)$value);
  }
}

/**
 * 测试用模型：拼写错误获取器（仅一次提示用例专用）
 */
class AccessorTypoOnceModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  public function getUidAttr(mixed $value): mixed
  {
    return $value;
  }
}

/**
 * 测试用模型：拼写错误获取器（静默用例专用）
 */
class AccessorTypoSilentModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  public function getUidAttr(mixed $value): mixed
  {
    return $value;
  }
}
