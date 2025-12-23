# WORKDIR 工作目录详解

本文档详细讲解 Dockerfile 中 `WORKDIR` 指令的作用、最佳实践以及为什么 Web 应用通常使用 `/var/www/html`。

---

## 什么是 WORKDIR？

`WORKDIR` 用于设置容器内的**工作目录**，类似于在终端中 `cd` 到某个目录。

### 基本概念

```dockerfile
WORKDIR /var/www/html
```

**效果：**
- 设置容器内的"当前位置"
- 后续所有命令都在这个目录执行
- 目录不存在时会自动创建

---

## 为什么是 `/var/www/html`？

这是一个 **Linux/Unix 世界的历史惯例和行业标准**。

### 历史来源

```
Apache HTTP Server (最早的 Web 服务器)
    │
    ├─ 默认配置：DocumentRoot /var/www/html
    │
    ├─ 所有 Web 文件放在这里
    │
    └─ 成为行业标准
```

### 目录结构解析

```bash
/var/www/html
│
├─ /var     # variable（可变数据，经常变化的内容）
│   └─ /www  # Web 服务器的数据目录
│       └─ /html  # HTML/PHP 文件根目录
```

**各部分含义：**

| 部分 | 含义 | 用途 |
|------|------|------|
| `/var` | Variable | 存放经常变化的文件（日志、缓存、Web 数据） |
| `/www` | Web | Web 服务器的数据目录 |
| `/html` | HTML | HTML/PHP 文件的根目录 |

---

## WORKDIR 的实际效果

### 简化命令对比

#### ❌ 不使用 WORKDIR（繁琐）

```dockerfile
FROM php:7.4-fpm-alpine

COPY . /var/www/html
RUN composer install --working-dir=/var/www/html
RUN php /var/www/html/artisan cache:clear
RUN chmod -R 777 /var/www/html/storage
CMD ["php-fpm", "-y", "/var/www/html/docker/php-fpm.conf"]
```

**问题：**
- 每个命令都要写完整路径
- 代码冗长，难以阅读
- 容易出错

#### ✅ 使用 WORKDIR（简洁）

```dockerfile
FROM php:7.4-fpm-alpine

WORKDIR /var/www/html
COPY . .
RUN composer install
RUN php artisan cache:clear
RUN chmod -R 777 storage
CMD ["php-fpm"]
```

**优势：**
- 代码清晰简洁
- 易于阅读和维护
- 减少错误

### 实际运行示例

```dockerfile
WORKDIR /var/www/html
COPY . .
RUN composer install
RUN php artisan optimize
CMD ["php-fpm"]
```

**实际执行：**

```bash
# 1. WORKDIR 自动创建目录
mkdir -p /var/www/html
cd /var/www/html

# 2. COPY 复制到当前目录
cp -r /tmp/build/* /var/www/html/

# 3. RUN 在当前目录执行
cd /var/www/html && composer install

# 4. CMD 在当前目录执行
cd /var/www/html && php-fpm
```

---

## 不同平台的 Web 目录标准

### 常见 Web 服务器默认目录

| 服务器/系统 | 默认目录 | 用途 |
|------------|----------|------|
| **Apache (Debian/Ubuntu)** | `/var/www/html` | 标准 Apache 目录 |
| **Apache (CentOS/RHEL)** | `/var/www/html` | RedHat 系默认 |
| **Nginx (官方镜像)** | `/usr/share/nginx/html` | Nginx 官方镜像 |
| **Nginx (Ubuntu 仓库)** | `/var/www/html` | Ubuntu 包管理器安装 |
| **IIS (Windows)** | `C:\inetpub\wwwroot` | Windows IIS |
| **XAMPP** | `htdocs` | 开发环境 |
| **Docker PHP 镜像** | `/var/www/html` | Docker 标准 |

### 为什么选择 `/var/www/html` 而不是其他？

#### ❌ 不推荐的目录

```dockerfile
# 1. /app（不符合 Linux 标准）
WORKDIR /app
# 问题：运维人员找不到文件，不熟悉

# 2. /project（太模糊）
WORKDIR /project
# 问题：不知道是什么项目

# 3. /home/www（不是标准）
WORKDIR /home/www
# 问题：/home 用于用户目录，不是服务数据

# 4. /usr/local/www（不是标准）
WORKDIR /usr/local/www
# 问题：/usr/local 用于本地安装的软件，不是应用数据
```

#### ✅ 推荐的目录

```dockerfile
# 1. /var/www/html（行业标准）★★★ 最推荐
WORKDIR /var/www/html
# 优势：所有运维人员都熟悉，文档丰富

# 2. /var/www（也是标准）
WORKDIR /var/www
# 可接受，但不够具体

# 3. /srv/www（FHS 标准）
WORKDIR /srv/www
# 符合 Filesystem Hierarchy Standard
# 但较少使用
```

---

## WORKDIR 在项目中的完整流程

### 与其他配置的配合

#### 1. WORKDIR + Nginx 配置

**docker/default.conf**
```nginx
server {
    root /var/www/html/public;  # ← 指向工作目录下的 public
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

**目录对应关系：**

```
/var/www/html/                    # WORKDIR（工作目录）
│
├─ public/                        # Nginx root（Web 访问根目录）
│   ├─ index.php                 # Laravel 入口文件
│   ├─ uploads/                  # 用户上传的文件
│   └─ static/                   # 静态资源
│
├─ app/                           # 应用代码（不对外）
│   ├─ Models/
│   ├─ Controllers/
│   └─ Services/
│
├─ config/                        # 配置文件（不对外）
├─ database/                      # 数据库文件（不对外）
├─ routes/                        # 路由文件（不对外）
├─ storage/                       # 存储目录（不对外）
├─ vendor/                        # Composer 依赖（不对外）
├─ artisan                        # Laravel 命令行工具
└─ .env                          # 环境配置（敏感文件）
```

**为什么 Nginx root 指向 `public`？**

✅ **安全考虑：**
- 只能访问 `public/` 下的文件
- 无法访问 `app/`、`config/`、`.env` 等敏感文件
- 符合最佳安全实践

#### 2. WORKDIR + PHP-FPM

PHP-FPM 配置也基于工作目录：

```conf
; php-fpm.d/www.conf
user = www-data
group = www-data

; 工作目录
php_admin_value[doc_root]=/var/www/html
```

#### 3. WORKDIR + Supervisor

**docker/supervisord.conf**
```ini
[program:laravel-queue]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3
user=www-data
```

因为 `WORKDIR /var/www/html`，也可以简化为：
```ini
[program:laravel-queue]
command=php artisan queue:work --sleep=3 --tries=3
user=www-data
```

---

## 实际运行流程

### 容器启动后的目录状态

```bash
# 1. 进入容器
docker exec -it dujiaoka sh

# 2. 查看当前目录
pwd
# 输出：/var/www/html

# 3. 查看文件结构
ls -la
# drwxr-xr-x  www-data  app/
# drwxr-xr-x  www-data  bootstrap/
# drwxr-xr-x  www-data  config/
# drwxr-xr-x  www-data  database/
# drwxr-xr-x  www-data  public/
# drwxr-xr-x  www-data  resources/
# drwxr-xr-x  www-data  routes/
# drwxr-xr-x  www-data  storage/
# drwxr-xr-x  www-data  vendor/
# -rw-r--r--  www-data  artisan
# -rw-r--r--  www-data  composer.json
# -rw-r--r--  www-data  .env

# 4. 执行 artisan 命令（不需要指定路径）
php artisan cache:clear
# 因为 WORKDIR 是 /var/www/html，Laravel 知道根目录在哪里
```

### HTTP 请求处理流程

```
用户访问: http://localhost:9595/
    ↓
宿主机端口 9595:80 映射
    ↓
容器 80 端口（Nginx）
    ↓
Nginx root: /var/www/html/public
    ↓
执行 /var/www/html/public/index.php
    ↓
PHP-FPM 处理 PHP 代码
    ↓
Laravel 应用运行在 /var/www/html
    ├─ 读取配置: /var/www/html/config/app.php
    ├─ 加载路由: /var/www/html/routes/web.php
    ├─ 执行控制器: /var/www/html/app/Controllers/
    └─ 返回视图: /var/www/html/resources/views/
    ↓
返回响应给用户
```

---

## WORKDIR 的重要特性

### 1. 自动创建目录

```dockerfile
# 如果目录不存在，WORKDIR 会自动创建
WORKDIR /var/www/html/new/dir
RUN pwd
# 输出：/var/www/html/new/dir（自动创建了整个路径）
```

### 2. 影响所有后续命令

```dockerfile
WORKDIR /var/www/html

RUN composer install           # 在 /var/www/html 执行
COPY . .                       # 复制到 /var/www/html
RUN php artisan optimize       # 在 /var/www/html 执行
CMD ["php-fpm"]                # 工作目录是 /var/www/html
```

### 3. 可以多次设置

```dockerfile
WORKDIR /var/www
RUN echo "step 1" > test.txt

WORKDIR /var/www/html
RUN echo "step 2" > index.html
```

### 4. 可以使用环境变量

```dockerfile
ENV APP_PATH=/var/www/html
WORKDIR ${APP_PATH}
RUN pwd  # 输出：/var/www/html
```

---

## 不同类型应用的 WORKDIR 推荐

### PHP 应用
```dockerfile
WORKDIR /var/www/html  # 行业标准
```

### Node.js 应用
```dockerfile
WORKDIR /usr/src/app   # Node.js 官方推荐
# 或
WORKDIR /app           # 简洁常用
```

### Go 应用
```dockerfile
WORKDIR /app           # Go 常用
# 或
WORKDIR /go/src/app    # Go 传统目录结构
```

### Python 应用
```dockerfile
WORKDIR /app           # Python 常用
# 或
WORKDIR /usr/src/app   # 也可接受
```

### Java 应用
```dockerfile
WORKDIR /app           # 简洁
# 或
WORKDIR /opt/app       # 符合 Linux 标准
```

---

## 修改 WORKDIR 的影响范围

### 如果改成其他目录（例如 `/app`）

```dockerfile
WORKDIR /app
```

**需要同时修改的配置文件：**

#### 1. Nginx 配置

**docker/default.conf**
```nginx
# 修改前
root /var/www/html/public;

# 修改后
root /app/public;
```

#### 2. Supervisor 配置

**docker/supervisord.conf**
```ini
# 修改前
[program:laravel-queue]
command=php /var/www/html/artisan queue:work

# 修改后
[program:laravel-queue]
command=php /app/artisan queue:work
```

#### 3. 启动脚本

**docker/entrypoint.sh**
```bash
# 修改前
chown -R www-data:www-data /var/www/html
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

# 修改后
chown -R www-data:www-data /app
chmod -R 777 /app/storage /app/bootstrap/cache
```

**结论：使用标准目录 `/var/www/html` 可以避免修改多个配置文件！**

---

## WORKDIR vs cd 命令

### ❌ 错误做法

```dockerfile
# cd 在每个 RUN 命令中是独立的
RUN cd /var/www/html && composer install
RUN php artisan optimize  # ← 又回到了根目录！
```

**问题：**
- 每个 `RUN` 命令在新的 shell 中执行
- `cd` 只影响当前命令
- 下一个命令又回到根目录

### ✅ 正确做法

```dockerfile
WORKDIR /var/www/html
RUN composer install
RUN php artisan optimize  # ← 仍在 /var/www/html
```

---

## WORKDIR 最佳实践

### ✅ DO（应该做的）

1. **使用标准目录**
```dockerfile
WORKDIR /var/www/html  # PHP 应用
WORKDIR /app           # 通用应用
```

2. **尽早设置**
```dockerfile
FROM php:7.4-fpm-alpine
WORKDIR /var/www/html  # ← 在早期设置，影响后续所有命令
COPY . .
```

3. **使用绝对路径**
```dockerfile
WORKDIR /var/www/html  # ✅ 清晰
```

### ❌ DON'T（不应该做的）

1. **不要使用相对路径**
```dockerfile
WORKDIR html  # ❌ 不清晰
WORKDIR /var/www/html  # ✅ 明确
```

2. **不要依赖 cd**
```dockerfile
RUN cd /var/www/html && command  # ❌
WORKDIR /var/www/html            # ✅
RUN command
```

3. **不要在根目录工作**
```dockerfile
WORKDIR /  # ❌ 污染根目录，不安全
```

---

## 常见问题

### Q1: WORKDIR 会增加镜像大小吗？

**A:** 不会。

`WORKDIR` 只是设置环境变量，不复制文件。

### Q2: 可以在 CMD 中覆盖工作目录吗？

**A:** 可以。

```dockerfile
WORKDIR /var/www/html
CMD ["sh", "-c", "cd /tmp && command"]  # 覆盖工作目录
```

### Q3: docker-compose 中的 working_dir 会覆盖吗？

**A:** 会的。

```yaml
services:
  app:
    build: .
    working_dir: /app  # 覆盖 Dockerfile 中的 WORKDIR
```

### Q4: 如何查看容器的工作目录？

**A:** 使用 `pwd` 命令。

```bash
docker exec -it dujiaoka pwd
# 输出：/var/www/html
```

### Q5: WORKDIR 和 VOLUME 的关系？

**A:** 它们是独立的。

```dockerfile
WORKDIR /var/www/html
VOLUME /var/www/html/storage  # 挂载存储目录
```

- `WORKDIR`：设置工作目录
- `VOLUME`：创建挂载点（数据持久化）

---

## 调试技巧

### 1. 查看构建时的工作目录

```dockerfile
WORKDIR /var/www/html
RUN pwd && ls -la
```

### 2. 比较有无 WORKDIR 的区别

```dockerfile
# 测试 1: 有 WORKDIR
FROM alpine
WORKDIR /test
RUN echo "hello" > file.txt

# 测试 2: 无 WORKDIR
FROM alpine
RUN echo "hello" > file.txt  # 文件在根目录
```

### 3. 进入容器验证

```bash
docker run --rm -it dujiaoka sh
pwd  # 查看 WORKDIR 是否生效
```

---

## 总结

### WORKDIR 核心作用

| 作用 | 说明 |
|------|------|
| **简化命令** | 不需要每次都写完整路径 |
| **明确意图** | 一眼看出这是 Web 应用目录 |
| **自动创建** | 目录不存在时自动创建 |
| **影响后续** | 所有后续命令都在这个目录执行 |

### 为什么是 `/var/www/html`？

1. **行业标准**：Apache、Nginx、PHP 都默认使用这个目录
2. **运维习惯**：所有运维人员都知道 Web 文件在哪里
3. **减少配置**：不需要修改 Nginx、PHP-FPM 的默认配置
4. **文档丰富**：遇到问题时，搜索结果都指向这个目录

### 实际效果对比

#### 不使用 WORKDIR
```dockerfile
COPY . /var/www/html
RUN cd /var/www/html && composer install
RUN cd /var/www/html && php artisan optimize
CMD ["php-fpm", "-y", "/var/www/html/docker/php-fpm.conf"]
```

#### 使用 WORKDIR
```dockerfile
WORKDIR /var/www/html
COPY . .
RUN composer install
RUN php artisan optimize
CMD ["php-fpm"]
```

**节省 40% 的代码量，提升可读性！**

---

## 类比理解

### WORKDIR 就像...

**在文件管理器中：**
1. 双击打开 `/Users/zack/Desktop/dujiaoka` 文件夹
2. 之后打开的文件都在这个文件夹里
3. 不需要每次都输入完整路径

**在终端中：**
```bash
cd /Users/zack/Desktop/dujiaoka  # 设置工作目录
vim Dockerfile                     # 直接写文件名，不需要完整路径
cat docker-compose.yml             # 同样不需要完整路径
```

**Docker WORKDIR 也是一样的道理：**
```dockerfile
WORKDIR /var/www/html  # 设置工作目录
COPY . .                # 复制到工作目录
RUN composer install    # 在工作目录执行
CMD ["php-fpm"]         # 在工作目录执行
```

---

## 相关文档

- [Dockerfile 详细讲解](./Dockerfile详解.md)
- [Docker 多阶段构建详解](./Docker多阶段构建详解.md)
- [Nginx 配置详解](./nginx配置详解.md)
- [Supervisor 配置详解](./supervisor配置详解.md)

---

**生成时间：** 2025-12-23
**适用版本：** Docker 20.10+
**项目：** 独角数卡（Dujiaoka）
