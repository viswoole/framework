<?php
// +----------------------------------------------------------------------
// | 路由配置
// +----------------------------------------------------------------------

declare(strict_types=1);

return [
  // 是否区分大小写
  'case_sensitive' => false,
  // 伪静态后缀，支持通过数组设置多个。
  'suffix' => '*',
  // 域名校验，例如 ['www.baidu.com']
  'domain' => '*',
  // HTTP 请求方法
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
    // 全局返回数据声明，值为 Viswoole\Router\ApiDoc\Annotation\Returned 实例数组
    'returned' => [],
    // 全局请求头，支持三种格式：
    //   new FieldStructure('authorization', '鉴权令牌', type: Types::String)
    //   'authorization' => '鉴权令牌'
    //   ['name' => 'authorization', 'description' => '鉴权令牌', 'type' => 'string']
    'header' => [],
    // 全局查询参数(GET)，格式同 header
    'query' => [],
    // 全局请求参数(POST)，格式同 header
    'body' => [],
    // 个别接口如需排除全局参数，可在控制器类或方法上使用 #[IgnoreGlobal] 注解，
    // 例如 #[IgnoreGlobal('header', 'authorization')] 可排除全局鉴权头
  ],
];
