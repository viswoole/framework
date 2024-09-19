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
  // HTTP请求方法
  'method' => '*',
  // 默认的路由变量正则表达式
  'default_pattern_regex' => '[\w\.]+',
  // 要加载的路由定义文件
  'route_config_files' => [
    BASE_PATH . '/config/route/route.php'
  ],
  // 路由缓存配置
  'cache' => [
    // 是否开启路由缓存
    'enable' => false,
    // 路由缓存存放目录
    'path' => BASE_PATH . '/runtime/route'
  ],
  // 路由文档配置
  'api_doc' => [
    // 是否启用
    'enable' => false,
    // 全局返回数据声明
    'returned' => [],
    // 全局请求头
    'header' => [],
    // 全局查询参数(GET)
    'query' => [],
    // 全局请求参数(POST)
    'body' => [],
  ],
];
