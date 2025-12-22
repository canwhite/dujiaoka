# 🐳 Docker配置详细教程 - 完整版

## 📚 基础概念（必须先理解）

### 什么是"容器"？
想象一下：
- **传统部署**：就像搬家，把所有家具家电一件件搬到新家，可能遗漏东西
- **容器部署**：就像用集装箱搬家，整个房子打包，到新地方放下就能用

### 什么是"镜像"？
- **镜像** = 应用程序的"快照" + 运行环境 + 所有依赖
- 就像Windows的ISO文件，是创建虚拟机的模板

### 什么是"Dockerfile"？
- 就是"制作说明书"
- 告诉Docker如何一步步构建镜像

### 什么是"docker-compose.yml"？
- 容器编排文件，就像"总指挥"
- 告诉Docker如何启动和管理多个容器


### 架构图
```
你的电脑
    ↓ (端口9595:80)
Docker网络
    ↓
┌─────────────────────────────────────────┐
│           独角数卡容器                    │
│  ┌───────────────────────────────────┐  │
│  │    Nginx (80端口)                │  │
│  │    PHP-FPM (9000端口)             │  │
│  │    Laravel应用                    │  │
│  │    Redis扩展 (连接外部Redis)      │  │
│  │    Supervisor (管理所有进程)      │  │
│  └───────────────────────────────────┘  │
└─────────────────────────────────────────┘
    ↓ (host.docker.internal)
你的电脑
    ├─── MySQL (3306端口)
    └─── Redis (6379端口)
```


## 📁 Dockerfile详细解析 - 镜像制作说明书

### 第1-2行：基础镜像选择
```dockerfile
# 独角数卡 Docker镜像
FROM php:7.4-fpm-alpine
```

**详细解释：**
- `FROM`：指定基础镜像，就像做蛋糕先要准备好蛋糕坯
- `php:7.4-fpm-alpine`：
  - `php`：这是PHP语言
  - `7.4`：指定版本号7.4
  - `fpm`：FastCGI Process Manager，PHP的运行方式
  - `alpine`：Linux发行版，非常轻量（只有5MB），适合做容器

**什么是PHP-FPM？**
- 传统Apache+PHP：每个请求都要启动PHP进程，浪费资源
- Nginx+PHP-FPM：PHP进程常驻内存，只处理PHP代码，效率更高

### 第4-5行：系统包安装
```dockerfile
# 安装系统依赖
RUN apk add --no-cache \
```

**详细解释：**
- `RUN`：执行命令，相当于在Linux终端里敲命令
- `apk`：Alpine Linux的包管理器（类似Ubuntu的apt，CentOS的yum）
- `add`：安装包
- `--no-cache`：不缓存包文件，减小镜像大小

### 第6-10行：核心服务包
```dockerfile
    nginx \           # Web服务器，处理HTTP请求
    supervisor \      # 进程管理器，同时管理多个进程
    curl \            # HTTP客户端，用于测试网络连接
    netcat-openbsd \  # 网络工具，检查端口是否开放
    bash \            # Shell环境，命令行解释器
```

**每个包的作用：**
- **nginx**：接收浏览器请求，静态文件直接返回，PHP文件转发给PHP-FPM
- **supervisor**：一个管家，同时管理nginx、php-fpm、laravel队列进程
- **curl**：测试网络连接的瑞士军刀
- **netcat**：检查网络端口，比如看MySQL是否启动了
- **bash**：Linux命令解释器，之前问题的根源！

### 第11-24行：PHP扩展开发包
```dockerfile
    libpng \          # PNG图片处理库
    libpng-dev \      # PNG图片开发文件
    oniguruma-dev \   # 正则表达式库
    libxml2-dev \     # XML解析库
    zip \             # 压缩文件处理
    unzip \           # 解压文件
    libzip-dev \      # ZIP开发文件
    imagemagick-dev \ # 图片处理库
    jpeg-dev \        # JPEG图片开发文件
    libjpeg-turbo-dev \ # JPEG优化库
    freetype-dev \    # 字体渲染库
    zlib-dev \        # 压缩库
    gmp-dev \         # 大数学运算库
    icu-dev \         # 国际化库
```

**为什么要装这些？**
- PHP扩展需要这些底层库支持
- 比如要处理图片，就需要图片处理库
- 要处理压缩文件，就需要zip库

### 第25-30行：编译工具
```dockerfile
    autoconf \        # 自动配置工具
    automake \        # 自动编译工具
    gcc \             # C语言编译器
    g++ \             # C++编译器
    make \            # 编译工具
    libtool \         # 库编译工具
    imagemagick \     # 图片处理程序
```

**作用：**
- 编译PHP扩展需要这些工具
- 比如Redis扩展不是PHP内置的，需要自己编译安装

### 第32行：配置GD图片处理库
```dockerfile
&& docker-php-ext-configure gd --with-freetype --with-jpeg \
```

**详细解释：**
- `&&`：命令连接符，前一个命令成功才执行下一个
- `docker-php-ext-configure`：PHP扩展配置工具
- `gd`：图片处理扩展名
- `--with-freetype --with-jpeg`：启用字体和JPEG支持

**什么是GD库？**
- PHP处理图片的扩展
- 生成验证码、缩略图、加水印等

### 第33-42行：安装PHP内置扩展
```dockerfile
&& docker-php-ext-install -j$(nproc) \
    gd \              # 图片处理
    pdo_mysql \       # MySQL数据库连接
    mysqli \          # 另一个MySQL连接方式
    zip \             # 压缩文件处理
    bcmath \          # 精确数学运算
    gmp \             # 大数运算
    opcache \         # PHP代码加速器
    intl \            # 国际化支持
    exif \            # 照片信息读取
```

**每个扩展的作用：**
- **pdo_mysql**：现代的数据库连接方式，面向对象
- **mysqli**：传统的数据库连接方式，面向过程
- **zip**：处理压缩包
- **bcmath**：处理精确的小数运算（如金额计算）
- **opcache**：缓存PHP编译后的代码，提升性能
- **intl**：多语言支持，日期格式化等
- **exif**：读取照片的拍摄信息（相机型号、GPS等）

### 第43-45行：安装第三方扩展
```dockerfile
&& pecl install imagick \
&& pecl install redis \
&& docker-php-ext-enable imagick redis
```

**详细解释：**
- `pecl`：PHP扩展库，类似应用商店
- `imagick`：强大的图片处理扩展，比GD更专业
- `redis`：Redis数据库连接扩展
- `docker-php-ext-enable`：启用已安装的扩展

**为什么Redis不是内置的？**
- Redis是第三方软件，不是PHP官方的
- 需要单独从PECL仓库下载安装

### 第47-48行：安装Composer
```dockerfile
# 安装Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

**详细解释：**
- **什么是Composer？** PHP的包管理器，类似Node.js的npm
- **多阶段构建：** 从composer官方镜像复制已安装的composer
- **为什么这样做？** 避免在镜像里安装整个composer环境，减小镜像大小

### 第50-51行：设置工作目录
```dockerfile
# 设置工作目录
WORKDIR /var/www/html
```

**作用：**
- 设置后续命令的默认目录
- 相当于`cd /var/www/html`
- `/var/www/html`是Web服务器的默认根目录

### 第53-54行：复制应用代码
```dockerfile
# 复制应用代码
COPY . .
```

**详细解释：**
- `COPY`：从宿主机复制文件到容器
- 第一个`.`：宿主机的当前目录（dujiaoka项目根目录）
- 第二个`.`：容器内的当前目录（/var/www/html）
- 结果：把整个项目代码复制到容器的Web根目录

### 第56-57行：安装PHP依赖
```dockerfile
# 安装PHP依赖
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

**参数详解：**
- `--no-dev`：不安装开发依赖（生产环境不需要）
- `--optimize-autoloader`：优化自动加载，提升性能
- `--ignore-platform-reqs`：忽略平台要求（避免某些包要求特定系统版本）

### 第59-62行：设置文件权限
```dockerfile
# 设置权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 storage bootstrap/cache
```

**权限解释：**
- `www-data`：Web服务器的运行用户
- `755`：所有者可读写执行，其他用户可读执行
- `777`：所有人可读写执行（用于需要写入的目录）

**为什么storage和cache需要777权限？**
- Laravel需要在这些目录写入日志、缓存、上传文件
- 如果权限不够，会报500错误

### 第64-67行：复制配置文件
```dockerfile
# 复制配置文件
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/php.ini /usr/local/etc/php/conf.d/custom.ini
```

**配置文件位置解释：**
- `nginx.conf`：nginx主配置文件
- `supervisord.conf`：进程管理器配置
- `php.ini`：PHP配置文件

**Linux目录结构：**
- `/etc/`：配置文件目录
- `/usr/local/`：用户安装软件目录
- `/usr/local/etc/`：用户软件配置目录

### 第69-71行：设置启动脚本
```dockerfile
# 创建启动脚本
COPY ./docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
```

**详细解释：**
- `/usr/local/bin/`：用户可执行文件目录
- `chmod +x`：添加执行权限（非常重要！）
- `entrypoint.sh`：容器启动时执行的脚本

### 第73-74行：暴露端口
```dockerfile
# 暴露端口
EXPOSE 80
```

**作用：**
- 告诉Docker这个容器监听80端口
- 只是文档说明，实际端口映射在docker-compose中定义
- 可以理解为："这个容器提供80端口的web服务"

### 第76-77行：启动命令
```dockerfile
# 启动命令
CMD ["/usr/local/bin/entrypoint.sh"]
```

**详细解释：**
- `CMD`：容器启动时执行的命令
- `["/usr/local/bin/entrypoint.sh"]`：执行启动脚本
- 相当于在容器内运行 `/usr/local/bin/entrypoint.sh`

## 📁 docker-compose.yml详细解析 - 容器编排文件

### 文件结构概览
```yaml
version: '3.8'        # docker-compose版本

services:             # 定义所有服务
  dujiaoka:          # 应用服务
    # 服务配置...

  db-init:           # 数据库初始化服务（可选）
    # 服务配置...

networks:            # 网络配置
  # 网络定义...

volumes:             # 数据卷配置
  # 数据卷定义...
```

### 应用服务详细解析
```yaml
services:
  # 独角数卡应用服务
  dujiaoka:
    build:           # 构建配置
      context: .     # 构建上下文目录（Dockerfile所在目录）
      dockerfile: Dockerfile  # Dockerfile文件名
      # platform: linux/amd64  # 确保使用amd64架构（可选）

    container_name: dujiaoka_app  # 容器名称

    ports:           # 端口映射
      - "9595:80"    # 格式：宿主机端口:容器端口
```

**端口映射详解：**
```
你的浏览器 → http://127.0.0.1:9595
    ↓
Docker网络 → 容器的80端口
    ↓
Nginx Web服务器
```

### 卷映射配置
```yaml
    volumes:         # 数据持久化 - 宿主机目录:容器目录
      # 环境配置文件（只读）
      - ./.env:/var/www/html/.env:ro

      # 上传文件持久化
      - ./storage/app/public:/var/www/html/storage/app/public
      - ./public/uploads:/var/www/html/public/uploads

      # 日志持久化
      - ./logs:/var/log
```

**卷映射作用：**
- **数据持久化**：容器删除后数据不丢失
- **开发调试**：可以直接在宿主机修改文件
- **日志查看**：方便查看应用日志

**ro参数说明：**
- `:ro`：只读模式（read-only）
- 容器内不能修改.env文件，保证配置安全

### 环境变量配置
```yaml
    environment:     # 环境变量
      # 数据库配置
      DB_HOST: host.docker.internal
      DB_PORT: 3306
      DB_DATABASE: dujiaoka
      DB_USERNAME: root
      DB_PASSWORD: ${DB_PASSWORD}  # 从宿主机环境变量读取

      # Redis配置
      REDIS_HOST: host.docker.internal
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD}

      # 应用配置
      APP_URL: http://127.0.0.1:9595
      APP_ENV: production
      APP_DEBUG: false

      # 其他配置
      TZ: Asia/Shanghai
```

**host.docker.internal解释：**
- Docker提供的特殊地址
- 在容器内指向宿主机（你的电脑）
- 这样容器就能访问你电脑上的MySQL和Redis

**环境变量优先级：**
1. docker-compose.yml中的environment
2. .env文件
3. 系统默认值

### 网络配置
```yaml
    networks:        # 网络连接
      - dujiaoka_network    # 自定义网络
      - bepusdt_default     # 外部网络

    restart: unless-stopped  # 重启策略

    healthcheck:     # 健康检查
      test: ["CMD", "curl", "-f", "http://localhost"]
      interval: 30s    # 检查间隔
      timeout: 10s     # 超时时间
      retries: 3       # 重试次数
```

**网络类型：**
- **自定义网络**：容器间通信
- **外部网络**：连接其他Docker项目网络

**重启策略：**
- `no`：不自动重启
- `always`：总是重启
- `on-failure`：失败时重启
- `unless-stopped`：除非手动停止，否则总是重启

### 数据库初始化服务（可选）
```yaml
  # 数据库初始化服务 (可选)
  db-init:
    image: mysql:8.0  # 使用官方MySQL镜像
    container_name: dujiaoka_db_init
    environment:
      MYSQL_HOST: host.docker.internal
      MYSQL_DATABASE: dujiaoka
      MYSQL_USER: root
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - ./database/init:/docker-entrypoint-initdb.d:ro
    profiles:         # 配置文件
      - db-init       # 只在指定profile下启动
    networks:
      - dujiaoka_network
```

**profiles作用：**
- 可选启动的服务
- 使用 `docker-compose --profile db-init up` 启动

**初始化脚本目录：**
- `/docker-entrypoint-initdb.d`：MySQL容器启动时自动执行的脚本目录

### 网络定义
```yaml
networks:
  dujiaoka_network:    # 自定义网络
    driver: bridge     # 网络驱动类型

  bepusdt_default:     # 外部网络
    external: true     # 声明为外部网络
```

**网络驱动类型：**
- `bridge`：桥接网络（默认）
- `host`：主机网络
- `overlay`：覆盖网络（多主机）

### 数据卷定义
```yaml
volumes:
  dujiaoka_uploads:    # 数据卷名称
    driver: local      # 本地驱动

  dujiaoka_logs:       # 数据卷名称
    driver: local      # 本地驱动
```

**数据卷 vs 绑定挂载：**
- **数据卷**：Docker管理的存储，位置在 `/var/lib/docker/volumes/`
- **绑定挂载**：直接映射宿主机目录（上面用的方式）

## 📁 ./docker 目录配置文件详解

### 1. docker/nginx.conf - Nginx主配置文件

#### 完整配置内容
```nginx
user www-data;
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log warn;

events {
    worker_connections 1024;
    use epoll;
    multi_accept on;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    # 日志格式
    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" "$http_x_forwarded_for"';

    access_log /var/log/nginx/access.log main;

    # 基本配置
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;
    client_max_body_size 100M;

    # Gzip压缩
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types
        text/plain
        text/css
        text/xml
        text/javascript
        application/json
        application/javascript
        application/xml+rss
        application/atom+xml
        image/svg+xml;

    # 包含虚拟主机配置
    include /etc/nginx/conf.d/*.conf;
}
```

#### 逐行详细解释

**第1行：运行用户**
```nginx
user www-data;
```
- `www-data`：Linux系统中Web服务器的标准用户
- 安全考虑：避免使用root用户运行Web服务
- 权限控制：只能访问Web相关文件

**第2-4行：基本设置**
```nginx
worker_processes auto;
pid /run/nginx.pid;
error_log /var/log/nginx/error.log warn;
```
- `worker_processes auto`：工作进程数自动设置（通常等于CPU核心数）
- `pid`：记录nginx主进程ID的文件位置
- `error_log`：错误日志文件路径，`warn`表示只记录警告级别以上

**第6-10行：事件模块**
```nginx
events {
    worker_connections 1024;  # 每个工作进程的最大连接数
    use epoll;               # 使用epoll事件模型（Linux高效I/O模型）
    multi_accept on;         # 允许同时接受多个连接
}
```

**什么是epoll？**
- Linux的高效I/O事件通知机制
- 相比传统的select，支持大量连接
- 适合高并发Web服务

**第12-15行：HTTP模块基础**
```nginx
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
```
- `include`：包含MIME类型配置文件
- `default_type`：默认文件类型（二进制流）

**第17-21行：日志格式**
```nginx
    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
                    '$status $body_bytes_sent "$http_referer" '
                    '"$http_user_agent" "$http_x_forwarded_for"';

    access_log /var/log/nginx/access.log main;
```

**日志变量解释：**
- `$remote_addr`：客户端IP地址
- `$remote_user`：认证用户名
- `[$time_local]`：访问时间
- `"$request"`：完整的HTTP请求行
- `$status`：HTTP状态码（200、404、500等）
- `$body_bytes_sent`：发送给客户端的字节数
- `"$http_referer"`：来源页面URL
- `"$http_user_agent"`：客户端浏览器信息
- `"$http_x_forwarded_for"`：代理服务器添加的客户端IP

**第24-29行：性能优化配置**
```nginx
    sendfile on;                # 启用高效文件传输
    tcp_nopush on;              # 防止网络拥塞
    tcp_nodelay on;             # 禁用Nagle算法，减少延迟
    keepalive_timeout 65;       # 连接保持时间（秒）
    types_hash_max_size 2048;   # MIME类型哈希表大小
    client_max_body_size 100M;  # 最大上传文件大小
```

**性能优化解释：**
- `sendfile on`：直接在内核空间传输文件，避免数据拷贝
- `tcp_nopush on`：将响应头和数据一起发送，减少网络包
- `tcp_nodelay on`：立即发送小数据包，提高实时性

**第31-46行：Gzip压缩配置**
```nginx
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types
        text/plain
        text/css
        text/xml
        text/javascript
        application/json
        application/javascript
        application/xml+rss
        application/atom+xml
        image/svg+xml;
```

**Gzip压缩详解：**
- `gzip on`：启用压缩
- `gzip_vary on`：添加Vary头，告诉代理服务器缓存压缩版本
- `gzip_min_length 1024`：只压缩大于1KB的文件
- `gzip_comp_level 6`：压缩级别（1-9，6是平衡压缩率和CPU使用）
- `gzip_types`：指定压缩的文件类型

**第48-50行：包含虚拟主机配置**
```nginx
    # 包含虚拟主机配置
    include /etc/nginx/conf.d/*.conf;
```
- 包含 `/etc/nginx/conf.d/` 目录下所有 `.conf` 文件
- 这样可以将不同网站的配置分开管理
- 我们的 `default.conf` 就在这里面

### 2. docker/supervisord.conf - 进程管理配置

#### 完整配置内容
```ini
[supervisord]
nodaemon=true
user=root

[program:php-fpm]
command=php-fpm
autostart=true
autorestart=true
priority=5
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g 'daemon off;'
autostart=true
autorestart=true
priority=10
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:laravel-queue]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
priority=20
user=www-data
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

#### 详细解释

**为什么要用Supervisor？**
- Docker容器只能运行一个主进程
- 但我们需要运行：Nginx + PHP-FPM + Laravel队列
- Supervisor是一个进程管理器，可以同时管理多个进程

**全局配置**
```ini
[supervisord]
nodaemon=true  # 前台运行（容器需要）
user=root      # 以root用户运行supervisor
```
- `nodaemon=true`：前台运行，这样容器不会退出
- `user=root`：管理所有服务需要root权限

**PHP-FPM进程**
```ini
[program:php-fpm]
command=php-fpm                    # 启动PHP-FPM服务
autostart=true                     # supervisor启动时自动启动
autorestart=true                   # 进程崩溃时自动重启
priority=5                         # 启动优先级（数字越小越早启动）
stdout_logfile=/dev/stdout         # 标准输出重定向到容器标准输出
stdout_logfile_maxbytes=0         # 日志文件大小限制（0=无限制）
stderr_logfile=/dev/stderr         # 错误输出重定向到容器标准错误
stderr_logfile_maxbytes=0         # 错误日志大小限制
```

**优先级说明：**
- `priority=5`：PHP-FPM先启动
- `priority=10`：Nginx后启动（需要PHP-FPM就绪）
- `priority=20`：Laravel队列最后启动

**Nginx进程**
```ini
[program:nginx]
command=nginx -g 'daemon off;'    # 前台运行nginx
autostart=true
autorestart=true
priority=10                       # 在PHP-FPM之后启动
```
- `daemon off`：关键是这个参数，让nginx前台运行
- 默认nginx会后台运行，容器会认为进程已结束而退出

**Laravel队列进程**
```ini
[program:laravel-queue]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
priority=20
user=www-data                      # 以www-data用户运行
```
**队列参数解释：**
- `queue:work`：Laravel队列工作进程
- `--sleep=3`：没有任务时等待3秒
- `--tries=3`：任务失败最多重试3次

### 3. docker/php.ini - PHP配置文件

#### 完整配置内容
```ini
[PHP]
; 基本设置
memory_limit = 256M
max_execution_time = 300
max_input_time = 300
upload_max_filesize = 100M
post_max_size = 100M

; 错误报告
display_errors = Off
log_errors = On
error_log = /var/log/php_errors.log

; 会话设置
session.save_handler = redis
session.save_path = "tcp://host.docker.internal:6379"
session.gc_maxlifetime = 7200

; OPcache设置
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1

; 其他设置
expose_php = Off
date.timezone = Asia/Shanghai
```

#### 详细解释

**内存和性能设置**
```ini
memory_limit = 256M        # PHP脚本最大内存使用量
max_execution_time = 300   # 脚本最大执行时间（秒）
max_input_time = 300       # 接收输入数据的最大时间
upload_max_filesize = 100M # 最大上传文件大小
post_max_size = 100M       # POST数据最大大小
```
- `memory_limit`：防止单个脚本占用过多内存
- `max_execution_time`：防止单个脚本运行时间过长
- 上传限制根据实际需求调整

**错误报告设置**
```ini
display_errors = Off              # 不在网页上显示错误
log_errors = On                   # 记录错误到日志文件
error_log = /var/log/php_errors.log
```
**安全考虑：**
- `display_errors = Off`：生产环境不显示详细错误信息
- 避免泄露系统信息和代码结构

**Redis会话存储**
```ini
session.save_handler = redis
session.save_path = "tcp://host.docker.internal:6379"
session.gc_maxlifetime = 7200
```
**为什么用Redis存会话？**
- 支持多台服务器共享会话
- 读写速度快
- 会话不会因为服务器重启而丢失
- `session.gc_maxlifetime = 7200`：会话过期时间2小时

**OPcache性能优化**
```ini
opcache.enable = 1                    # 启用OPcache
opcache.enable_cli = 0                # 命令行不启用OPcache
opcache.memory_consumption = 128      # OPcache内存使用量（MB）
opcache.interned_strings_buffer = 8   # 字符串缓冲区大小（MB）
opcache.max_accelerated_files = 4000  # 最大缓存文件数量
opcache.revalidate_freq = 2          # 检查文件更新频率（秒）
opcache.fast_shutdown = 1             # 快速关闭机制
```
**OPcache作用：**
- 将编译后的PHP代码缓存在内存中
- 避免每次请求都重新编译
- 大幅提升PHP性能（通常提升3-5倍）

**其他重要设置**
```ini
expose_php = Off            # 不显示PHP版本信息
date.timezone = Asia/Shanghai  # 时区设置
```
- `expose_php = Off`：安全考虑，不在HTTP头中显示PHP版本
- `date.timezone`：避免时间相关的错误

### 4. docker/default.conf - 虚拟主机配置

#### 完整配置内容
```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html index.htm;

    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;

    # 静态文件缓存
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        add_header X-Content-Type-Options nosniff;
        try_files $uri $uri/ =404;
    }

    # 上传目录
    location /uploads/ {
        alias /var/www/html/public/uploads/;
        try_files $uri $uri/ =404;
    }

    # Laravel路由重写
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP处理
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # PHP设置
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        client_max_body_size 100M;
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    # 禁止访问敏感文件
    location ~ /\. {
        deny all;
    }

    location ~ /\.ht {
        deny all;
    }

    # 错误页面
    error_page 404 /index.php;
    error_page 500 502 503 504 /50x.html;
    location = /50x.html {
        root /usr/share/nginx/html;
    }
}
```

#### 详细解释

**基础设置**
```nginx
server {
    listen 80;                    # 监听80端口
    server_name _;                # 匹配所有域名
    root /var/www/html/public;    # 网站根目录
    index index.php index.html index.htm;  # 默认首页文件
```
- `server_name _`：通配符，匹配所有访问的域名
- `root`：Laravel项目的public目录，这是Web可访问的根目录

**安全头设置**
```nginx
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
```
**安全头作用：**
- `X-Frame-Options`：防止点击劫持攻击
- `X-XSS-Protection`：启用XSS过滤器
- `X-Content-Type-Options`：防止MIME类型嗅探
- `Referrer-Policy`：控制Referrer信息发送

**静态文件缓存**
```nginx
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg)$ {
        expires 1y;                                         # 缓存1年
        add_header Cache-Control "public, immutable";       # 公共且不可变缓存
        add_header X-Content-Type-Options nosniff;
        try_files $uri $uri/ =404;                         # 文件不存在返回404
    }
```
**缓存策略解释：**
- `expires 1y`：浏览器缓存1年
- `Cache-Control "public, immutable"`：告诉浏览器文件不会改变
- 大幅减少重复请求，提升网站速度

**上传目录配置**
```nginx
    location /uploads/ {
        alias /var/www/html/public/uploads/;               # 目录别名
        try_files $uri $uri/ =404;
    }
```
- `alias`：将URL路径映射到文件系统路径
- `/uploads/` URL → `/var/www/html/public/uploads/` 文件路径

**Laravel路由重写**
```nginx
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
```
**这是Laravel的关键配置！**
- `try_files $uri`：先尝试直接访问文件
- `try_files $uri/`：再尝试访问目录
- 最后所有请求都转发给 `index.php`
- `?$query_string`：保持URL参数

**PHP处理配置**
```nginx
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;                       # PHP-FPM服务地址
        fastcgi_index index.php;                           # 默认PHP文件
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;                            # 包含FastCGI参数

        # 性能优化参数
        fastcgi_buffers 16 16k;                            # 缓冲区设置
        fastcgi_buffer_size 32k;
        client_max_body_size 100M;                         # 上传大小限制
        fastcgi_read_timeout 300;                          # 读取超时
        fastcgi_send_timeout 300;                          # 发送超时
    }
```
**FastCGI参数解释：**
- `fastcgi_pass 127.0.0.1:9000`：连接PHP-FPM服务
- `SCRIPT_FILENAME`：告诉PHP-FPM要执行的文件完整路径
- `fastcgi_buffers`：优化大文件传输性能

**安全防护**
```nginx
    location ~ /\. {
        deny all;     # 禁止访问所有隐藏文件
    }

    location ~ /\.ht {
        deny all;     # 禁止访问Apache配置文件
    }
```
**防护目的：**
- 防止访问 `.git`、`.env` 等敏感文件
- 防止访问 `.htaccess` 等配置文件

**错误页面**
```nginx
    error_page 404 /index.php;                     # 404错误交给Laravel处理
    error_page 500 502 503 504 /50x.html;         # 服务器错误显示静态页面
    location = /50x.html {
        root /usr/share/nginx/html;                # 错误页面位置
    }
```

### 5. docker/entrypoint.sh - 容器启动脚本

#### 完整脚本内容
```bash
#!/bin/sh

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
if [ -L /var/log/nginx ]; then
    rm -f /var/log/nginx
    mkdir -p /var/log/nginx
fi

# 确保nginx日志文件存在且可写
touch /var/log/nginx/error.log /var/log/nginx/access.log
chmod 755 /var/log/nginx
chmod 644 /var/log/nginx/*.log

# 启动supervisor
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
```

#### 详细解释

**脚本头部**
```bash
#!/bin/sh
set -e
```
- `#!/bin/sh`：指定脚本解释器（之前问题就出在这里！）
- `set -e`：任何命令返回非零状态时立即退出脚本

**等待数据库连接**
```bash
echo "等待数据库连接..."
while ! nc -z host.docker.internal 3306; do
    sleep 2
done
echo "数据库连接成功"
```
**为什么需要等待？**
- 容器启动可能比数据库服务快
- 如果数据库未就绪，Laravel会报错
- `nc -z host.docker.internal 3306`：检查MySQL端口是否可访问
- `!`：如果端口不可访问，继续等待

**等待Redis连接**
```bash
echo "等待Redis连接..."
while ! nc -z host.docker.internal 6379; do
    sleep 2
done
echo "Redis连接成功"
```
**Redis连接等待：**
- 检查Redis服务是否启动
- 端口6379是Redis的默认端口
- 确保会话存储功能正常

**设置文件权限**
```bash
chown -R www-data:www-data /var/www/html || true
chmod -R 755 /var/www/html || true
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache || true
```
**权限设置解释：**
- `|| true`：即使命令失败也继续执行（避免只读文件系统错误）
- `storage`：Laravel存储目录，需要写入权限
- `bootstrap/cache`：Laravel缓存目录，需要写入权限

**复制Nginx配置**
```bash
mkdir -p /etc/nginx/conf.d || true
cp /var/www/html/docker/default.conf /etc/nginx/conf.d/default.conf || true
```
**为什么要复制？**
- nginx.conf中配置了 `include /etc/nginx/conf.d/*.conf`
- 需要将项目配置复制到nginx期望的位置

**清理Laravel缓存**
```bash
php artisan config:clear    # 清理配置缓存
php artisan cache:clear     # 清理应用缓存
php artisan view:clear      # 清理视图缓存
```
**清理缓存原因：**
- 容器重启后环境可能变化
- 确保使用最新的配置
- 避免缓存导致的奇怪问题

**修复日志目录问题**
```bash
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
**这是解决之前问题的关键代码！**
- 检查 `/var/log/nginx` 是否是符号链接
- 如果是符号链接可能导致循环引用
- 创建真实的目录和文件

**启动所有服务**
```bash
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
```
- `exec`：用supervisor进程替换当前shell进程
- 这样supervisor就成为容器的主进程
- supervisor会启动nginx、php-fpm、laravel-queue三个服务

## 🎯 总结：配置文件关系图

```
Docker容器启动
    ↓
entrypoint.sh 执行
    ├─── 等待数据库和Redis就绪
    ├─── 设置文件权限
    ├─── 复制配置文件
    ├─── 清理Laravel缓存
    └─── 启动supervisor
            ↓
    supervisord 进程管理
    ├─── nginx (80端口，处理Web请求)
    ├─── php-fpm (9000端口，处理PHP代码)
    └─── laravel-queue (后台任务处理)
            ↓
    nginx处理请求
    ├─── 静态文件 (直接返回)
    ├─── PHP文件 (转发给php-fpm)
    └── Laravel路由 (由index.php处理)
```

## 📋 所有配置文件完整列表

现在你应该完全理解Docker部署的每一个配置细节了！每个文件都有其存在的必要性，每行配置都有其具体的作用。

### 配置文件关系总结：
1. **Dockerfile** - 构建镜像的说明书
2. **docker-compose.yml** - 运行容器的指挥官
3. **docker/nginx.conf** - Web服务器全局配置
4. **docker/supervisord.conf** - 进程管理配置
5. **docker/php.ini** - PHP语言配置
6. **docker/default.conf** - 具体网站配置
7. **docker/entrypoint.sh** - 容器启动脚本

这些配置文件共同协作，构建了一个完整、高效的Laravel应用运行环境。