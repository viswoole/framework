<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Viswoole\Database\Model;

/**
 * 审查回归测试用关联模型 B：绑定 review_rel_b 通道
 */
class ReviewRelBModel extends Model
{
  protected string $table = 'rel_b';
  protected string $pk = 'id';
  protected ?string $channelName = 'review_rel_b';
}
