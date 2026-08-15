# 基础镜像
FROM phpswoole/swoole:php8.5-alpine

# 设置工作目录
WORKDIR /var/www/app

# 复制项目文件到工作目录
COPY . /var/www/app

# 暴露容器监听的端口号（容器内固定 9501，与框架 config/server.php 默认监听一致；
# 宿主机访问端口由 docker-compose.yml 映射决定，当前映射为 9511，避开 api-php 项目的 9501）
EXPOSE 9501
