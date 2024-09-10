<?php
// +----------------------------------------------------------------------
// | 路由配置
// +----------------------------------------------------------------------

declare (strict_types=1);

return [
  // 是否区分大小写
  'case_sensitive' => false,
  // 伪静态后缀，支持通过数组设置多个。
  'suffix' => '*',
  // 域名校验 例如 [www.baidu.com]
  'domain' => '*',
  // 默认的路由变量正则表达式
  'default_pattern_regex' => '[\w\.]+',
  // 要加载的路线配置文件
  'route_config_files' => [
    BASE_PATH . '/config/route/route.php'
  ]
];
