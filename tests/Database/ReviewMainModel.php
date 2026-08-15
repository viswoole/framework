<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Viswoole\Database\Model;
use Viswoole\Database\Model\RelationQuery;

/**
 * 审查回归测试用主模型：绑定 review_main 通道，提供两个一对一关联
 */
class ReviewMainModel extends Model
{
  protected string $table = 'main';
  protected string $pk = 'id';
  protected ?string $channelName = 'review_main';

  public function profile(): RelationQuery
  {
    return $this->hasOne(ReviewRelAModel::class, 'user_id', 'id');
  }

  public function extra(): RelationQuery
  {
    return $this->hasOne(ReviewRelBModel::class, 'user_id', 'id');
  }
}
