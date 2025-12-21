#!/bin/bash

set -e

# 等待数据库连接
echo "等待数据库连接..."
while ! nc -z host.docker.internal 3306; do
    sleep 2
done
echo "数据库连接成功"

# 等待Redis连接
echo "等待Redis连接..."
while ! nc -z host.docker.internal 6379; do
    sleep 2
done
echo "Redis连接成功"

# 设置权限
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# 复制虚拟主机配置
cp /var/www/html/docker/default.conf /etc/nginx/conf.d/default.conf

# 清理配置缓存
php artisan config:clear
php artisan cache:clear
php artisan view:clear

# 创建logs目录
mkdir -p /var/log/php

# 启动supervisor
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf