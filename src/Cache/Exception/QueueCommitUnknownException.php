<?php

/*
 * 职责：标识队列原子提交未取得可靠回执；不将不确定提交误报为明确拒收。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Exception;

use RuntimeException;

/**
 * Redis 命令可能已执行，调用方不能据此创建新的同业务任务。
 */
final class QueueCommitUnknownException extends RuntimeException
{
}
