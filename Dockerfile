# 基础镜像
FROM phpswoole/swoole:php8.5-alpine

# 设置工作目录
WORKDIR /var/www/app

# 复制项目文件到工作目录
COPY . /var/www/app

# 暴露容器监听的端口号
EXPOSE 9501
