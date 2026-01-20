# Docker首次启动与MySQL初始化指南

## 概述
本文档详细说明如何使用Docker首次启动独角数卡(dujiaoka)项目，并正确初始化MySQL数据库。

## 项目架构理解
- **应用容器**: dujiaoka_app (PHP-FPM + Nginx + Supervisor)
- **数据库**: 使用宿主机上的MySQL服务 (通过`host.docker.internal`连接)
- **缓存**: 使用宿主机上的Redis服务 (通过`host.docker.internal`连接)
- **数据库初始化**: 通过`database/sql/install.sql`文件手动导入

## 前提条件

### 1. 系统要求
- Docker和Docker Compose已安装
- MySQL 5.7+ 在宿主机上运行
- Redis 在宿主机上运行
- 至少2GB可用内存

### 2. 端口可用性检查
确保以下端口未被占用：
- `3306` (MySQL)
- `6379` (Redis)
- `9595` (应用访问端口)

## 数据库初始化步骤

### 步骤1: 创建数据库
```bash
# 登录MySQL
mysql -u root -p

# 创建数据库
CREATE DATABASE dujiaoka CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 创建用户（可选）
CREATE USER 'dujiaoka_user'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON dujiaoka.* TO 'dujiaoka_user'@'%';
FLUSH PRIVILEGES;

# 退出
EXIT;
```

### 步骤2: 导入数据库结构
```bash
# 导入install.sql文件
mysql -u root -p dujiaoka < database/sql/install.sql
```

### 步骤3: 验证数据导入
```bash
# 检查表是否创建成功
mysql -u root -p dujiaoka -e "SHOW TABLES;"

# 检查管理员菜单数据
mysql -u root -p dujiaoka -e "SELECT COUNT(*) as menu_count FROM admin_menu;"
```
预期结果：应该看到多个表，且admin_menu表应有数据。

## Docker启动步骤

### 步骤1: 环境配置
```bash
# 复制环境配置文件
cp .env.example .env

# 编辑.env文件，配置数据库连接
vim .env
```

**.env关键配置项**:
```bash
# 数据库配置
DB_CONNECTION=mysql
DB_HOST=host.docker.internal  # Docker容器内访问宿主机的特殊域名
DB_PORT=3306
DB_DATABASE=dujiaoka
DB_USERNAME=root
DB_PASSWORD=your_mysql_password

# Redis配置
REDIS_HOST=host.docker.internal
REDIS_PORT=6379
REDIS_PASSWORD=

# 应用配置
APP_URL=http://127.0.0.1:9595
APP_ENV=production
APP_DEBUG=false

# 后台配置
ADMIN_ROUTE_PREFIX=admin
```

### 步骤2: 启动Docker容器
```bash
# 使用docker-compose启动
docker-compose up -d

# 查看容器状态
docker-compose ps

# 查看启动日志
docker-compose logs -f dujiaoka
```

### 步骤3: 验证容器运行状态
```bash
# 检查容器健康状态
docker inspect dujiaoka_app --format='{{.State.Health.Status}}'

# 查看容器日志
docker logs dujiaoka_app

# 检查端口映射
docker port dujiaoka_app
```

## 验证应用是否正常运行

### 1. 访问应用
- 打开浏览器访问: http://localhost:9595
- 应该能看到独角数卡的前台页面

### 2. 访问后台管理
- 访问: http://localhost:9595/admin
- 使用默认账号登录:
  - 用户名: `admin`
  - 密码: `admin`
- 登录后立即修改默认密码

### 3. 检查数据库连接
```bash
# 进入容器内部检查
docker exec -it dujiaoka_app bash

# 在容器内测试数据库连接
php artisan tinker
>>> DB::connection()->getPdo()
# 应该返回PDO连接对象，没有错误

# 测试Redis连接
>>> Redis::ping()
# 应该返回"PONG"
```

## 完整的一键启动脚本

如果您已经配置好MySQL和Redis，可以使用以下脚本快速启动：

```bash
#!/bin/bash
# 一键启动脚本: start_dujiaoka.sh

echo "1. 检查MySQL服务..."
if ! mysqladmin ping -h localhost -u root --password=your_password > /dev/null 2>&1; then
    echo "错误: MySQL服务未运行"
    exit 1
fi

echo "2. 创建数据库..."
mysql -u root -p'your_password' -e "CREATE DATABASE IF NOT EXISTS dujiaoka CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

echo "3. 导入数据库结构..."
mysql -u root -p'your_password' dujiaoka < database/sql/install.sql 2>/dev/null

echo "4. 检查环境配置..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "请编辑.env文件配置数据库连接"
    exit 1
fi

echo "5. 启动Docker容器..."
docker-compose up -d

echo "6. 等待应用启动..."
sleep 10

echo "7. 检查应用状态..."
curl -f http://localhost:9595 > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✅ 独角数卡启动成功！"
    echo "前台地址: http://localhost:9595"
    echo "后台地址: http://localhost:9595/admin"
    echo "默认账号: admin / admin"
else
    echo "❌ 应用启动失败，请查看日志: docker-compose logs dujiaoka"
fi
```

## 常见问题排查

### 问题1: 数据库连接失败
**症状**: 容器日志显示"数据库连接成功"但应用无法访问数据库

**解决**:
1. 检查宿主机MySQL是否允许远程连接：
```sql
-- 在MySQL中执行
CREATE USER 'root'@'%' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%';
FLUSH PRIVILEGES;
```

2. 检查防火墙是否阻止了Docker容器访问宿主机：
```bash
# 临时关闭防火墙（测试用）
sudo ufw disable
# 或添加规则
sudo ufw allow from 172.17.0.0/16 to any port 3306
```

### 问题2: Redis连接失败
**症状**: 容器日志显示"Redis连接成功"但应用无法使用Redis

**解决**:
1. 检查Redis配置是否允许外部连接：
```bash
# 编辑Redis配置文件
sudo vim /etc/redis/redis.conf

# 修改以下配置
bind 0.0.0.0
protected-mode no
```

2. 重启Redis服务：
```bash
sudo systemctl restart redis
```

### 问题3: 应用无法访问
**症状**: 浏览器无法访问 http://localhost:9595

**解决**:
1. 检查容器是否运行：
```bash
docker-compose ps
```

2. 检查端口映射：
```bash
docker port dujiaoka_app
# 应该显示: 80/tcp -> 0.0.0.0:9595
```

3. 查看容器日志：
```bash
docker-compose logs dujiaoka
```

### 问题4: 后台登录失败
**症状**: 使用admin/admin无法登录后台

**解决**:
1. 检查数据库中的管理员账号：
```sql
-- 查看admin_users表
mysql -u root -p dujiaoka -e "SELECT * FROM admin_users;"
```

2. 重置管理员密码：
```bash
# 进入容器
docker exec -it dujiaoka_app bash

# 使用Laravel的tinker重置密码
php artisan tinker
>>> \App\Models\AdminUser::where('username', 'admin')->update(['password' => bcrypt('admin')])
```

## 进阶配置

### 使用Docker运行MySQL和Redis
如果您希望完全使用Docker运行所有服务，可以修改docker-compose.yml：

```yaml
version: '3.8'

services:
  mysql:
    image: mysql:8.0
    container_name: dujiaoka_mysql
    environment:
      MYSQL_ROOT_PASSWORD: root_password
      MYSQL_DATABASE: dujiaoka
      MYSQL_USER: dujiaoka_user
      MYSQL_PASSWORD: user_password
    volumes:
      - mysql_data:/var/lib/mysql
      - ./database/sql/install.sql:/docker-entrypoint-initdb.d/install.sql
    ports:
      - "3306:3306"
    networks:
      - dujiaoka_network

  redis:
    image: redis:alpine
    container_name: dujiaoka_redis
    ports:
      - "6379:6379"
    networks:
      - dujiaoka_network

  dujiaoka:
    build: .
    container_name: dujiaoka_app
    ports:
      - "9595:80"
    volumes:
      - ./.env:/var/www/html/.env:ro
      - ./storage/app/public:/var/www/html/storage/app/public
      - ./public/uploads:/var/www/html/public/uploads
      - ./logs:/var/log
    environment:
      DB_HOST: mysql  # 使用服务名而不是host.docker.internal
      DB_PORT: 3306
      DB_DATABASE: dujiaoka
      DB_USERNAME: dujiaoka_user
      DB_PASSWORD: user_password
      REDIS_HOST: redis
      REDIS_PORT: 6379
      APP_URL: http://127.0.0.1:9595
    depends_on:
      - mysql
      - redis
    networks:
      - dujiaoka_network
    restart: unless-stopped

volumes:
  mysql_data:

networks:
  dujiaoka_network:
    driver: bridge
```

### 自动化数据库初始化
使用Docker的初始化脚本功能自动导入SQL：

```bash
# 创建初始化目录
mkdir -p database/init

# 复制SQL文件到初始化目录
cp database/sql/install.sql database/init/

# 修改docker-compose.yml中的MySQL服务配置
# 添加 volumes: - ./database/init:/docker-entrypoint-initdb.d:ro
```

## 维护指南

### 备份数据库
```bash
# 从宿主机备份
mysqldump -u root -p dujiaoka > dujiaoka_backup_$(date +%Y%m%d).sql

# 从Docker容器备份（如果使用Docker运行MySQL）
docker exec dujiaoka_mysql mysqldump -u root -p dujiaoka > dujiaoka_backup.sql
```

### 更新应用
```bash
# 拉取最新代码
git pull

# 重建Docker镜像
docker-compose build

# 重启服务
docker-compose up -d
```

### 查看日志
```bash
# 查看应用日志
docker-compose logs -f dujiaoka

# 查看Nginx访问日志
tail -f logs/nginx/access.log

# 查看PHP错误日志
tail -f logs/php/error.log
```

## 总结
通过以上步骤，您可以成功使用Docker启动独角数卡项目并初始化MySQL数据库。关键点包括：

1. **数据库先于容器启动**：必须先创建数据库并导入install.sql
2. **正确的网络配置**：使用`host.docker.internal`连接宿主机服务
3. **环境变量配置**：确保.env文件中的数据库连接信息正确
4. **验证步骤**：通过访问应用和后台验证部署成功

如果遇到问题，请参考常见问题排查部分，或查看容器日志获取详细信息。