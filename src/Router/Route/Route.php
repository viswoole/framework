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

namespace Viswoole\Router\Route;

use InvalidArgumentException;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\ParamParseTool;
use Viswoole\Router\ApiDoc\Status;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\Facade\Router;

/**
 * 路由项
 */
class Route extends BaseRoute
{
  /**
   * @var Status 状态
   */
  private Status $status = Status::DEVELOPMENT;
  /**
   * @var array 标签
   */
  private array $tags = [];
  /**
   * @var string 作者
   */
  private string $author = '';
  /**
   * @var string 创建时间
   */
  private string $createdAt = '';
  /**
   * @var string 更新时间
   */
  private string $updatedAt = '';
  /**
   * @var array{body:array<string,FieldStructure>, header:array<string,FieldStructure>, query:array<string,FieldStructure>} 请求参数结构
   */
  private array $params = [];
  /**
   * @var Returned[] 响应结构
   */
  private array $returned = [];

  /**
   * @param string|array $paths 路由访问路径
   * @param callable|string|array $handler 路由处理函数
   * @param BaseRoute|null $parentOption 父级路由配置
   * @param string|null $id
   */
  public function __construct(
    array|string          $paths,
    callable|array|string $handler,
    BaseRoute             $parentOption = null,
    string                $id = null
  )
  {
    if (empty($paths)) {
      throw new InvalidArgumentException('route item paths is empty');
    }
    parent::__construct($paths, $handler, $parentOption, $id);
    if (Router::isEnableApiDoc()) {
      $result = ParamParseTool::parse($this->handler);
      $this->params = $result['params'];
      $this->returned = $result['returned'];
    }
  }

  /**
   * 参数结构
   *
   * @return array{body:array<string,FieldStructure>, header:array<string,FieldStructure>, query:array<string,FieldStructure>}
   */
  public function getParams(): array
  {
    return $this->params;
  }

  /**
   * 响应组件
   *
   * @return Returned[]
   */
  public function getReturned(): array
  {
    return $this->returned;
  }

  /**
   * 获取所有标签
   *
   * @return array 标签数组
   */
  public function getTags(): array
  {
    return $this->tags;
  }

  /**
   * 设置所有标签
   *
   * @param array $tag 标签数组
   * @return $this
   */
  public function setTags(string ...$tag): static
  {
    $this->tags = $tag;
    return $this;
  }

  /**
   * 获取创建时间
   *
   * @return string 创建时间
   */
  public function getCreatedAt(): string
  {
    return $this->createdAt;
  }

  /**
   * 设置创建时间
   *
   * @param string $createdAt 创建时间
   * @return $this
   */
  public function setCreatedAt(string $createdAt): static
  {
    $this->createdAt = $createdAt;
    return $this;
  }

  /**
   * 获取更新时间
   *
   * @return string 更新时间
   */
  public function getUpdatedAt(): string
  {
    return $this->updatedAt;
  }

  /**
   * 设置更新时间
   *
   * @param string $updatedAt 更新时间
   * @return $this
   */
  public function setUpdatedAt(string $updatedAt): static
  {
    $this->updatedAt = $updatedAt;
    return $this;
  }

  /**
   * 获取作者
   *
   * @return string 作者
   */
  public function getAuthor(): string
  {
    return $this->author;
  }

  /**
   * 设置作者
   *
   * @param string $author
   * @return $this
   */
  public function setAuthor(string $author): static
  {
    $this->author = $author;
    return $this;
  }

  /**
   * 获取状态
   *
   * @return Status 状态
   */
  public function getStatus(): Status
  {
    return $this->status;
  }

  /**
   * 设置状态
   *
   * @param Status $status 状态
   * @return $this
   */
  public function setStatus(Status $status): static
  {
    $this->status = $status;
    return $this;
  }
}
