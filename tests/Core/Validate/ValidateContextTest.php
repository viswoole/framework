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

declare(strict_types=1);

namespace Viswoole\Tests\Core\Validate;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate;
use Viswoole\Core\Validate\BaseValidateRule;
use Viswoole\Core\Validate\Rules\Length;
use Viswoole\Core\Validate\Rules\Mobile;

/**
 * 校验异常参数名占位符测试
 *
 * 覆盖 Validate::withContext 与 Validate::checkRules 的 {:name} 占位符
 * 替换逻辑：规则错误消息通过占位符引用参数名，未使用占位符的
 * 自定义文案保持原样
 */
class ValidateContextTest extends TestCase
{
  /**
   * 测试 withContext 替换占位符为参数名并保留原异常错误码
   *
   * @return void
   */
  public function testWithContextPreservesCode(): void
  {
    $e = new ValidateException('{:name} 长度不符合要求', 990101);
    $wrapped = Validate::withContext($e, 'nickname');
    static::assertSame(990101, $wrapped->getCode());
    static::assertSame('$nickname 长度不符合要求', $wrapped->getMessage());
  }

  /**
   * 测试 withContext 占位符可出现多次并全部替换
   *
   * @return void
   */
  public function testWithContextMultiplePlaceholders(): void
  {
    $e = new ValidateException('{:name} 与 {:name} 均不符合要求');
    $wrapped = Validate::withContext($e, 'phone');
    static::assertSame('$phone 与 $phone 均不符合要求', $wrapped->getMessage());
  }

  /**
   * 测试 withContext 对多条错误逐条替换占位符且保留数组结构
   *
   * 未使用占位符的条目保持原样
   *
   * @return void
   */
  public function testWithContextWithArrayError(): void
  {
    $e = new ValidateException(['{:name} 长度必须在 1 到 64 之间', '格式错误']);
    $wrapped = Validate::withContext($e, 'nickname');
    static::assertSame(
      ['$nickname 长度必须在 1 到 64 之间', '格式错误'],
      $wrapped->getError()
    );
  }

  /**
   * 测试 withContext 在缺失参数名时的行为
   *
   * 未使用占位符的消息原样返回；使用占位符的消息在参数名缺失时
   * 占位符替换为空串并去除行首残留空白
   *
   * @return void
   */
  public function testWithContextWithoutName(): void
  {
    // 无占位符：各种缺失场景均原样返回
    $e = new ValidateException('验证失败');
    static::assertSame($e, Validate::withContext($e));
    static::assertSame($e, Validate::withContext($e, 123));
    static::assertSame($e, Validate::withContext($e, ''));
    // 有占位符且参数名缺失：占位符替换为空串，去除行首空白
    $placeholder = new ValidateException('{:name} 验证失败');
    static::assertSame('验证失败', Validate::withContext($placeholder)->getMessage());
    static::assertSame(
      '验证失败',
      Validate::withContext(new ValidateException('{:name} 验证失败'), '')->getMessage()
    );
  }

  /**
   * 测试 checkRules 内置规则失败时错误信息附带参数名
   *
   * @return void
   */
  public function testCheckRulesAttachesParamName(): void
  {
    $rule = new Length(min: 1, max: 5);
    try {
      Validate::checkRules($rule, '超长超长超长超长', 'nickname');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertStringContainsString('$nickname', $e->getMessage());
      static::assertStringContainsString('长度必须在 1 到 5 之间', $e->getMessage());
    }
  }

  /**
   * 测试 checkRules 规则通过时正常返回转换后的值
   *
   * @return void
   */
  public function testCheckRulesReturnsTransformedValue(): void
  {
    $rule = new Length(min: 1, max: 5);
    // Length 规则会 trim 字符串，验证校验链未受异常包装逻辑影响
    $result = Validate::checkRules($rule, '  ok  ', 'nickname');
    static::assertSame('ok', $result);
  }

  /**
   * 测试 checkRules 传递 ReflectionAttribute 形式的规则
   *
   * @return void
   */
  public function testCheckRulesWithReflectionAttribute(): void
  {
    $parameters = (new \ReflectionMethod(static::class, 'methodForAttribute'))->getParameters();
    $attributes = $parameters[0]->getAttributes(Length::class);
    static::assertNotEmpty($attributes);
    try {
      Validate::checkRules($attributes, '这是一个超出长度限制的值', 'field');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertStringContainsString('$field', $e->getMessage());
    }
  }

  /**
   * 测试 checkRules 自定义规则可通过可选第二参数接收参数名
   *
   * validate(mixed $value, string $name = '') 签名向后兼容；
   * 规则以裸参数名拼接文案时（如 "phone xxx"），包装时不应重复添加前缀
   *
   * @return void
   */
  public function testCheckRulesPassesNameToCustomRule(): void
  {
    $rule = new class extends BaseValidateRule {
      public function validate(mixed $value, string $name = ''): mixed
      {
        if ($value !== 'ok') {
          $this->error("$name 参数值不合法");
        }
        return $value;
      }
    };
    $result = Validate::checkRules($rule, 'ok', 'phone');
    static::assertSame('ok', $result);
    try {
      Validate::checkRules($rule, 'bad', 'phone');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertSame('phone 参数值不合法', $e->getMessage());
    }
  }

  /**
   * 测试 checkRules 在无上下文参数时错误信息保持原样
   *
   * @return void
   */
  public function testCheckRulesWithoutContextKeepsMessage(): void
  {
    $rule = new class extends BaseValidateRule {
      public function validate(mixed $value): mixed
      {
        $this->error('原始错误信息');
        return $value;
      }
    };
    try {
      Validate::checkRules($rule, 'any');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertSame('原始错误信息', $e->getMessage());
    }
  }

  /**
   * 测试 checkRules 规则实例共享时协程间不串参数名
   *
   * 同一实例先携带参数名校验失败，再以另一参数名校验，
   * 错误信息应使用新的参数名（验证未在实例上存储请求级状态）
   *
   * @return void
   */
  public function testCheckRulesSharedInstanceNoStateLeak(): void
  {
    $rule = new Length(min: 1, max: 5);
    $first = null;
    try {
      Validate::checkRules($rule, '超出长度限制的值', 'first_param');
    } catch (ValidateException $e) {
      $first = $e->getMessage();
    }
    static::assertStringContainsString('$first_param', (string)$first);
    try {
      Validate::checkRules($rule, '超出长度限制的值', 'second_param');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertStringContainsString('$second_param', $e->getMessage());
      static::assertStringNotContainsString('$first_param', $e->getMessage());
    }
  }

  /**
   * 测试规则显式配置的无占位符自定义消息保持原样
   *
   * 开发者定稿的全中文文案不附加参数名
   *
   * @return void
   */
  public function testCustomizedMessageKeepsOriginal(): void
  {
    $rule = new Length(min: 1, max: 5, message: '昵称长度需在 1 到 5 个字符之间');
    try {
      Validate::checkRules($rule, '超出长度限制的值', 'nickname');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertSame('昵称长度需在 1 到 5 个字符之间', $e->getMessage());
    }
  }

  /**
   * 测试规则默认消息使用 {:name} 占位符时替换为参数名
   *
   * Mobile 等规则的构造默认消息内置占位符
   *
   * @return void
   */
  public function testDeclaredDefaultPlaceholderSubstituted(): void
  {
    $rule = new Mobile();
    try {
      Validate::checkRules($rule, '123', 'phone');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertSame('$phone 必须是有效的手机号', $e->getMessage());
    }
  }

  /**
   * 测试自定义消息中使用 {:name} 占位符获取参数名
   *
   * 开发者定制文案通过占位符显式引用参数名，替换后不再自动加前缀
   *
   * @return void
   */
  public function testCustomizedMessageWithPlaceholder(): void
  {
    $rule = new Length(min: 1, max: 5, message: '{:name} 长度需在 1 到 5 个字符之间');
    try {
      Validate::checkRules($rule, '超出长度限制的值', 'nickname');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertSame('$nickname 长度需在 1 到 5 个字符之间', $e->getMessage());
    }
  }

  /**
   * 测试多条错误消息中的 {:name} 占位符逐条替换
   *
   * @return void
   */
  public function testPlaceholderInArrayError(): void
  {
    $e = new ValidateException(['{:name} 长度不符合要求', '格式错误']);
    $wrapped = Validate::withContext($e, 'nickname');
    static::assertSame(
      ['$nickname 长度不符合要求', '格式错误'],
      $wrapped->getError()
    );
  }

  /**
   * 测试自定义规则未声明 message 默认值时非空消息视为定制
   *
   * 自定义规则通过 parent::__construct 传入固定文案且无 message 参数，
   * 视为开发者定稿文案，不附加参数名
   *
   * @return void
   */
  public function testCustomRuleWithFixedMessageNoPrefix(): void
  {
    $rule = new class extends BaseValidateRule {
      public function __construct()
      {
        parent::__construct('该取值不符合业务约束');
      }

      public function validate(mixed $value): mixed
      {
        if ($value !== 'ok') $this->error();
        return $value;
      }
    };
    try {
      Validate::checkRules($rule, 'bad', 'biz_field');
      static::fail('应当抛出 ValidateException');
    } catch (ValidateException $e) {
      static::assertSame('该取值不符合业务约束', $e->getMessage());
    }
  }

  /**
   * 测试前初始化 App 容器（Validate 相关方法依赖容器）
   *
   * @return void
   */
  protected function setUp(): void
  {
    App::factory();
  }

  /**
   * 属性测试辅助方法（供 ReflectionAttribute 用例反射其参数）
   *
   * @param string $field 测试字段
   * @return string 原值
   */
  private function methodForAttribute(
    #[Length(min: 1, max: 5)] string $field
  ): string
  {
    return $field;
  }
}
