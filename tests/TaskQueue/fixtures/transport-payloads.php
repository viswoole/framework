<?php
/**
 * 职责：提供具有恢复与序列化副作用的合法对象载荷；不访问存储或服务。
 */
declare(strict_types=1);

/**
 * 统一对比首次和重试中可观察到的业务数据。
 */
interface TransportPayload
{
    /**
     * 返回对象恢复或序列化生成的计数。
     * @return int 可观察计数
     */
    public function metric(): int;

    /**
     * 返回需要保持不变的二进制内容。
     * @return string 原始字节
     */
    public function bytes(): string;
}

/**
 * 检验 __wakeup 只在实际消费者中运行一次。
 */
final class WakeTransportPayload implements TransportPayload
{
    /**
     * @var int 当前对象恢复次数
     */
    public int $wakeCount = 0;
    /**
     * @var string 必须经过存储和 IPC 的二进制内容
     */
    private string $payloadBytes = "\xff\0payload";

    /**
     * 为每次反序列化留下可观察痕迹。
     * @return void
     */
    public function __wakeup(): void
    {
        $this->wakeCount++;
    }

    /**
     * 返回恢复次数。
     * @return int 当前恢复次数
     */
    public function metric(): int
    {
        return $this->wakeCount;
    }

    /**
     * 返回二进制载荷。
     * @return string 原始字节
     */
    public function bytes(): string
    {
        return $this->payloadBytes;
    }
}

/**
 * 检验业务对象只在持久化时序列化一次，重试不重新生成载荷。
 */
final class SerializeTransportPayload implements TransportPayload
{
    /**
     * @var int 当前生产进程调用序列化钩子的次数
     */
    public static int $serializations = 0;
    /**
     * @var int 本次字节快照所属代次
     */
    private int $generation = 0;
    /**
     * @var string 必须经过序列化钩子保留的二进制内容
     */
    private string $payloadBytes = "\xff\0payload";

    /**
     * 每次调用生成不同代次，检测隐式重序列化。
     * @return array{generation: int, bytes: string} 序列化内容
     */
    public function __serialize(): array
    {
        return ['generation' => ++self::$serializations, 'bytes' => $this->payloadBytes];
    }

    /**
     * 恢复首次持久化的代次。
     * @param array $data 序列化内容
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->generation = $data['generation'];
        $this->payloadBytes = $data['bytes'];
    }

    /**
     * 返回存储快照代次。
     * @return int 字节快照代次
     */
    public function metric(): int
    {
        return $this->generation;
    }

    /**
     * 返回二进制载荷。
     * @return string 原始字节
     */
    public function bytes(): string
    {
        return $this->payloadBytes;
    }
}
