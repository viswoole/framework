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

namespace Viswoole\Tests\HttpServer;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\HttpServer\Message\UploadedFile;
use Viswoole\HttpServer\Validate\FileRule;

/**
 * 上传文件验证规则测试
 *
 * 验证 FileRule 的 MIME 白名单基于文件内容检测（finfo）而非客户端声明的
 * Content-Type：客户端 MIME 可被任意伪造，仅校验声明值会使上传白名单
 * 形同虚设（OWASP Upload Cheat Sheet）
 */
class FileRuleTest extends TestCase
{
  /** @var string[] 测试产生的临时文件路径 */
  private array $tempFiles = [];

  /**
   * 清理测试产生的临时文件
   */
  protected function tearDown(): void
  {
    foreach ($this->tempFiles as $file) {
      @unlink($file);
    }
  }

  /**
   * 创建临时上传文件
   *
   * @param string $content 文件内容
   * @param string $name 文件名后缀
   * @return string 临时文件路径
   */
  private function createTempFile(string $content, string $name): string
  {
    $path = sys_get_temp_dir() . '/file_rule_' . uniqid() . '_' . $name;
    file_put_contents($path, $content);
    $this->tempFiles[] = $path;
    return $path;
  }

  /**
   * 测试伪造客户端 MIME 的可执行文件被拒绝
   *
   * 构造内容为 PHP 代码的临时文件，客户端声明 image/jpeg：
   * 若校验仅基于客户端声明（可伪造），该文件将通过 image/jpeg 白名单
   *
   * @return void
   */
  public function testRejectsForgedClientMime(): void
  {
    $tmpPath = $this->createTempFile(
      "<?php echo 'webshell';",
      'shell.jpg'
    );
    $file = new UploadedFile(
      'image/jpeg', 'shell.jpg', filesize($tmpPath), $tmpPath, UPLOAD_ERR_OK
    );

    $this->expectException(ValidateException::class);
    (new FileRule(fileMime: 'image/jpeg'))->validate($file);
  }

  /**
   * 测试真实 MIME 匹配白名单的合法文件通过校验
   *
   * 文件内容为真实 GIF 头（GIF89a），客户端声明 image/gif：
   * 内容检测应命中 image/gif 白名单
   *
   * @return void
   */
  public function testAcceptsGenuineMime(): void
  {
    $tmpPath = $this->createTempFile(
      'GIF89a' . "\x01\x00\x01\x00\x00\x00\x00;",
      'real.gif'
    );
    $file = new UploadedFile(
      'image/gif', 'real.gif', filesize($tmpPath), $tmpPath, UPLOAD_ERR_OK
    );

    $result = (new FileRule(fileMime: 'image/gif'))->validate($file);

    static::assertSame($file, $result);
  }
}
