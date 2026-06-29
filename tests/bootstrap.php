<?php
/**
 * PHPUnit 引导文件
 *
 * 在测试启动前预初始化 App 单例，捕获初始化期间的输出（如事件监听器的 echo_log），
 * 避免被 PHPUnit 标记为 risky test。配置文件无需感知测试环境。
 */

require __DIR__ . '/../vendor/autoload.php';

ob_start();
Viswoole\Core\App::factory();
ob_end_clean();
