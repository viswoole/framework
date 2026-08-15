<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Viswoole\Database\Model;

/**
 * 审查回归测试用关联模型 A：绑定 review_rel_a 通道
 */
class ReviewRelAModel extends Model
{
  protected string $table = 'rel_a';
  protected string $pk = 'id';
  protected ?string $channelName = 'review_rel_a';
}
