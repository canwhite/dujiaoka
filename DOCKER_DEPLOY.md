# 独角数卡 Docker 部署指南

## 🚀 快速开始

### 前置要求
- Docker & Docker Compose
- 本地 MySQL 5.7+ 或 8.0+
- 本地 Redis 5.0+

### 1. 配置数据库
```sql
-- 创建数据库
CREATE DATABASE dujiaoka CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 创建用户 (可选)
CREATE USER 'dujiaoka'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON dujiaoka.* TO 'dujiaoka'@'%';
FLUSH PRIVILEGES;
```

### 2. 配置环境变量
复制 `.env.example` 到 `.env` 并修改:

```env
# 应用配置
APP_NAME=独角数卡
APP_URL=http://127.0.0.1:9595
APP_ENV=production
APP_DEBUG=false

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=host.docker.internal
DB_PORT=3306
DB_DATABASE=dujiaoka
DB_USERNAME=root
DB_PASSWORD=你的密码

# Redis配置
REDIS_HOST=host.docker.internal
REDIS_PORT=6379
REDIS_PASSWORD=

# 后台配置
DUJIAO_ADMIN_LANGUAGE=zh_CN
ADMIN_ROUTE_PREFIX=/admin

# 缓存配置
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

### 3. 启动应用
```bash
# 构建并启动
docker-compose up -d

# 查看日志
docker-compose logs -f dujiaoka
```

### 4. 访问应用
- 网站首页: http://127.0.0.1:9595
- 安装页面: http://127.0.0.1:9595/install (首次访问)
- 管理后台: http://127.0.0.1:9595/admin

## 📁 目录结构
```
dujiaoka/
├── docker/
│   ├── nginx.conf          # Nginx主配置
│   ├── default.conf        # 虚拟主机配置
│   ├── supervisord.conf    # 进程管理配置
│   ├── php.ini            # PHP配置
│   └── entrypoint.sh      # 启动脚本
├── storage/app/public/     # 上传文件持久化
├── public/uploads/         # 公共上传文件
├── logs/                   # 日志文件
├── Dockerfile             # 应用镜像构建
└── docker-compose.yml     # 容器编排
```

## 🔧 自定义配置

### 修改端口
编辑 `docker-compose.yml`:
```yaml
ports:
  - "你的端口:80"
```

### 修改数据库连接
编辑 `.env` 文件中的数据库配置。

### 数据持久化
以下目录已自动持久化:
- `./storage/app/public` - 应用上传文件
- `./public/uploads` - 公共上传文件
- `./logs` - 应用日志

## 🛠️ 常用命令

```bash
# 查看服务状态
docker-compose ps

# 查看日志
docker-compose logs -f dujiaoka

# 重启服务
docker-compose restart dujiaoka

# 进入容器
docker-compose exec dujiaoka bash

# 清理缓存
docker-compose exec dujiaoka php artisan cache:clear

# 运行队列
docker-compose exec dujiaoka php artisan queue:work
```

## 🐛 故障排除

### 数据库连接失败
1. 确认MySQL服务正在运行
2. 检查数据库用户名和密码
3. 确认防火墙设置允许Docker容器访问

### Redis连接失败
1. 确认Redis服务正在运行
2. 检查Redis端口和密码设置

### 上传文件问题
1. 检查目录权限: `chmod -R 777 storage public/uploads`
2. 确认磁盘空间充足

### 500错误
1. 查看应用日志: `docker-compose logs dujiaoka`
2. 检查Laravel日志: `storage/logs/laravel.log`

## 🔄 更新应用

```bash
# 拉取最新代码
git pull

# 重新构建镜像
docker-compose build --no-cache

# 重启服务
docker-compose up -d
```

## 📈 性能优化

### 1. 启用OPcache
已在 `docker/php.ini` 中配置。

### 2. Redis缓存
建议使用Redis作为缓存和队列驱动。

### 3. Nginx优化
已启用Gzip压缩和静态文件缓存。

### 4. 数据库优化
- 添加适当的索引
- 定期清理过期数据
- 考虑读写分离

## 🔒 安全建议

1. 定期更新应用和依赖
2. 使用强密码
3. 启用HTTPS
4. 配置防火墙
5. 定期备份数据库和文件