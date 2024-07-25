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

namespace Viswoole\HttpServer\Facades;

use Override;
use Viswoole\Core\Facade;
use Viswoole\HttpServer\Contract\RequestInterface;
use ViSwoole\HttpServer\Message\UploadedFile;
use Viswoole\HttpServer\Message\Uri;

/**
 * HTTP请求对象
 *
 * @method static Uri getUri() 检索 URI 实例。
 * @method static array|string|null getHeader(?string $key = null, mixed $default = null) 获取请求标头, 所有标头均为小写
 * @method static RequestInterface create(array $options = []) 创建一个新的RequestInterface对象
 * @method static bool https() 判断是否https访问
 * @method static mixed getServer(?string $key = null, mixed $default = null) 获取服务参数
 * @method static array|null getBasicAuthCredentials() 获取基本身份验证票据
 * @method static string target() 获取消息的请求目标（路径）。
 * @method static string getPath() 获取消息的请求路径。
 * @method static mixed cookie(?string $key = null, mixed $default = null) 获取cookie
 * @method static UploadedFile|\array|\null files(?string $key = null) 获取上传的文件
 * @method static string|false getContent() 获取POST包体，此函数等同于 PHP 的 fopen('php://input')。
 * @method static string|false getData() 获取完整的原始 Http 请求报文，注意 Http2 下无法使用。
 * @method static int parse(string $data) 解析 HTTP 请求数据包，会返回成功解析的数据包长度。
 * @method static bool isCompleted() 获取当前的 HTTP 请求数据包是否已到达结尾。
 * @method static string ip() 获取客户端ip
 * @method static array params(array|string|null $rule = null, bool $isShowNull = true) 批量获取请求参数
 * @method static mixed param(?string $key = null, mixed $default = null, array|string|null $filter = null) 获取请求参数，自动判断get或post
 * @method static string getMethod() 获取当前的 HTTP 请求的请求方式。
 * @method static mixed post(?string $key = null, mixed $default = null) 获取post参数
 * @method static mixed get(?string $key = null, mixed $default = null) 获取get参数
 * @method static string getProtocolVersion() 获取HTTP协议版本。
 * @method static bool hasHeader(string $key) 通过给定的不区分大小写的名称检查标头是否存在。
 * @method static RequestInterface setHeader(string $name, string $value) 使用提供的值替换指定标头的实例。(不存在会新增)
 * @method static bool isJson() 当前是否JSON请求
 * @method static string getAcceptType() 当前请求的资源类型
 * @method static void addParams(array $params, string $type = 'auto') 添加/修改请求参数
 *
 * 优化命令：php viswoole optimize:facade \\Viswoole\\HttpServer\\Facades\\Request
 */
class Request extends Facade
{
  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\HttpServer\Request::class;
  }
}
