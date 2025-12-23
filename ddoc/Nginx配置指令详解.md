# Nginx 配置指令详解

本文档详细讲解 Nginx 中常见的配置指令，包括 `root`、`alias`、`proxy_pass`、`index`、`location` 正则匹配等。

---

## 目录

1. [location 匹配规则](#1-location-匹配规则)
2. [index 指令详解](#2-index-指令详解)
3. [root vs alias vs proxy_pass](#3-root-vs-alias-vs-proxy_pass)
4. [正则表达式详解](#4-正则表达式详解)
5. [实际应用场景](#5-实际应用场景)

---

## 1. location 匹配规则

### 1.1 location 语法类型

| 符号 | 名称 | 匹配方式 | 优先级 | 示例 |
|------|------|---------|--------|------|
| `无符号` | 前缀匹配 | 以指定路径开头 | 低 | `location /admin/` |
| `=` | 精确匹配 | 完全相等 | **最高** | `location = /` |
| `^~` | 前缀匹配（优先） | 以指定路径开头，停止正则搜索 | 高 | `location ^~ /static/` |
| `~` | 正则匹配（区分大小写） | 正则表达式 | 中 | `location ~ \.jpg$` |
| `~*` | 正则匹配（不区分大小写） | 正则表达式 | 中 | `location ~* \.jpg$` |

### 1.2 匹配优先级

```nginx
# 检查顺序（从高到低）：
1. location = /exact/path     # 精确匹配
2. location ^~ /prefix/       # 前缀优先匹配
3. location ~ pattern         # 正则匹配（按配置顺序）
4. location ~* pattern        # 正则不区分大小写
5. location /prefix/          # 普通前缀匹配
6. location /                 # 通用匹配（兜底）
```

### 1.3 实例演示

```nginx
location = / {
    # 只匹配 http://example.com/
    # 不匹配 http://example.com/home
}

location ^~ /static/ {
    # 匹配 /static/ 开头的所有请求
    # 一旦匹配，不再检查正则表达式
}

location ~* \.(jpg|png|gif)$ {
    # 匹配 .jpg、.png、.gif 结尾的请求
    # 不区分大小写
}

location /api/ {
    # 匹配 /api/ 开头的所有请求
    # 如果其他正则也匹配，正则优先级更高
}
```

---

## 2. index 指令详解

### 2.1 基本语法

```nginx
index index.php index.html index.htm;
```

**定义**：当用户访问目录时，Nginx 自动查找并返回的文件优先级顺序。

### 2.2 工作原理

```
用户访问：http://example.com/admin/
    ↓
Nginx 按顺序查找：
1. index.php  → 找到？返回 ✓
2. index.html → 找到？返回 ✓
3. index.htm  → 找到？返回 ✓
4. 都不存在 → 返回 403 Forbidden 或 404
```

### 2.3 实际例子

**目录结构**：
```
/var/www/html/public/
├── index.php          ✓ 存在
├── index.html         ✗ 不存在
└── admin/
    ├── index.php      ✗ 不存在
    └── index.html     ✓ 存在
```

**访问结果**：
- 访问 `/` → 返回 `index.php`
- 访问 `/admin/` → 返回 `admin/index.html`

### 2.4 常见配置

```nginx
# Laravel 应用（必须 index.php 第一）
index index.php;

# 静态网站优先静态文件
index index.html index.htm index.php;

# 支持多种脚本
index index.php index.cgi index.pl index.html;
```

---

## 3. root vs alias vs proxy_pass

### 3.1 对比表

| 特性 | root | alias | proxy_pass |
|------|------|-------|------------|
| **用途** | 指定根目录 | URL 路径别名 | 反向代理 |
| **处理方式** | 返回本地文件 | 返回本地文件 | 转发给其他服务器 |
| **路径处理** | **追加** URI 路径 | **替换** URI 路径 | 传递完整 URI |
| **性能** | 最快 | 快 | 较慢（网络开销） |
| **使用场景** | 静态网站 | 映射非标准目录 | 微服务、API 网关 |

---

### 3.2 root 指令

**语法**：
```nginx
location /uploads/ {
    root /var/www/html/public;
}
```

**工作原理**：
```
请求：/uploads/image.jpg
映射：root + URI
结果：/var/www/html/public/uploads/image.jpg
```

**完整示例**：
```nginx
server {
    root /var/www/html;  # 全局根目录

    location /css/ {
        # /css/style.css → /var/www/html/css/style.css
    }

    location /js/ {
        # /js/app.js → /var/www/html/js/app.js
    }
}
```

---

### 3.3 alias 指令

**语法**：
```nginx
location /uploads/ {
    alias /var/www/html/public/uploads/;
}
```

**工作原理**：
```
请求：/uploads/image.jpg
映射：替换 location 匹配部分
结果：/var/www/html/public/uploads/image.jpg
```

**关键区别**：`alias` **替换** location 路径，`root` **追加** URI 路径

#### 对比示例

假设文件实际位置：`/data/files/photo.jpg`

| 配置 | 请求 | 映射结果 | 是否正确 |
|------|------|---------|---------|
| `root /data/files;` | `/uploads/photo.jpg` | `/data/files/uploads/photo.jpg` | ❌ 错误 |
| `alias /data/files/;` | `/uploads/photo.jpg` | `/data/files/photo.jpg` | ✅ 正确 |

#### ⚠️ 常见陷阱

```nginx
# ❌ 错误：斜杠不一致
location /uploads/ {
    alias /var/www/html/public/uploads;  # 缺少尾部 /
}
# 请求 /uploads/file.jpg → /var/www/html/public/uploadsfile.jpg

# ✅ 正确：保持斜杠一致
location /uploads/ {
    alias /var/www/html/public/uploads/;
}
```

#### 使用场景

```nginx
# 映射到外部存储目录
location /uploads/ {
    alias /data/storage/files/;
}

# CDN 资源映射
location /static/ {
    alias /cdn/assets/;
}

# 主题目录映射
location /theme/ {
    alias /var/www/html/public/themes/default/;
}
```

---

### 3.4 proxy_pass 指令

**语法**：
```nginx
location /api/ {
    proxy_pass http://backend-server:8080;
}
```

**工作原理**：
```
客户端请求：http://nginx-server/api/users
    ↓
Nginx 转发：http://backend-server:8080/api/users
    ↓
后端服务器处理并返回
    ↓
Nginx 返回给客户端（客户端不知道后端服务器存在）
```

**完整配置**：
```nginx
location /api/ {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

**常用指令**：
| 指令 | 作用 |
|------|------|
| `proxy_pass` | 后端服务器地址 |
| `proxy_set_header Host $host` | 传递原始 Host 头 |
| `proxy_set_header X-Real-IP` | 传递客户端真实 IP |
| `proxy_set_header X-Forwarded-For` | 传递代理链路 |
| `proxy_connect_timeout` | 连接超时 |
| `proxy_read_timeout` | 读取超时 |

---

## 4. 正则表达式详解

### 4.1 基本语法

```nginx
location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg)$
```

**逐字符解析**：
| 符号 | 含义 |
|------|------|
| `~*` | 不区分大小写的正则匹配 |
| `\.` | 转义的点号（匹配字面上的 `.`） |
| `(...)` | 分组，包含多个选项 |
| `\|` | "或"运算符 |
| `$` | 字符串结尾（确保是扩展名） |

---

### 4.2 匹配示例

| URL 请求 | 是否匹配 | 原因 |
|----------|---------|------|
| `/logo.png` | ✅ 是 | 扩展名 `.png` 在列表中 |
| `/style.CSS` | ✅ 是 | `~*` 不区分大小写 |
| `/app.js?v=1.2` | ✅ 是 | `$` 只匹配路径，不匹配查询参数 |
| `/image.JPG` | ✅ 是 | 不区分大小写 |
| `/font.woff2` | ✅ 是 | 支持多后缀名 |
| `/photos.zip` | ❌ 否 | `.zip` 不在列表中 |
| `/file.jpg.bak` | ❌ 否 | `$` 确保是最后一段 |

---

### 4.3 常见正则模式

```nginx
# 匹配图片（不区分大小写）
location ~* \.(jpg|jpeg|png|gif|webp|svg)$ {
    expires 1y;
}

# 匹配字体文件
location ~* \.(woff|woff2|ttf|eot|otf)$ {
    expires 1y;
    add_header Cache-Control "public";
}

# 匹配特定路径下的图片
location ~* ^/assets/.*\.(jpg|png|css|js)$ {
    expires 1y;
}

# 匹配多级扩展名（如 .tar.gz）
location ~* \.(tar\.gz|tar\.bz2)$ {
    # 需要转义点号
}

# 排除某个路径
location ~* ^/(?!uploads/).*\.(jpg|png)$ {
    # (?!uploads/) 否定前瞻，不匹配 /uploads/ 路径
}
```

---

## 5. 实际应用场景

### 5.1 静态网站（使用 root）

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html;  # 全局根目录

    location / {
        # 所有请求：/ → /var/www/html/
        try_files $uri $uri/ =404;
    }

    location /images/ {
        # /images/logo.png → /var/www/html/images/logo.png
    }
}
```

---

### 5.2 Laravel 应用（混合使用）

```nginx
server {
    root /var/www/html/public;

    # 静态资源缓存
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        # 直接返回文件，不经过 PHP
    }

    # 上传文件（使用 alias）
    location /uploads/ {
        alias /var/www/html/public/uploads/;
    }

    # PHP 处理（使用 fastcgi_pass）
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        # 相当于 PHP 的 proxy_pass
    }

    # Laravel 路由
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

---

### 5.3 微服务架构（使用 proxy_pass）

```nginx
# 前端静态资源
location / {
    root /var/www/frontend/dist;
    try_files $uri $uri/ /index.html;
}

# 用户服务 API
location /api/users/ {
    proxy_pass http://user-service:3000;
    proxy_set_header Host $host;
}

# 订单服务 API
location /api/orders/ {
    proxy_pass http://order-service:3001;
    proxy_set_header Host $host;
}

# 支付服务 API
location /api/payment/ {
    proxy_pass http://payment-service:3002;
    proxy_set_header Host $host;
}

# 文件上传服务
location /files/ {
    proxy_pass http://file-service:8080;
    client_max_body_size 100M;
}
```

---

### 5.4 CDN 资源映射（使用 alias）

```nginx
# 映射到外部存储
location /cdn/images/ {
    alias /data/storage/images/;
    expires 1y;
}

# 映射到对象存储缓存
location /cdn/videos/ {
    alias /var/cache/nginx/videos/;
    expires 30d;
}

# 主题资源
location /theme/default/ {
    alias /var/www/html/themes/default/assets/;
}
```

---

## 6. 补充：FastCGI vs Proxy

虽然 `fastcgi_pass` 和 `proxy_pass` 都是"转发请求"，但：

| 特性 | fastcgi_pass | proxy_pass |
|------|-------------|------------|
| **协议** | FastCGI 协议 | HTTP/HTTPS 协议 |
| **目标** | PHP-FPM、Python WSGI | 任何 HTTP 服务器 |
| **用途** | PHP/Python 应用 | 反向代理、负载均衡 |
| **示例** | `127.0.0.1:9000` | `http://backend:8080` |
| **配置文件** | `fastcgi_params` | `proxy_set_header` |

**fastcgi_pass 示例**：
```nginx
location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

**proxy_pass 示例**：
```nginx
location /api/ {
    proxy_pass http://backend:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

---

## 7. 总结

### 选择指南

| 场景 | 推荐指令 | 原因 |
|------|---------|------|
| 静态网站根目录 | `root` | 简单直接 |
| 映射非标准路径 | `alias` | 灵活重定向 |
| 转发给后端服务 | `proxy_pass` | 反向代理 |
| PHP 应用 | `fastcgi_pass` | FastCGI 协议 |
| 静态资源缓存 | `location ~*` + `expires` | 性能优化 |

### 性能对比

```
root / alias:      ~1-5ms    (直接读取本地文件)
fastcgi_pass:      ~50-200ms (PHP 处理)
proxy_pass:        ~100-500ms (网络转发 + 后端处理)
```

### 最佳实践

1. **静态资源优先**：使用 `location ~*` 匹配并缓存
2. **明确路径映射**：`alias` 注意斜杠一致性
3. **安全头设置**：`proxy_pass` 记得传递真实 IP
4. **缓存策略**：静态文件长期缓存，动态内容禁用缓存
5. **错误处理**：合理配置 `try_files` 和错误页面

---

## 相关文档

- [Nginx 官方文档 - ngx_http_core_module](https://nginx.org/en/docs/http/ngx_http_core_module.html)
- [Nginx Location 匹配规则详解](https://www.nginx.com/resources/wiki/start/topics/tutorials/install/)
- [独角数卡 Docker 配置](../docker/default.conf)
