# Docker 多阶段构建详解

本文档详细讲解 Docker 多阶段构建（Multi-stage Build）的概念、用法和最佳实践。

---

## 什么是多阶段构建？

**多阶段构建**允许你在同一个 Dockerfile 中使用多个 `FROM` 指令，每个 `FROM` 开始一个新的构建阶段。

### 核心概念

```
┌──────────────────┐         复制文件         ┌──────────────────┐
│  阶段 1          │  ──────────────────→  │  阶段 2          │
│  (构建环境)      │      COPY --from      │  (运行环境)      │
│                  │                        │                  │
│  - 编译工具      │                        │  - 只保留必要文件 │
│  - 源代码        │                        │  - 镜像体积小     │
│  - 依赖库        │                        │                  │
└──────────────────┘                        └──────────────────┘
      (丢弃)                                    (最终镜像)
```

**关键思想：**
- **阶段 1**：构建环境（包含编译工具、源代码、依赖）
- **阶段 2**：运行环境（只保留编译好的二进制文件）
- **中间阶段被丢弃**，减小最终镜像体积

---

## 基本语法

### 语法 1：使用阶段编号

```dockerfile
# 阶段 1（编号 0）
FROM golang:1.19 AS builder
WORKDIR /app
COPY . .
RUN go build -o myapp

# 阶段 2（编号 1）
FROM alpine:3.16
# 从阶段 0 复制文件（--from=0）
COPY --from=0 /app/myapp /usr/local/bin/
```

### 语法 2：使用阶段名称

```dockerfile
# 阶段 1（命名为 builder）
FROM golang:1.19 AS builder
WORKDIR /app
COPY . .
RUN go build -o myapp

# 阶段 2
FROM alpine:3.16
# 从 builder 阶段复制文件（--from=builder）
COPY --from=builder /app/myapp /usr/local/bin/
```

### 语法 3：不命名阶段（隐式编号）

```dockerfile
FROM composer:2
WORKDIR /app
COPY . .
RUN composer install

FROM php:7.4-fpm-alpine
# 从第一个 FROM 阶段复制（编号 0）
COPY --from=0 /app/vendor /var/www/html/vendor
```

---

## 实际案例

### 案例 1：从外部镜像复制文件

#### 你的 Dockerfile 中的用法

```dockerfile
FROM php:7.4-fpm-alpine

# 从 composer:2 镜像复制 Composer 二进制文件
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

**解析：**
- `composer:2` 是外部的 Docker 镜像（不是当前 Dockerfile 的阶段）
- 直接从该镜像复制文件到当前镜像
- 不需要写 FROM，`--from` 自动拉取该镜像

**优势对比：**

| 方式 | 镜像体积 | 构建时间 | 可靠性 |
|------|----------|----------|--------|
| 传统 curl 安装 | 53.5MB | 慢（需要下载、安装） | 中等 |
| 多阶段构建 | 52MB | 快（直接复制） | 高（官方预编译） |

**传统方式（不推荐）：**
```dockerfile
RUN apk add --no-cache curl \
    && curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer
```

### 案例 2：构建 Go 应用

```dockerfile
# ========== 阶段 1: 编译 ==========
FROM golang:1.19 AS builder

WORKDIR /app

# 复制源代码
COPY go.mod go.sum ./
RUN go mod download

COPY . .

# 编译（静态链接，不需要依赖库）
RUN CGO_ENABLED=0 go build -o myapp .

# ========== 阶段 2: 运行 ==========
FROM alpine:3.16

# 只复制编译好的二进制文件
COPY --from=builder /app/myapp /usr/local/bin/myapp

# 创建非 root 用户
RUN adduser -D -g '' appuser
USER appuser

CMD ["myapp"]
```

**效果对比：**

| 镜像 | 包含内容 | 大小 |
|------|----------|------|
| 单阶段（golang:1.19） | Go 工具链 + 源代码 + 二进制 | **800MB+** |
| 多阶段（alpine） | 只包含二进制文件 | **10MB** |

**节省：99% 的空间！**

### 案例 3：前端资源构建

```dockerfile
# ========== 阶段 1: Node.js 构建 ==========
FROM node:16-alpine AS builder

WORKDIR /app

# 安装依赖
COPY package*.json ./
RUN npm ci --only=production

# 复制源代码并构建
COPY . .
RUN npm run build

# ========== 阶段 2: Nginx 托管 ==========
FROM nginx:alpine

# 只复制构建后的静态文件
COPY --from=builder /app/dist /usr/share/nginx/html

# 复制 nginx 配置
COPY nginx.conf /etc/nginx/nginx.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

**不包含在最终镜像：**
- ❌ Node.js（150MB）
- ❌ npm（50MB）
- ❌ node_modules（300MB）
- ❌ 源代码（10MB）

**最终只包含：**
- ✅ 编译后的 HTML/CSS/JS（2MB）
- ✅ Nginx（10MB）

### 案例 4：Laravel 应用（完整示例）

```dockerfile
# ========== 阶段 1: Composer 依赖 ==========
FROM composer:2 AS composer-stage
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# ========== 阶段 2: 前端资源构建 ==========
FROM node:16-alpine AS node-stage
WORKDIR /app
COPY . .
RUN npm install
RUN npm run build

# ========== 阶段 3: 最终运行环境 ==========
FROM php:7.4-fpm-alpine

# 安装系统依赖
RUN apk add --no-cache \
    nginx \
    supervisor \
    bash

# 安装 PHP 扩展
RUN docker-php-ext-install pdo_mysql bcmath gd opcache

# 从阶段 1 复制 Composer 依赖
COPY --from=composer-stage /app /var/www/html

# 从阶段 2 复制构建后的前端资源
COPY --from=node-stage /app/public /var/www/html/public

# 设置权限
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
```

**最终镜像不包含：**
- ❌ Composer（20MB）
- ❌ Node.js（150MB）
- ❌ npm 源码包（300MB）
- ❌ 构建缓存

---

## 为什么使用多阶段构建？

### 优势 1：减小镜像体积

```
单阶段镜像:
包含: 编译工具 + 源代码 + 依赖 + 二进制文件
大小: 800MB - 2GB

多阶段镜像:
只包含: 二进制文件 + 运行时库
大小: 10MB - 100MB
```

**实际案例对比：**

| 项目 | 单阶段大小 | 多阶段大小 | 减少 |
|------|-----------|-----------|------|
| Go 应用 | 800MB | 10MB | **98.75%** |
| Node.js 应用 | 500MB | 20MB | **96%** |
| Laravel 应用 | 1.2GB | 150MB | **87.5%** |

### 优势 2：提高安全性

```
单阶段镜像:
✅ 二进制文件
❌ GCC 编译器（可被利用）
❌ 源代码（泄露商业逻辑）
❌ 构建脚本
❌ 调试工具（gdb, strace）

多阶段镜像:
✅ 二进制文件
✅ 最小化运行时（攻击面小）
```

**安全优势：**
- 减少攻击面（没有编译工具）
- 不泄露源代码
- 不包含调试工具
- 符合最小权限原则

### 优势 3：加快部署速度

```
镜像大小对比:
单阶段: 800MB
多阶段: 10MB

部署时间（100Mbps 网络）:
单阶段: 800MB ÷ 12.5MB/s = 64 秒
多阶段: 10MB ÷ 12.5MB/s = 0.8 秒

快 80 倍！
```

### 优势 4：简化 CI/CD

```yaml
# .gitlab-ci.yml
build:
  script:
    - docker build -t myapp:$CI_COMMIT_SHA .
    - docker push myapp:$CI_COMMIT_SHA
  # 一个命令完成构建和优化，不需要额外脚本
```

---

## 高级用法

### 1. 多个源阶段

```dockerfile
# 阶段 1: 后端编译
FROM golang:1.19 AS backend-builder
COPY backend/ .
RUN go build -o backend

# 阶段 2: 前端构建
FROM node:16 AS frontend-builder
COPY frontend/ .
RUN npm run build

# 阶段 3: 整合
FROM alpine:3.16
COPY --from=backend-builder /backend /app/
COPY --from=frontend-builder /dist /app/public/
```

### 2. 条件复制

```dockerfile
FROM node:16 AS builder
WORKDIR /app
COPY package*.json ./

# 根据构建参数决定安装哪些依赖
ARG NODE_ENV
RUN if [ "$NODE_ENV" = "production" ]; then \
        npm ci --only=production; \
    else \
        npm ci; \
    fi

FROM alpine:3.16
COPY --from=builder /app /app
```

使用：
```bash
docker build --build-arg NODE_ENV=production -t myapp .
```

### 3. 共享基础层

```dockerfile
# 基础层
FROM alpine:3.16 AS base
RUN apk add --no-cache ca-certificates

# 开发阶段
FROM base AS development
RUN apk add git vim

# 生产阶段
FROM base AS production
# 只包含基础层的 ca-certificates
```

### 4. 跨平台构建

```dockerfile
# 构建阶段（使用 amd64 平台）
FROM --platform=linux/amd64 golang:1.19 AS builder
RUN go build -o myapp

# 运行阶段（支持多平台）
FROM alpine:3.16
COPY --from=builder /app/myapp /usr/local/bin/
```

---

## 最佳实践

### ✅ DO（应该做的）

1. **命名阶段**
```dockerfile
FROM golang:1.19 AS builder  # 清晰的命名
COPY --from=builder ...
```

2. **使用特定标签**
```dockerfile
FROM composer:2.5.5  # 而不是 composer:2
```

3. **清理构建缓存**
```dockerfile
RUN go build -o myapp && \
    rm -rf /var/cache/apk/*  # 在构建阶段清理
```

4. **复制必要文件**
```dockerfile
COPY --from=builder /app/myapp /usr/local/bin/
# 不要复制整个 /app 目录
```

### ❌ DON'T（不应该做的）

1. **在最终镜像安装构建工具**
```dockerfile
# ❌ 错误
FROM alpine:3.16
RUN apk add gcc musl-dev  # 不需要！

# ✅ 正确
FROM golang:1.19 AS builder
RUN go build ...  # 在构建阶段使用
```

2. **复制源代码到最终镜像**
```dockerfile
# ❌ 错误
COPY . /app

# ✅ 正确
COPY --from=builder /app/dist /app
```

3. **忽略层缓存**
```dockerfile
# ❌ 错误（每次都会重新安装依赖）
COPY . .
RUN npm install

# ✅ 正确（利用缓存）
COPY package*.json ./
RUN npm install
COPY . .
```

---

## 常见问题

### Q1: 多阶段构建会增加构建时间吗？

**A:** 不会显著增加。

- 每个阶段会缓存，重复构建时只执行变化的阶段
- 最终镜像拉取/部署时间大幅减少（整体更快）

### Q2: 可以从外部镜像复制吗？

**A:** 可以！就像你的 Dockerfile：

```dockerfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

不需要在当前 Dockerfile 中定义该阶段。

### Q3: 如何查看中间阶段的镜像？

**A:** 构建时使用 `--target` 参数：

```bash
# 只构建到 builder 阶段
docker build --target builder -t myapp:builder .

# 查看 builder 阶段的内容
docker run --rm -it myapp:builder sh
```

### Q4: 多阶段构建会影响运行性能吗？

**A:** 不会！

- 最终镜像只包含运行时必需的文件
- 运行性能与单阶段完全相同
- 镜像体积更小，启动反而更快

### Q5: 可以跨 Dockerfile 复制吗？

**A:** 不可以。

`COPY --from` 只能从：
- 同一个 Dockerfile 的其他阶段
- 外部镜像（如 `composer:2`）

不能从另一个 Dockerfile 的构建产物复制。

---

## 调试技巧

### 1. 查看构建历史

```bash
docker history myapp:latest

# 你会看到多阶段构建只保留最终阶段
# 中间阶段不会出现在最终镜像历史中
```

### 2. 构建特定阶段

```bash
# 只构建到 builder 阶段，用于调试
docker build --target builder -t debug .

# 进入该阶段检查文件
docker run --rm -it debug sh
```

### 3. 查看阶段大小

```bash
docker build --target builder -t builder .
docker images | grep builder
```

### 4. 使用 BuildKit（推荐）

```bash
# 启用 BuildKit（默认已启用）
export DOCKER_BUILDKIT=1

# 构建时查看详细输出
docker build --progress=plain -t myapp .
```

---

## 实际项目示例

### 示例 1：微服务架构

```dockerfile
# ========== API Gateway ==========
FROM node:16 AS gateway-builder
WORKDIR /app
COPY gateway/package*.json ./
RUN npm ci
COPY gateway/ .
RUN npm run build

# ========== User Service ==========
FROM golang:1.19 AS user-builder
WORKDIR /app
COPY user-service/ .
RUN go build -o user-service

# ========== Order Service ==========
FROM golang:1.19 AS order-builder
WORKDIR /app
COPY order-service/ .
RUN go build -o order-service

# ========== 最终镜像（多服务容器） ==========
FROM alpine:3.16

# 安装运行时依赖
RUN apk add --no-cache ca-certificates

# 复制所有服务
COPY --from=gateway-builder /app/dist /app/gateway
COPY --from=user-builder /app/user-service /app/
COPY --from=order-builder /app/order-service /app/

# 使用 Supervisor 管理多个服务
COPY supervisord.conf /etc/supervisor/conf.d/
CMD ["supervisord", "-n"]
```

### 示例 2：你的项目（独角数卡）

```dockerfile
# ========== 阶段 1: Composer 依赖 ==========
FROM composer:2 AS composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader

# ========== 阶段 2: 最终镜像 ==========
FROM php:7.4-fpm-alpine

# 安装系统依赖
RUN apk add --no-cache nginx supervisor bash

# 安装 PHP 扩展
RUN docker-php-ext-install pdo_mysql bcmath gd

# 从外部镜像复制 Composer（你当前的用法）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 从 composer 阶段复制依赖
COPY --from=composer /app /var/www/html

# 设置权限
RUN chown -R www-data:www-data /var/www/html
```

---

## 总结

### 多阶段构建核心价值

| 优势 | 效果 |
|------|------|
| **减小镜像体积** | 减少 80-99% |
| **提高安全性** | 移除构建工具和源代码 |
| **加快部署** | 减少 80%+ 的传输时间 |
| **简化构建** | 一个 Dockerfile 完成所有步骤 |

### 适用场景

✅ **强烈推荐使用：**
- 编译型语言（Go, C++, Rust）
- 前端框架（React, Vue, Angular）
- 微服务架构
- 生产环境部署

⚠️ **可选使用：**
- 解释型语言（PHP, Python）
- 开发环境（可以保留构建工具方便调试）

### 关键命令

```dockerfile
# 命名阶段
FROM image:tag AS stage_name

# 从阶段复制
COPY --from=stage_name /src /dest

# 从外部镜像复制
COPY --from=image:tag /src /dest

# 从编号阶段复制
COPY --from=0 /src /dest
```

### 构建命令

```bash
# 构建所有阶段
docker build -t myapp .

# 只构建到特定阶段（用于调试）
docker build --target builder -t debug .

# 查看构建缓存
docker build --progress=plain -t myapp .
```

---

## 相关文档

- [Dockerfile 多阶段构建官方文档](https://docs.docker.com/build/building/multi-stage/)
- [Dockerfile 详细讲解](./Dockerfile详解.md)
- [Docker 最佳实践](./Docker最佳实践.md)

---

**生成时间：** 2025-12-23
**适用版本：** Docker 20.10+
