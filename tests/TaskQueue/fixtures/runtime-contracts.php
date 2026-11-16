<?php
/**
 * 职责：通过真实 Swoole 和容器验证同步返回值和类主题注册；不连接 Redis。
 */
declare(strict_types=1);

namespace Viswoole\Core {
    require __DIR__ . '/bootstrap.php';

    /**
     * 只隔离应用启动，保留真实容器的构造注入逻辑。
     */
    final class App
    {
        /**
         * 提供无业务启动副作用的真实容器。
         * @return Container 依赖解析容器
         */
        public static function factory(): Container
        {
            return new class extends Container {};
        }
    }
}

namespace Viswoole\Core\Facade {
    /**
     * 仅将门面定位到当前场景的真实 Swoole Server。
     */
    final class Server
    {
        /**
         * @var \Swoole\Server 当前测试服务
         */
        public static \Swoole\Server $instance;

        /**
         * 提供真实任务投递能力。
         * @return \Swoole\Server 当前服务
         */
        public static function getServer(): \Swoole\Server
        {
            return self::$instance;
        }
    }
}

namespace {
    use Viswoole\Core\Server\Task\TaskProxy;

    /**
     * 模拟需要构造注入的业务依赖。
     */
    final class ContractDependency {}

    /**
     * 检验实例构造与处理器状态不会跨任务复用。
     */
    final class ContractHandler
    {
        /**
         * @var int 当前实例已处理次数
         */
        private int $calls = 0;

        /**
         * 声明真实容器需要解析的依赖。
         * @param ContractDependency $dependency 业务依赖
         */
        public function __construct(
            /**
             * @var ContractDependency 由真实容器注入的依赖
             */
            private ContractDependency $dependency
        ) {}

        /**
         * 处理器使用当前命名空间的任务代理类型。
         * @param \Viswoole\Core\Server\Task\TaskProxy $task 任务代理
         * @return int 当前实例处理次数
         */
        public function handle(\Viswoole\Core\Server\Task\TaskProxy $task): int
        {
            return ++$this->calls;
        }

        /**
         * 非公开方法不应成为业务主题。
         * @return void
         */
        protected function hidden(): void {}

        /**
         * 静态处理器保持可调用。
         * @return string 静态处理结果
         */
        public static function staticHandle(): string
        {
            return 'static';
        }
    }

    /**
     * 暴露主题调用并复用生产同步投递实现。
     */
    final class ContractManager
    {
        use \Viswoole\Core\Server\Task\TaskTopicRegistry;
        use \Viswoole\Core\Server\Task\TaskSynchronousDispatch;

        /**
         * @var array<string, callable> 已注册主题
         */
        protected array $topics = [];

        /**
         * 使用真实代理调用注册结果。
         * @param string $topic 主题名称
         * @param \Swoole\Server $server 当前服务
         * @return mixed 处理器结果
         */
        public function call(string $topic, \Swoole\Server $server): mixed
        {
            $task = new \Swoole\Server\Task();
            $task->data = ['topic' => $topic];
            return ($this->topics[strtolower($topic)])(new TaskProxy($task), $server);
        }
    }

    $results = [];
    $manager = new ContractManager();
    $manager->register('class', ContractHandler::class);
    $server = new \Swoole\Server('127.0.0.1', 0, SWOOLE_BASE);
    $results['instanceInjected'] = $manager->call('class.handle', $server) === 1;
    $results['instanceIsolated'] = $manager->call('class.handle', $server) === 1;
    $results['staticCallable'] = $manager->call('class.staticHandle', $server) === 'static';
    try {
        $manager->has('class.hidden');
        $results['hiddenExcluded'] = false;
    } catch (\InvalidArgumentException $exception) {
        $results['hiddenExcluded'] = true;
    }
    $server->set(['worker_num' => 1, 'task_worker_num' => 1, 'task_use_object' => true,
        'log_file' => sys_get_temp_dir() . '/contract-swoole-' . getmypid() . '.log']);
    \Viswoole\Core\Facade\Server::$instance = $server;
    $manager->register('sync', static fn(): bool => true);
    $manager->register('0', static fn(): bool => true);
    $server->on('Receive', static function (): void {});
    $server->on('Finish', static function (): void {});
    $server->on('Task', static function (\Swoole\Server $server, \Swoole\Server\Task $task): mixed {
        if ($task->data['topic'] === 0) return 'invalid integer topic';
        if ($task->data['data'] === null) {
            $task->finish(null);
            return null;
        }
        return $task->data['data'];
    });
    $server->on('WorkerStart', static function (\Swoole\Server $server) use ($manager, $results): void {
        if ($server->taskworker) return;
        \Swoole\Timer::after(30, static function () use ($server, $manager, $results): void {
            foreach (['array' => ['ok' => true], 'int' => 42, 'true' => true,
                'null' => null, 'string' => 'ok', 'false' => false] as $name => $value) {
                $results['return' . $name] = $manager->emitWait('sync', $value, 1) === $value;
            }
            $results['zeroSync'] = $manager->emitWait('0', ['ok' => true], 1) === ['ok' => true];
            $results['zeroMulti'] = $manager->emitsWait(['0' => ['ok' => true]], 1) === [['ok' => true]];
            $results['zeroCo'] = $manager->emitsWait(['0' => ['ok' => true]], 1, true) === [['ok' => true]];
            echo json_encode($results, JSON_THROW_ON_ERROR);
            $server->shutdown();
        });
    });
    $server->start();
}
