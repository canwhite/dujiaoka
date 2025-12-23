# PHP 配置文件说明 (php.ini)

## 概述

`php.ini` 是 PHP 的核心配置文件，用于控制 PHP 的行为、性能、安全性和资源限制。

**本项目中的位置：** `docker/php.ini`
**实际容器内路径：** 通常挂载到 `/usr/local/etc/php/php.ini`

---

## INI 文件语法

### 基本结构

```ini
; 这是注释，使用分号开头

[Section]
directive = value
another_directive = "string value"
```

### 语法规则

| 语法 | 说明 | 示例 |
|------|------|------|
| `; 注释` | 分号开头表示注释，`#` 也可以但不推荐 | `; 这是注释` |
| `[Section]` | 配置节/分组 | `[PHP]`、`[Date]` |
| `directive = value` | 指令 = 值 | `memory_limit = 256M` |
| `布尔值` | On/Off、1/0、true/false | `display_errors = Off` |
| `字符串` | 可以加引号，也可以不加 | `error_log = "/var/log/php.log"` |
| `大小单位` | K、M、G（千字节、兆字节、吉字节） | `memory_limit = 256M` |
| `时间单位` | s（秒） | `max_execution_time = 300` |

### 示例对比

```ini
; ✅ 正确写法
memory_limit = 256M
display_errors = Off
error_log = /var/log/php_errors.log

; ❌ 错误写法
memory_limit: 256M           ; 不能用冒号
display_errors = false       ; 虽然可以，但建议用 Off/On
error_log = "/var/log/php    ; 缺少结束引号
```

---

## 本项目配置详解

### 1. 基本设置

```ini
memory_limit = 256M
```
- **作用：** 单个 PHP 脚本可使用的最大内存
- **何时调整：**
  - 处理大文件上传/导出时
  - 使用内存密集型库（如图片处理、Excel 导入）
  - 出现 `Allowed memory size exhausted` 错误时

```ini
max_execution_time = 300
```
- **作用：** 脚本最大执行时间（秒）
- **何时调整：**
  - 长时间运行的任务（如批量导入、视频转码）
  - API 调用超时时间长
  - 设为 0 表示无限制（不推荐）

```ini
max_input_time = 300
```
- **作用：** 解析输入数据的最大时间（秒）
- **何时调整：**
  - 上传大文件时
  - POST 数据量很大时

```ini
upload_max_filesize = 100M
post_max_size = 100M
```
- **作用：** 限制上传文件大小和 POST 数据大小
- **重要：** `post_max_size` 必须 ≥ `upload_max_filesize`
- **何时调整：**
  - 用户需要上传大文件（视频、大型图片）
  - 导入大型 Excel/CSV 文件
  - 注意：还需调整 Nginx 的 `client_max_body_size`

---

### 2. 错误报告

```ini
display_errors = Off
log_errors = On
error_log = /var/log/php_errors.log
```
- **作用：**
  - `display_errors = Off`：不在浏览器显示错误（安全）
  - `log_errors = On`：记录错误到日志文件
  - `error_log`：指定日志路径
- **何时调整：**
  - **生产环境必须：** `display_errors = Off`
  - **开发环境可以：** `display_errors = On`（方便调试）
  - Docker 容器中确保日志路径可写

---

### 3. 会话设置

```ini
session.save_handler = redis
session.save_path = "tcp://host.docker.internal:6379"
session.gc_maxlifetime = 7200
```
- **作用：**
  - 使用 Redis 存储 Session（而非文件）
  - 连接到宿主机的 Redis（Docker 环境配置）
  - Session 过期时间 7200 秒（2小时）
- **何时使用：**
  - 多服务器/多容器部署（共享 Session）
  - 需要高性能 Session 存储
  - `host.docker.internal` 仅限 Docker Desktop/For Mac

**替代方案：**
```ini
; 文件存储（单机默认）
session.save_handler = files
session.save_path = "/var/lib/php/sessions"

; Memcached
session.save_handler = memcached
session.save_path = "localhost:11211"
```

---

### 4. OPcache 设置

```ini
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
```
- **作用：** 缓存编译后的 PHP 字节码，提升性能
- **何时调整：**
  - **生产环境必须开启**（显著提升性能）
  - `memory_consumption`：根据项目大小调整（64M-256M）
  - `max_accelerated_files`：项目文件数多时调大
  - `revalidate_freq = 0`：开发环境设为 0（代码改动立即生效）

**性能对比：**
- 无 OPcache：每次请求都重新编译 PHP 代码
- 有 OPcache：编译一次，多次执行，性能提升 50-300%

---

### 5. 其他设置

```ini
expose_php = Off
```
- **作用：** 隐藏 PHP 版本号（HTTP 响应头不显示 `X-Powered-By: PHP/8.1`）
- **何时使用：** **生产环境必须开启**（安全最佳实践）

```ini
date.timezone = Asia/Shanghai
```
- **作用：** 设置默认时区
- **何时调整：**
  - 根据服务器/用户所在地区设置
  - 避免 PHP 时间函数报警告

---

## 何时使用 php.ini 配置

### ✅ 需要使用 php.ini 的场景

1. **部署到生产环境**
   - 调整内存、执行时间限制
   - 关闭错误显示，开启日志记录
   - 启用 OPcache 提升性能

2. **Docker 容器化部署**
   - 自定义 PHP 配置（覆盖默认配置）
   - 挂载到容器：`-v $(pwd)/docker/php.ini:/usr/local/etc/php/conf.d/custom.ini`

3. **特定应用需求**
   - Laravel/ThinkPHP 等 Web 应用
   - 需要上传文件、导出数据
   - 需要长连接处理

4. **安全加固**
   - 隐藏 PHP 版本
   - 限制文件上传大小
   - 禁用危险函数（`disable_functions`）

### ❌ 不需要/不建议使用 php.ini 的场景

1. **简单脚本**
   ```bash
   # 命令行脚本可以直接在命令中设置
   php -d memory_limit=512M script.php
   ```

2. **开发调试**
   ```php
   // 在代码中临时设置（不推荐生产环境）
   ini_set('display_errors', 1);
   ini_set('memory_limit', '512M');
   ```

3. **多个应用需要不同配置**
   - 使用 `.user.ini` 或 `.htaccess`（Apache）
   - 在 PHP-FPM pool 配置中设置（`php_admin_value`）

---

## 如何应用配置

### Docker 容器中

```yaml
# docker-compose.yml
version: '3'
services:
  app:
    image: php:8.1-fpm
    volumes:
      - ./docker/php.ini:/usr/local/etc/php/conf.d/custom.ini:ro
```

### 直接修改（不推荐容器环境）

```bash
# 容器内
docker exec -it container_name bash
vi /usr/local/etc/php/php.ini
# 重启 PHP-FPM
docker restart container_name
```

### 验证配置

```bash
# 查看已加载的配置文件
php --ini

# 查看特定配置的值
php -i | grep memory_limit

# 在浏览器访问（包含所有配置信息）
echo "<?php phpinfo(); ?>" > info.php
```

---

## 常见问题

### Q1: 修改 php.ini 后不生效？
**A:**
1. 确认修改的是正确的配置文件（`php --ini` 查看）
2. 重启 PHP-FPM 或容器
3. 检查是否有多个配置文件覆盖（后加载的优先生效）

### Q2: upload_max_filesize 改了还是无法上传大文件？
**A:** 还需要修改：
- Nginx: `client_max_body_size 100M;`
- 确认 `post_max_size >= upload_max_filesize`

### Q3: 如何知道需要多大的 memory_limit？
**A:**
```bash
# 监控实际使用
echo "<?php echo memory_get_peak_usage(true)/1024/1024 . ' MB'; ?>" > test.php
php test.php
```

### Q4: OPcache 导致代码更新不生效？
**A:**
```bash
# 重启 PHP-FPM 清除缓存
docker-compose restart php

# 或设置快速刷新频率（开发环境）
opcache.revalidate_freq = 0
```

---

## 安全建议

```ini
; 生产环境建议添加
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

; 限制文件访问
open_basedir = /var/www/html:/tmp

; 防止文件包含攻击
allow_url_include = Off
allow_url_fopen = On  ; 如果需要读取远程文件
```

---

## 参考链接

- [PHP 官方配置文档](https://www.php.net/manual/zh/ini.list.php)
- [OPcache 配置优化](https://www.php.net/manual/zh/opcache.configuration.php)
- [Docker PHP 镜像配置](https://hub.docker.com/_/php)
