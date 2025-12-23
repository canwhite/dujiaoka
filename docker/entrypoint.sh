#!/bin/sh

set -e

# 等待数据库连接
echo "等待数据库连接..."
# nc是netcat的缩写，号称网络界的瑞士军刀
# nc -z host.docker.internal 3306，-z只扫不发，检测端口
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
chown -R www-data:www-data /var/www/html || true
chmod -R 755 /var/www/html || true
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache || true

# 复制虚拟主机配置
mkdir -p /etc/nginx/conf.d || true
cp /var/www/html/docker/default.conf /etc/nginx/conf.d/default.conf || true

# 清理配置缓存
php artisan config:clear
php artisan cache:clear
php artisan view:clear

# 创建logs目录并修复nginx日志路径
mkdir -p /var/log/php /var/log/nginx

# 检查并修复符号链接循环
#  在 Shell 脚本中，[ ] 是 test 命令的简写，用于条件判断。
if [ -L /var/log/nginx ]; then
    rm -f /var/log/nginx
    mkdir -p /var/log/nginx
fi #if的结束符

# 确保nginx日志文件存在且可写
touch /var/log/nginx/error.log /var/log/nginx/access.log
chmod 755 /var/log/nginx
chmod 644 /var/log/nginx/*.log

# 启动supervisor，exec就是执行
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf

# PS: for循环
# 等待多个服务
# for port in 3306 6379 9200; do
#     echo "等待端口 $port..."
#     while ! nc -z host.docker.internal $port; do
#         sleep 2
#     done
# done