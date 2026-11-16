# 独立验证镜像，不用于直接更新业务容器。
FROM phpswoole/swoole:6.2.5-php8.4-alpine
RUN php -r 'if (swoole_version() !== "6.2.5") { exit(1); }'
