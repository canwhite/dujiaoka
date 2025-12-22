# 独角数卡 Docker 构建问题解决方案

## 📋 项目概述

独角数卡是一个基于Laravel的卡密销售系统，本文档记录了在Docker化部署过程中遇到的主要问题及其解决方案。

## 🚀 Docker构建流程

### 1. 基础环境准备
```bash
# 克隆项目
git clone <repository-url>
cd dujiaoka

# 检查Docker环境
docker --version
docker-compose --version
```

### 2. 配置文件准备
```bash
# 复制环境配置
cp .env.example .env
# 编辑.env文件，配置数据库和Redis连接
```

### 3. 构建和启动
```bash
# 构建并启动容器
docker-compose build --no-cache
docker-compose up -d

# 查看容器状态
docker-compose ps
docker-compose logs -f dujiaoka
```

### 4. 访问应用
- 前端地址: http://127.0.0.1:9595
- 后台地址: http://127.0.0.1:9595/admin

## 🔧 解决的主要问题

### 问题1: Entrypoint脚本执行失败
**错误信息**: `/usr/local/bin/entrypoint.sh: not found`

**根本原因**:
- Alpine Linux默认不包含bash
- entrypoint.sh使用了`#!/bin/bash`但系统中没有bash

**解决方案**:
1. 在`Dockerfile`中添加bash包
```dockerfile
RUN apk add --no-cache \
    bash \
    # ... 其他包
```

2. 确保entrypoint.sh有执行权限
```bash
chmod +x docker/entrypoint.sh
```

### 问题2: Nginx符号链接循环错误
**错误信息**: `nginx: [emerg] open() "/var/log/nginx/error.log" failed (40: Symbolic link loop)`

**根本原因**:
- 本地`logs/nginx`目录存在符号链接指向`/var/lib/nginx/logs`
- Docker容器挂载`./logs:/var/log`形成循环引用

**解决方案**:
1. 清理本地符号链接
```bash
rm -rf logs/nginx
```

2. 修改`docker/entrypoint.sh`，添加nginx日志目录处理逻辑
```bash
# 创建logs目录并修复nginx日志路径
mkdir -p /var/log/php /var/log/nginx

# 检查并修复符号链接循环
if [ -L /var/log/nginx ]; then
    rm -f /var/log/nginx
    mkdir -p /var/log/nginx
fi

# 确保nginx日志文件存在且可写
touch /var/log/nginx/error.log /var/log/nginx/access.log
chmod 755 /var/log/nginx
chmod 644 /var/log/nginx/*.log
```

### 问题3: PHP-FPM连接方式不匹配
**错误信息**: `connect() to unix:/var/run/php/php7.4-fpm.sock failed (2: No such file or directory)`

**根本原因**:
- nginx配置使用Unix socket: `unix:/var/run/php/php7.4-fpm.sock`
- PHP-FPM实际监听TCP端口: `127.0.0.1:9000`

**解决方案**:
修改`docker/default.conf`中的PHP-FPM连接方式
```nginx
# PHP处理
location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;  # 修改为TCP连接
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    # ...
}
```

### 问题4: Laravel应用安装状态检查
**现象**: 访问任何页面都重定向到首页或返回404

**根本原因**:
- Laravel应用检测到缺少`install.lock`文件
- 中间件将所有请求重定向到安装页面

**解决方案**:
1. **临时解决方案**: 创建install.lock文件
```bash
touch install.lock
```

2. **完整解决方案**: 访问安装向导
```bash
# 访问安装页面
http://127.0.0.1:9595/install
```

### 问题5: PHP Redis扩展缺失
**错误信息**: Laravel会话和缓存功能异常，Redis相关操作失败

**根本原因**:
- PHP镜像默认不包含Redis扩展
- Laravel需要Redis扩展来连接Redis服务
- 缺少扩展会导致会话存储失败

**解决方案**:
1. 在`Dockerfile`中添加Redis扩展安装
```dockerfile
&& pecl install imagick \
&& pecl install redis \
&& docker-php-ext-enable imagick redis
```

2. 优化Redis扩展安装方式（分离安装确保成功）
```dockerfile
&& pecl install imagick \
&& pecl install redis \
&& docker-php-ext-enable imagick redis
```

3. 验证Redis扩展是否正常加载
```bash
# 进入容器检查
docker-compose exec dujiaoka php -m | grep redis

# 或者使用PHP代码测试
docker-compose exec dujiaoka php -r "if (extension_loaded('redis')) { echo 'Redis extension is loaded\n'; } else { echo 'Redis extension is NOT loaded\n'; }"
```

**解决方案**:
1. 在`Dockerfile`中添加bash包
```dockerfile
RUN apk add --no-cache \
    bash \
    # ... 其他包
```

2. 确保entrypoint.sh有执行权限
```bash
chmod +x docker/entrypoint.sh
```

### 问题2: Nginx符号链接循环错误
**错误信息**: `nginx: [emerg] open() "/var/log/nginx/error.log" failed (40: Symbolic link loop)`

**根本原因**:
- 本地`logs/nginx`目录存在符号链接指向`/var/lib/nginx/logs`
- Docker容器挂载`./logs:/var/log`形成循环引用

**解决方案**:
1. 清理本地符号链接
```bash
rm -rf logs/nginx
```

2. 修改`docker/entrypoint.sh`，添加nginx日志目录处理逻辑
```bash
# 创建logs目录并修复nginx日志路径
mkdir -p /var/log/php /var/log/nginx

# 检查并修复符号链接循环
if [ -L /var/log/nginx ]; then
    rm -f /var/log/nginx
    mkdir -p /var/log/nginx
fi

# 确保nginx日志文件存在且可写
touch /var/log/nginx/error.log /var/log/nginx/access.log
chmod 755 /var/log/nginx
chmod 644 /var/log/nginx/*.log
```

### 问题3: PHP-FPM连接方式不匹配
**错误信息**: `connect() to unix:/var/run/php/php7.4-fpm.sock failed (2: No such file or directory)`

**根本原因**:
- nginx配置使用Unix socket: `unix:/var/run/php/php7.4-fpm.sock`
- PHP-FPM实际监听TCP端口: `127.0.0.1:9000`

**解决方案**:
修改`docker/default.conf`中的PHP-FPM连接方式
```nginx
# PHP处理
location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;  # 修改为TCP连接
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    # ...
}
```

### 问题4: Laravel应用安装状态检查
**现象**: 访问任何页面都重定向到首页或返回404

**根本原因**:
- Laravel应用检测到缺少`install.lock`文件
- 中间件将所有请求重定向到安装页面

**解决方案**:
1. **临时解决方案**: 创建install.lock文件
```bash
touch install.lock
```

2. **完整解决方案**: 访问安装向导
```bash
# 访问安装页面
http://127.0.0.1:9595/install
```

## 📁 配置文件修改清单

### 1. Dockerfile
- ✅ 添加`bash`包到Alpine安装列表
- ✅ 添加Redis扩展安装

### 2. docker/entrypoint.sh
- ✅ 添加nginx日志目录创建和处理逻辑
- ✅ 添加符号链接循环检测和修复

### 3. docker/default.conf
- ✅ 修改PHP-FPM连接方式从Unix socket改为TCP端口

### 4. docker-compose.yml
- ✅ 配置正确的端口映射 (9595:80)
- ✅ 设置环境变量和数据库连接

## 🎯 验证步骤

### 1. 检查容器状态
```bash
docker-compose ps
# 应该显示 Status: Up (healthy)
```

### 2. 检查服务进程
```bash
docker-compose exec dujiaoka ps aux
# 应该看到 nginx, php-fpm, laravel-queue 进程
```

### 3. 测试Web访问
```bash
# 测试首页响应
curl -I http://127.0.0.1:9595

# 应该返回 HTTP/1.1 200 或重定向响应
```

### 4. 检查Laravel路由
```bash
docker-compose exec dujiaoka php artisan route:list
# 应该显示完整的路由列表
```

### 5. 验证Redis扩展
```bash
# 检查Redis扩展是否加载
docker-compose exec dujiaoka php -m | grep redis

# 测试Redis连接
docker-compose exec dujiaoka php -r "if (extension_loaded('redis')) { echo 'Redis extension is loaded\n'; } else { echo 'Redis extension is NOT loaded\n'; }"
```

## 🔄 重建容器

如果需要重新构建容器，执行以下命令：

```bash
# 停止并删除现有容器
docker-compose down

# 清理镜像（可选）
docker-compose down --rmi all

# 重新构建
docker-compose build --no-cache

# 启动容器
docker-compose up -d

# 检查状态
docker-compose ps
docker-compose logs -f dujiaoka
```

## 📝 注意事项

1. **数据库连接**: 确保MySQL和Redis服务正常运行
2. **权限设置**: 确保storage和bootstrap/cache目录有写权限
3. **环境配置**: 检查.env文件中的数据库和Redis配置
4. **端口冲突**: 确保9595端口未被占用
5. **内存限制**: Docker默认内存限制可能不足，建议至少2GB

## 🎉 最终结果

修复完成后，独角数卡应用应该能够：
- ✅ 正常启动所有服务 (nginx, php-fpm, laravel-queue)
- ✅ 通过 http://127.0.0.1:9595 访问前端
- ✅ 通过 http://127.0.0.1:9595/admin 访问后台
- ✅ 正常处理Laravel路由和请求
- ✅ 数据库连接正常
- ✅ Redis扩展正常工作
- ✅ 队列任务正常运行

## 🐛 常见问题排查

### 容器无法启动
1. 检查端口占用: `lsof -i :9595`
2. 查看错误日志: `docker-compose logs dujiaoka`
3. 检查配置文件语法

### 数据库连接失败
1. 检查MySQL服务状态
2. 验证.env文件中的数据库配置
3. 确认防火墙设置

### Redis扩展问题
1. 检查扩展是否安装: `docker-compose exec dujiaoka php -m | grep redis`
2. 重新构建镜像: `docker-compose build --no-cache`
3. 验证Redis服务运行状态

### 静态文件404
1. 检查nginx配置中的root路径
2. 验证storage目录权限
3. 检查符号链接是否正确

---

**更新时间**: 2025-12-22
**版本**: 2.0
**主要更新**: 新增Redis扩展问题解决方案，完善故障排查指南