# default.conf 使用路径追踪

本文档详细追踪 `docker/default.conf` 在整个 Docker 项目中的使用路径，帮助理解配置文件如何从源代码到最终生效的完整过程。

---

## 概述

`docker/default.conf` 是 Nginx 的虚拟主机配置文件，它定义了网站如何处理 HTTP 请求。本文档追踪它从源代码到 Nginx 实际使用的完整路径。

---

## 完整使用路径

### 步骤 1：Dockerfile 构建镜像

**文件：** `Dockerfile:54`

```dockerfile
COPY . .
```

**发生了什么：**
- 将宿主机的整个项目目录（包括 `docker/default.conf`）复制到容器的 `/var/www/html/`

**容器内文件结构：**
```
/var/www/html/
├─ docker/
│   ├─ default.conf       ← 在这里
│   ├─ nginx.conf
│   ├─ supervisord.conf
│   ├─ php.ini
│   └─ entrypoint.sh
├─ public/
├─ app/
├─ config/
├─ routes/
├─ storage/
├─ vendor/
├─ artisan
└─ ...
```

**说明：**
- 此时 `default.conf` 还在项目目录中
- Nginx 还看不到它
- 需要复制到 Nginx 的配置目录

---

### 步骤 2：容器启动，执行 entrypoint.sh

**文件：** `Dockerfile:77`

```dockerfile
CMD ["/usr/local/bin/entrypoint.sh"]
```

**容器启动时自动执行 `/usr/local/bin/entrypoint.sh`**

**说明：**
- `CMD` 是容器启动时的默认命令
- 每次容器启动都会执行 entrypoint.sh
- 这是动态配置的机会

---

### 步骤 3：entrypoint.sh 复制 default.conf

**文件：** `docker/entrypoint.sh:24-26`

```bash
# 复制虚拟主机配置
mkdir -p /etc/nginx/conf.d || true
cp /var/www/html/docker/default.conf /etc/nginx/conf.d/default.conf || true
```

**发生了什么：**
- 从 `/var/www/html/docker/default.conf` 复制到 `/etc/nginx/conf.d/default.conf`
- 这是 Nginx 的标准虚拟主机配置目录
- `mkdir -p` 确保目标目录存在
- `|| true` 确保即使复制失败也不会中断启动

**复制后的位置：**
```
/etc/nginx/
├─ nginx.conf              # 主配置（Dockerfile:65 已复制）
└─ conf.d/
    └─ default.conf        ← 现在在这里
```

**为什么要这样做？**
- Nginx 不会自动从 `/var/www/html/docker/` 读取配置
- 必须把配置文件放到 Nginx 的标准配置目录
- 通过在 entrypoint.sh 中复制，可以支持配置覆盖

---

### 步骤 4：nginx.conf 加载 default.conf

**文件：** `docker/nginx.conf:49`（已复制到 `/etc/nginx/nginx.conf`）

```nginx
http {
    # ... 其他配置 ...

    # 包含虚拟主机配置
    include /etc/nginx/conf.d/*.conf;  # ← 加载 default.conf
}
```

**发生了什么：**
- Nginx 启动时读取 `/etc/nginx/nginx.conf`
- 在 `http {}` 块中执行 `include /etc/nginx/conf.d/*.conf`
- 加载 `/etc/nginx/conf.d/default.conf`（虚拟主机配置）

**Nginx 配置加载顺序：**
```
1. /etc/nginx/nginx.conf（主配置）
    ↓
2. events { } 块
    ↓
3. http { } 块
    ├─ Gzip 设置
    ├─ 日志格式
    ├─ client_max_body_size
    └─ include conf.d/*.conf  ← 这里
        ↓
4. 加载 /etc/nginx/conf.d/default.conf
        ↓
5. 应用 server { } 块配置
```

---

### 步骤 5：Nginx 应用 default.conf 的配置

**文件：** `/etc/nginx/conf.d/default.conf`（来自 `docker/default.conf`）

```nginx
server {
    listen 80;                      # 监听 80 端口
    server_name _;                  # 接受所有域名
    root /var/www/html/public;      # 网站根目录

    # Laravel 路由重写
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;  # 转发到 PHP-FPM
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**配置生效：**
- Nginx 监听 80 端口
- 请求静态文件（图片、CSS、JS）
- 转发 `.php` 文件到 PHP-FPM（127.0.0.1:9000）
- 实现 Laravel 路由重写

---

## 完整流程图

```
┌─────────────────────────────────────────────────────────┐
│ 1. Dockerfile 构建                                       │
│    COPY . . → 复制整个项目到镜像                         │
│                                                          │
│    宿主机: ./docker/default.conf                         │
│           ↓                                              │
│    容器:   /var/www/html/docker/default.conf            │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 2. 容器启动                                              │
│    CMD ["/usr/local/bin/entrypoint.sh"]                 │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 3. entrypoint.sh 执行                                    │
│    第 26 行:                                             │
│    cp /var/www/html/docker/default.conf                 │
│       /etc/nginx/conf.d/default.conf                    │
│                                                          │
│    复制到 Nginx 标准配置目录                             │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 4. Supervisor 启动 Nginx                                 │
│    [program:nginx]                                       │
│    command=nginx -g 'daemon off;'                       │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 5. Nginx 读取主配置                                      │
│    nginx -c /etc/nginx/nginx.conf                       │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 6. nginx.conf 加载虚拟主机配置                           │
│    http { } 块中:                                        │
│    include /etc/nginx/conf.d/*.conf;                    │
│    → 加载 /etc/nginx/conf.d/default.conf                │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 7. Nginx 应用 default.conf 的 server 配置                │
│    - listen 80                                           │
│    - root /var/www/html/public                          │
│    - location / { try_files ... }  (Laravel 路由)       │
│    - location ~ \.php$ { fastcgi_pass ... }  (PHP 处理) │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 8. Nginx 开始处理 HTTP 请求                              │
│    ├─ 静态文件直接返回                                   │
│    ├─ .php 文件转发到 PHP-FPM                           │
│    └─ 其他请求转发到 Laravel (index.php)                │
└─────────────────────────────────────────────────────────┘
```

---

## 关键代码位置总结

| 文件 | 行号 | 代码 | 作用 |
|------|------|------|------|
| **Dockerfile** | 54 | `COPY . .` | 将整个项目（包括 `docker/default.conf`）复制到镜像的 `/var/www/html/` |
| **Dockerfile** | 65 | `COPY ./docker/nginx.conf ...` | 将 nginx.conf 复制到 `/etc/nginx/nginx.conf` |
| **entrypoint.sh** | 26 | `cp ... /etc/nginx/conf.d/default.conf` | 将 `default.conf` 复制到 Nginx 配置目录 |
| **nginx.conf** | 49 | `include /etc/nginx/conf.d/*.conf;` | 加载所有虚拟主机配置（包括 default.conf） |
| **Supervisor** | - | 启动 Nginx 进程 | Nginx 启动时应用配置 |

---

## 文件位置变化

```
源文件（宿主机）
└─ ./docker/default.conf
        ↓ (docker build)
镜像中（构建后）
└─ /var/www/html/docker/default.conf
        ↓ (entrypoint.sh)
运行时（容器启动）
└─ /etc/nginx/conf.d/default.conf
        ↓ (nginx.conf include)
Nginx 加载
└─ server { listen 80; root ... }
```

---

## 为什么需要这样设计？

### 1. 分离配置和代码

```
/var/www/html/          # 应用代码
└─ docker/              # 配置文件（与代码一起管理）
    ├─ nginx.conf
    ├─ default.conf
    ├─ supervisord.conf
    └─ php.ini

/etc/nginx/             # 系统配置
├─ nginx.conf           # 主配置
└─ conf.d/
    └─ default.conf     # 虚拟主机配置
```

**优势：**
- ✅ 配置文件版本控制（与代码一起提交到 Git）
- ✅ 容易修改和覆盖（通过挂载卷）
- ✅ 符合 12-Factor App 原则（配置与代码分离）

### 2. 为什么不直接在 Dockerfile 中 COPY？

#### ❌ 错误做法

```dockerfile
# 直接复制到 Nginx 配置目录
COPY ./docker/default.conf /etc/nginx/conf.d/default.conf
```

**问题：**
- 配置硬编码到镜像中
- 修改配置需要重新构建镜像
- 无法通过挂载卷动态覆盖

#### ✅ 正确做法

```dockerfile
# 方案 1: 复制整个项目（当前做法）
COPY . .  # docker/default.conf 也在其中

# entrypoint.sh 中复制
cp /var/www/html/docker/default.conf /etc/nginx/conf.d/
```

**优势：**
- 可以通过挂载卷覆盖配置：
  ```yaml
  # docker-compose.yml
  volumes:
    - ./custom-default.conf:/etc/nginx/conf.d/default.conf:ro
  ```
- 不需要重新构建镜像
- 配置灵活性更高

---

## default.conf 配置详解

### 完整内容

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
        try_files $uri $uri/ =404;
    }

    # Laravel 路由重写
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        client_max_body_size 100M;
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    # 禁止访问敏感文件
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 配置作用

| 配置项 | 作用 |
|--------|------|
| `listen 80` | 监听 80 端口（HTTP） |
| `root /var/www/html/public` | 网站根目录指向 Laravel 的 public 目录 |
| `location / { try_files ... }` | Laravel 路由重写，所有请求转发到 index.php |
| `location ~ \.php$` | PHP 文件转发到 PHP-FPM（127.0.0.1:9000） |
| `fastcgi_pass 127.0.0.1:9000` | 连接到本地的 PHP-FPM |
| `expires 1y` | 静态文件缓存 1 年 |

---

## 验证方法

### 在运行中的容器里验证

```bash
# 1. 进入容器
docker exec -it dujiaoka sh

# 2. 查看当前目录（应该是工作目录）
pwd
# 输出：/var/www/html

# 3. 查看 default.conf 是否存在
ls -la /var/www/html/docker/default.conf
# 输出：-rw-r--r-- 1 www-data www-data ... default.conf

# 4. 查看复制后的文件
ls -la /etc/nginx/conf.d/default.conf
# 输出：-rw-r--r-- 1 root root ... /etc/nginx/conf.d/default.conf

# 5. 比较两个文件是否相同
diff /var/www/html/docker/default.conf /etc/nginx/conf.d/default.conf
# 无输出 = 文件相同

# 6. 查看 default.conf 内容
cat /etc/nginx/conf.d/default.conf | head -20
# 应该看到 server { listen 80; ... }

# 7. 测试 Nginx 配置语法
nginx -t
# 输出：
# nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
# nginx: configuration file /etc/nginx/nginx.conf test is successful

# 8. 查看 Nginx 实际加载的完整配置
nginx -T 2>&1 | grep -A 15 "server {"
# 应该能看到 default.conf 的 server 块内容

# 9. 查看 Nginx 进程
ps aux | grep nginx
# 输出：root ... nginx: master process
#       www-data ... nginx: worker process
```

---

## 如果删除 default.conf 会怎样？

### 测试场景

```bash
# 1. 重命名或删除 default.conf
docker exec -it dujiaoka mv /var/www/html/docker/default.conf /var/www/html/docker/default.conf.bak

# 2. 重启容器
docker-compose restart

# 3. 观察结果
```

### 预期结果

| 影响 | 说明 |
|------|------|
| **容器能启动** | entrypoint.sh 有 `|| true`，不会报错 |
| **Nginx 能启动** | nginx.conf 语法正确，没有 default.conf 也能启动 |
| **访问 404** | 没有 server 块配置，Nginx 不知道如何处理请求 |
| **PHP 无法执行** | `location ~ \.php$` 配置缺失，PHP 文件无法转发到 PHP-FPM |
| **Laravel 路由失效** | `try_files $uri /index.php` 配置缺失，路由不工作 |

### 完整故障现象

```bash
# 访问网站
curl http://localhost:9595/

# 可能返回：
# 1. 404 Not Found
# 2. 403 Forbidden
# 3. Nginx 默认欢迎页（如果有的话）

# 访问 PHP 文件
curl http://localhost:9595/index.php

# 可能返回：
# 1. 404 Not Found
# 2. 二进制文件下载（PHP 源码泄露！）
```

---

## 修改 default.conf 的方法

### 方法 1：修改源文件并重新构建

```bash
# 1. 编辑源文件
vim ./docker/default.conf

# 2. 重新构建镜像
docker-compose build

# 3. 重启容器
docker-compose up -d
```

### 方法 2：挂载自定义配置（推荐）

```yaml
# docker-compose.yml
services:
  dujiaoka:
    volumes:
      # 挂载自定义配置
      - ./custom-default.conf:/etc/nginx/conf.d/default.conf:ro
```

**优势：**
- 不需要重新构建镜像
- 可以快速测试配置
- 配置文件可以单独管理

### 方法 3：在容器内直接修改（不推荐）

```bash
# 进入容器
docker exec -it dujiaoka sh

# 编辑配置
vi /etc/nginx/conf.d/default.conf

# 测试配置
nginx -t

# 重载配置（平滑重启，不中断服务）
nginx -s reload
```

**劣势：**
- 容器重启后配置丢失
- 不符合 Docker 最佳实践

---

## 相关配置文件

### nginx.conf vs default.conf

| 配置文件 | 位置 | 作用 | 作用域 |
|---------|------|------|--------|
| **nginx.conf** | `/etc/nginx/nginx.conf` | Nginx 主配置 | 全局（所有虚拟主机） |
| **default.conf** | `/etc/nginx/conf.d/default.conf` | 虚拟主机配置 | 单个网站 |

### nginx.conf 内容

```nginx
user www-data;
worker_processes auto;
pid /run/nginx.pid;

events {
    worker_connections 1024;
    use epoll;
}

http {
    include /etc/nginx/mime.types;

    # Gzip 压缩（全局）
    gzip on;
    gzip_comp_level 6;

    # 上传大小限制（全局）
    client_max_body_size 100M;

    # 加载虚拟主机配置
    include /etc/nginx/conf.d/*.conf;  # ← 加载 default.conf
}
```

### default.conf 内容

```nginx
server {
    listen 80;
    root /var/www/html/public;

    # 这个网站特定的配置
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

---

## 常见问题

### Q1: 为什么不能在 Dockerfile 中直接 COPY？

**A:** 可以，但不够灵活：

```dockerfile
# 硬编码方式（不灵活）
COPY ./docker/default.conf /etc/nginx/conf.d/default.conf
```

**当前方式的优势：**
- 可以通过挂载卷覆盖配置
- 配置文件与应用代码一起版本控制
- 支持多环境配置（dev/staging/prod）

### Q2: entrypoint.sh 中的 `|| true` 是什么意思？

**A:** 容错处理：

```bash
cp /var/www/html/docker/default.conf /etc/nginx/conf.d/default.conf || true
```

**作用：**
- 如果 `cp` 命令失败，脚本不会退出
- 继续执行后续命令
- 给容器启动提供容错空间

### Q3: 如果我挂载了自定义配置，entrypoint.sh 会覆盖吗？

**A:** 取决于挂载的时机和方式：

```yaml
volumes:
  # 场景 1: 挂载源文件
  - ./custom-default.conf:/var/www/html/docker/default.conf:ro
  # → entrypoint.sh 会复制到 /etc/nginx/conf.d/

  # 场景 2: 直接挂载目标文件（推荐）
  - ./custom-default.conf:/etc/nginx/conf.d/default.conf:ro
  # → entrypoint.sh 的 cp 会失败（因为有 || true 不会中断）
  # → 但你的配置已经生效
```

### Q4: 如何确认 Nginx 使用了哪个配置文件？

**A:** 使用以下命令：

```bash
# 查看 Nginx 加载的所有配置
docker exec dujiaoka nginx -T 2>&1 | less

# 查看 server 块来自哪个文件
docker exec dujiaoka nginx -T 2>&1 | grep -B 5 "server {"

# 查看默认 server 块
docker exec dujiaoka nginx -T 2>&1 | grep -A 20 "server_name _;"
```

---

## 最佳实践

### ✅ DO（推荐）

1. **配置文件版本控制**
```bash
git add docker/default.conf
git commit -m "update nginx config"
```

2. **使用挂载卷覆盖配置**
```yaml
# docker-compose.yml
volumes:
  - ./docker/default.conf:/etc/nginx/conf.d/default.conf:ro
```

3. **修改配置后测试**
```bash
docker exec dujiaoka nginx -t
docker-compose restart
```

4. **使用环境变量管理配置**
```nginx
# envsubst 模板
server_name ${SERVER_NAME};
```

### ❌ DON'T（不推荐）

1. **不要在容器内直接修改配置**
```bash
# 容器重启后丢失
docker exec dujiaoka vi /etc/nginx/conf.d/default.conf
```

2. **不要硬编码敏感信息**
```nginx
# ❌ 错误
fastcgi_pass 192.168.1.100:9000;

# ✅ 正确
fastcgi_pass ${PHP_FPM_HOST}:9000;
```

3. **不要忽略配置测试**
```bash
# 每次修改后都要测试
nginx -t  # ← 必须执行
```

---

## 总结

### `docker/default.conf` 使用路径

```
源文件（宿主机开发）
  ./docker/default.conf
        ↓ (docker build + COPY . .)
镜像构建时
  /var/www/html/docker/default.conf
        ↓ (entrypoint.sh:26)
容器启动时
  /etc/nginx/conf.d/default.conf
        ↓ (nginx.conf:49 include)
Nginx 加载时
  server { listen 80; root /var/www/html/public; ... }
        ↓
HTTP 请求处理
  - 静态文件直接返回
  - PHP 转发到 127.0.0.1:9000
  - Laravel 路由重写
```

### 关键点

1. **Dockerfile:54** - `COPY . .` 把配置文件复制到镜像
2. **entrypoint.sh:26** - 把配置复制到 Nginx 配置目录
3. **nginx.conf:49** - `include /etc/nginx/conf.d/*.conf` 加载配置
4. **Nginx 启动** - 应用配置，处理 HTTP 请求

### 核心价值

- ✅ 配置文件与应用代码一起管理
- ✅ 支持配置覆盖和动态更新
- ✅ 符合 Docker 最佳实践
- ✅ 灵活性高，易于维护

---

**生成时间：** 2025-12-23
**项目：** 独角数卡（Dujiaoka）
**Docker 版本：** 20.10+
