<div align="center" style="text-align: center;margin-top:24px">
<img alt="logo" style="width: 300px;" src="https://viswoole.com/logo_empty_bg.png?d=1">
<br>

一款基于[swoole](https://www.swoole.com/)开发的轻量级`PHP`框架

[![Gitee](https://img.shields.io/badge/Gitee-blue?style=flat-square&logo=Gitee)](https://gitee.com/viswoole/viswoole)
[![GitHub Stars](https://img.shields.io/github/stars/viswoole/viswoole?style=flat-square&logo=Github)](https://github.com/viswoole/viswoole)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue?style=flat-square)](http://www.apache.org/licenses/LICENSE-2.0)
</div>

## 特性

- **易用**：注释文档采用中文编写，能够让你在编写代码时得到良好的代码提示，以及清晰可读的异常反馈信息，让英语能力差的开发者能够拥有一个极其舒适的开发体验。
- **安全**：优雅地依赖注入方式，提供了参数基本类型校验，以及拓展规则校验，无需额外编写`validate`
  去校验请求参数是否正确，现在您只需要关注业务代码，让框架帮你完成参数校验。
- **性能**：基于`swoole`的协程，在性能上比`PHP`原生的`fpm`要快很多。
- **扩展**：框架的扩展性非常强，提供了服务发现、依赖下发、`swoole`服务事件HOOK等常用功能，能够依据这些功能拓展你自定义的服务。
- **文档**：框架提供了API文档结构生成功能，能够根据路由树自动生成API文档，文档结构中包含了请求参数结构、响应数据结构等构建API文档所需的信息。

> 框架运行环境依赖于 `PHP`^8.3 + `swoole`^5.0

## 文档

[Viswoole开发文档](https://viswoole.com/docs/)

### 安装

```bash
composer create-project viswoole/viswoole myProject
```

### 启动服务

```bash
# 进入项目目录
cd myProject
# 安装依赖
composer install
# http为服务名称，是可选的，不填写默认会启动config/server.php配置文件中的default_start_server
php viswoole start:server http -d # -d 参数代表后台启动
```

如需单独更新框架依赖，可以使用如下命令：

```bash
composer update viswoole/framework
```

### 重启服务

```bash
# 如果不传入serverName，则会关闭所有在运行的服务
# 默认重启worker进程和task进程，可以选择传入 -t 参数重启task进程
# 除了 -t 参数以为，还接收一个 -f 参数，会先服务再重启
php viswoole reload:server http
```

### 关闭服务

```bash
# 如果不传入serverName，则会关闭所有在运行的服务
php viswoole close:server http
```

### 热重载

内置了一个shell脚本`watch`，可以用来监听文件修改，实现热重载。

```bash
/bin/sh watch http # 唯一接收一个可选参数[serverName]
```

## 参与开发

作者个人精力与能力有限，期待社区贡献，提交PR或Issue即可！

## 许可证书

`Viswoole`遵循[Apache-2.0](LICENSE)开源协议。

## 后续计划

如果有建议请提交issue。

- [ ] 基于`Vue`开发一套Admin管理系统UI框架，包含API文档Web界面。
- [ ] 微服务`RPC`支持，敬请期待。
- [ ] 视图模板解析引擎，考虑到现在都是前后端分离，所以没有在第一个发行版本中实现视图模板解析功能，该功能会在后续完善。
