# 独角数卡 (dujiaoka) - 生产环境状态

> **最后更新**: 2026-01-06
> **版本**: v1.0 (基于dujiaoka)
> **维护者**: Claude Code

---

## 📋 项目定位

独角数卡是一个基于Laravel开发的自动发卡系统，支持：
- ✅ 多种支付方式（支付宝、微信、PayPal等）
- ✅ 自动发货和人工处理两种订单类型
- ✅ API Hook回调机制（支持第三方充值）
- ✅ 卡密库存管理
- ✅ 优惠券系统
- ✅ 邮件通知

---

## 🏗️ 核心架构

### 订单处理流程

```
用户下单
    ↓
OrderController::createOrder()
    ↓
OrderProcessService::createOrder()
    ↓
用户支付
    ↓
支付回调 (AlipayController::notifyUrl)
    ↓
OrderProcessService::completedOrder()
    ↓
判断订单类型:
    ├─ AUTOMATIC_DELIVERY → processAuto()
    └─ MANUAL_PROCESSING → processManual()
    ↓
ApiHook::dispatch($order) ← 异步队列任务
    ↓
根据from参数路由:
    ├─ from=novel → callNovelApi()
    ├─ from=game → callGameApi()
    └─ 默认 → sendDefaultApiHook()
```

### 关键服务

- **OrderService**: 订单查询和验证
- **OrderProcessService**: 订单创建和处理
- **CarmisService**: 卡密管理
- **GoodsService**: 商品管理
- **EmailtplService**: 邮件模板

---

## 🛠️ 技术栈

### 后端
- **框架**: Laravel 8.x
- **数据库**: MySQL 5.7+
- **缓存/队列**: Redis
- **PHP**: 7.4+

### 前端
- **模板引擎**: Blade
- **后台管理**: Dcat Admin
- **UI框架**: Bootstrap/Luna

---

## 📁 目录结构

```
dujiaoka/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── Home/
│   │       │   └── OrderController.php    # 订单控制器
│   │       └── Pay/
│   │           └── AlipayController.php    # 支付回调
│   ├── Jobs/
│   │   └── ApiHook.php                     # API回调任务 ⭐
│   ├── Service/
│   │   ├── OrderProcessService.php         # 订单处理服务 ⭐
│   │   ├── OrderService.php
│   │   └── CarmisService.php
│   └── Models/
│       └── Order.php
├── config/
│   └── app.php
├── database/
│   └── sql/
│       └── install.sql
├── resources/
│   └── views/
├── storage/
│   └── logs/
│       └── laravel.log                     # 日志文件
├── public/
├── schema/                                 # 项目文档 ⭐
│   ├── archive/                            # 已归档任务
│   └── task_*.md                           # 进行中的任务
├── ddoc/                                   # 详细文档
├── openspec/                               # OpenSpec变更管理
└── .env                                    # 环境配置
```

---

## ⚙️ 部署流程

### 1. 环境准备

```bash
# 安装依赖
composer install

# 配置环境变量
cp .env.example .env
vim .env

# 生成应用密钥
php artisan key:generate
```

### 2. 数据库初始化

```bash
# 导入数据库结构
mysql -u root -p dujiaoka < database/sql/install.sql

# 运行迁移
php artisan migrate
```

### 3. 权限设置

```bash
chmod -R 755 storage
chmod -R 755 bootstrap/cache
```

### 4. 启动队列

```bash
# 手动启动（测试）
php artisan queue:work

# 使用Supervisor（生产环境）
# /etc/supervisor/conf.d/laravel-worker.conf
```

### 5. 配置Web服务器

**Nginx配置示例**:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/dujiaoka/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
    }
}
```

---

## 🔑 关键配置

### .env 配置项

#### 基础配置
```bash
APP_NAME=独角数卡
APP_URL=https://your-domain.com
```

#### 数据库配置
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dujiaoka
```

#### Redis配置
```bash
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=
REDIS_PORT=6379
```

#### 队列配置
```bash
QUEUE_CONNECTION=redis  # 使用异步队列
```

#### 三方平台充值配置 ⭐
```bash
# 是否使用卡密发货（true: 使用卡密，false: 不使用卡密）
RECHARGE_USE_CARMIS=false

# 小说网站充值API地址
NOVEL_API_URL=http://novel-api:8080/api/v1/users/recharge

# 支付成功后重定向URL
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
```

---

## 🔧 重要特性

### 1. API Hook 机制

**用途**: 支付成功后调用第三方API自动充值

**流程**:
1. 用户从第三方网站跳转（`?from=novel`）
2. OrderController捕获from参数并存储到`order.info`
3. 支付成功后触发ApiHook异步任务
4. 根据from参数路由到对应的充值API
5. 验证API响应的业务状态（`response['success']`）
6. 记录详细日志

**技术细节**:
- **队列分发**: 使用Laravel的`Dispatchable` trait，通过`ApiHook::dispatch($order)`推送到Redis队列
- **异步处理**: 在`OrderProcessService::completedOrder()`的事务提交后触发，确保数据一致性
- **HMAC签名**: 为API请求生成`HMAC-SHA256`签名，防止请求篡改和重放攻击
- **安全验证**: 验证签名参数包括`actual_price`, `email`, `order_sn`, `timestamp`

**相关文件**:
- `app/Jobs/ApiHook.php`
- `app/Http/Controllers/Home/OrderController.php:72-76`

### 2. 卡密发货控制

**环境变量**: `RECHARGE_USE_CARMIS`

**模式说明**:
- `true`: 使用卡密发货（检查卡密库存，发放卡密给用户）
- `false`: 不使用卡密（直接标记订单完成，适用于API Hook充值）

**相关文件**:
- `app/Service/OrderProcessService.php:489-584`

### 3. 充值账号智能提取

**逻辑**:
1. 优先从`order.info`中提取"充值账号: xxx"
2. 如果提取失败，使用订单邮箱作为备用方案
3. 验证账号不为空

**相关文件**:
- `app/Jobs/ApiHook.php:135-160`

---

## 📊 数据库表结构

### orders (订单表)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 主键 |
| order_sn | varchar(32) | 订单号 |
| goods_id | int | 商品ID |
| title | varchar(255) | 订单标题 |
| type | int | 订单类型 (1=自动发货, 2=人工处理) |
| email | varchar(255) | 邮箱 |
| info | text | 订单详情（包含充值账号和from参数） |
| actual_price | decimal | 实际支付金额 |
| status | int | 订单状态 (1=待支付, 2=待处理, 3=已完成, 4=异常) |
| created_at | timestamp | 创建时间 |

### goods (商品表)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 主键 |
| gd_name | varchar(255) | 商品名称 |
| type | int | 商品类型 |
| api_hook | varchar(255) | API回调地址 |
| in_stock | int | 库存数量 |

---

## 🚀 常用命令

### Laravel命令

```bash
# 清除缓存
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 队列操作
php artisan queue:work                    # 启动队列
php artisan queue:restart                 # 重启队列
php artisan queue:failed                  # 查看失败任务
php artisan queue:retry all               # 重试所有失败任务

# 日志查看
tail -f storage/logs/laravel.log
tail -f storage/logs/laravel.log | grep "API Hook"
```

### 数据库操作

```bash
# 查看最新订单
mysql -u root -p dujiaoka -e "SELECT order_sn, info, status FROM orders ORDER BY id DESC LIMIT 10;"

# 查看商品配置
mysql -u root -p dujiaoka -e "SELECT id, gd_name, type, api_hook FROM goods;"
```

---

## 🔍 日志监控

### 关键日志关键词

- **API Hook**: `grep "API Hook" storage/logs/laravel.log`
- **充值成功**: `grep "API Hook充值成功" storage/logs/laravel.log`
- **充值失败**: `grep "API Hook.*失败" storage/logs/laravel.log`
- **无卡密发货**: `grep "订单自动完成（无卡密发货）" storage/logs/laravel.log`
- **订单创建**: `grep "createOrder" storage/logs/laravel.log`

---

## 🛡️ 安全建议

1. **环境变量**: 不要将`.env`文件提交到版本控制
2. **API密钥**: 定期更换NOVEL_API_URL等API密钥
3. **HTTPS**: 生产环境必须启用HTTPS
4. **队列监控**: 使用Supervisor保持队列运行
5. **日志轮转**: 配置日志轮转，避免磁盘占满

---

## 📞 故障排查

### 常见问题

**Q: API Hook没有被调用？**
- 检查Laravel队列是否运行: `ps aux | grep queue:work`
- 检查商品是否配置了`api_hook`
- 查看日志: `tail -f storage/logs/laravel.log | grep "API Hook"`

**Q: 充值失败但订单已完成？**
- 检查API响应格式: 必须包含`success`字段
- 查看错误日志: `grep "API Hook业务失败" storage/logs/laravel.log`

**Q: from参数没有保存？**
- 检查OrderController.php是否包含from参数捕获代码
- 清除缓存: `php artisan config:clear`
- 查看订单info字段: `SELECT info FROM orders ORDER BY id DESC LIMIT 1;`

---

## 📝 更新日志

### 2026-01-06 - ApiHook机制技术分析

**分析内容**:
- computeHMACSignature函数详细分析（HMAC-SHA256签名生成逻辑）
- ApiHook完整调用路径分析（从支付回调到异步队列分发）
- Dispatchable trait和Laravel队列机制解析
- 事务与队列协同设计模式

**技术要点**:
- 签名参数：`actual_price`, `email`, `order_sn`, `timestamp`
- 调用路径：支付回调 → completedOrder() → ApiHook::dispatch() → Redis队列 → 异步处理
- 设计模式：发布-订阅模式，事务提交后触发异步任务

**相关文档**:
- `schema/task_understand_hmac_260106_163505.md` - 完整技术分析报告

### 2026-01-05 - 多项任务归档

**任务归档**:
- 三方充值接口请求代码修改（HMAC签名、时间戳校验、幂等性校验）
- 充值失败日志分析（排查三方平台充值失败原因）
- from参数传递调查（验证订单中的from参数存储机制）
- 支付成功重定向修复（修复novel网站支付成功后的跳转问题）
- TRX配置导出（导出Tron网络配置供其他服务使用）
- Docker日志分析（分析Docker容器日志，排查问题）
- Docker监控分析（分析容器资源使用和性能）
- 配置安装器（安装脚本配置验证和优化）
- novel-api详细日志分析（诊断充值接口400错误问题）
- novel-api日志分析续（解决good_id字段类型不匹配问题）
- Docker构建修复（解决composer install SSL连接和git PATH问题）

**归档状态**:
- ✅ 所有任务文档已归档至 `schema/archive/`
- ✅ 相关代码修改已实施
- ✅ 项目状态已更新

**相关文档**:
- `schema/archive/task_recharge_api_modify_260105_164313.md` - 三方充值接口修改
- `schema/archive/task_recharge_failure_analysis_260105_173237.md` - 充值失败分析
- `schema/archive/task_from_investigation_260103_120932.md` - from参数调查
- `schema/archive/task_fix_redirect_260103_134417.md` - 重定向修复
- `schema/archive/task_export_trx_config_260104_152923.md` - TRX配置导出
- `schema/archive/task_docker_logs_analysis_260105_171814.md` - Docker日志分析
- `schema/archive/task_docker_monitor_analysis_260105_180836.md` - Docker监控分析
- `schema/archive/task_config_installer_260103_204830.md` - 配置安装器

### 2026-01-02 - Docker网络支付通知修复

**问题描述**:
- bepusdt 支付通知失败（notify_state=0，3次重试均失败）
- 导致 ApiHook 任务未触发，用户 token 未充值

**根本原因**:
- Docker 网络中，Laravel 生成的 notify_url 使用 `localhost`
- bepusdt 容器内的 `localhost` 指向 bepusdt 自己，无法访问 dujiaoka_app 容器

**修复方案**:
- ✅ 修改 EpusdtController.php，notify_url 改用 Docker 服务名 `http://dujiaoka`
- ✅ bepusdt 通过 Docker 内部网络访问 dujiaoka_app

**影响文件**:
- `app/Http/Controllers/Pay/EpusdtController.php:33`

**相关文档**:
- `schema/archive/task_fix_payment_token_260102.md` - 修复任务文档
- `schema/archive/task_investigate_payment_token_260102.md` - 问题调查报告

### 2026-01-02 - NOVEL_REDIRECT_URL配置澄清

**问题描述**:
- 用户误将 NOVEL_REDIRECT_URL 配置为 `http://host.docker.internal:3000`

**澄清说明**:
- NOVEL_REDIRECT_URL 用于**浏览器端跳转**（window.location.href）
- 不是服务器端调用，因此不应使用 Docker 特殊域名
- 用户浏览器运行在主机上，应使用主机视角的地址

**正确配置**:
- ✅ `NOVEL_REDIRECT_URL=http://127.0.0.1:3000`
- ✅ 已更新 .env.example

**相关文档**:
- `schema/archive/task_investigate_novel_redirect_url_260102.md` - 配置调查报告

---

### 2026-01-02 - 卡密并发安全修复

**问题描述**:
- 多个用户同时购买可能导致同一张卡密发放给多个用户
- 缺少并发冲突检测和重试机制

**修复方案**:
- ✅ P0: 乐观锁机制（UPDATE时检查status=1）
- ✅ P0: 重试机制（最多3次，随机延迟100-200ms）
- ✅ P1: 详细日志记录（记录并发冲突、重试过程）

**影响文件**:
- `app/Service/CarmisService.php` - 添加乐观锁检查
- `app/Service/OrderProcessService.php` - 添加重试机制

**相关文档**:
- `schema/archive/task_analyze_carmis_260102.md` - 问题分析和修复详情
- `schema/archive/test_concurrent_carmis_fix_260102.md` - 测试指南

---

### 2026-01-02 - 三方平台自动充token修复 (完整版)

**问题描述**: 用户支付成功后，三方平台(novel网站)的token未能自动充值

**修复历程**: 通过三个阶段的渐进调试，彻底解决问题

**阶段1: notify_url 拼接错误修复** (task_recharge_debug_260102_200233)
- **问题**: bepusdt 日志显示 `http://dujiaokapay/epusdt/notify_url`
- **根本原因**: URL拼接缺少斜杠
- **修复方案**: EpusdtController.php:33
  ```php
  'notify_url' => 'http://dujiaoka/' . trim($this->payGateway->pay_handleroute, '/') . '/notify_url',
  ```

**阶段2: ApiHook 逻辑错误修复** (task_deep_debug_recharge_260102_201429)
- **问题**: Laravel 日志显示 "商品未配置API Hook，跳过"
- **根本原因**: ApiHook 先检查 api_hook 字段，导致 from=novel 的订单无法执行充值逻辑
- **修复方案**: ApiHook.php 完整重构
  - 先提取 from 参数，再决定执行路径
  - from=novel 时，直接调用 novel-api，不检查 api_hook
  - from 为空时，才检查 api_hook 配置

**阶段3: 全面分析准备** (task_full_analysis_260102_203046)
- **状态**: 准备全面分析，但实际未执行
- **原因**: 前两个阶段的修复已解决问题

**最终修复内容**:
- ✅ EpusdtController.php:33 - notify_url 拼接修复
- ✅ ApiHook.php:58-372 - 完整重构路由和充值逻辑
  - from 参数提取和路由分发
  - 小说充值 API 调用逻辑
  - 充值账号智能提取（备用方案）
  - 响应验证机制（区分 HTTP 失败和业务失败）
  - 详细日志记录

**影响文件**:
- `app/Http/Controllers/Pay/EpusdtController.php`
- `app/Jobs/ApiHook.php`

**相关文档**:
- `schema/task_recharge_debug_260102_200233.md` - 阶段1: notify_url修复
- `schema/task_deep_debug_recharge_260102_201429.md` - 阶段2: ApiHook逻辑修复
- `schema/task_full_analysis_260102_203046.md` - 阶段3: 全面分析准备
- `schema/task_summary_recharge_260102_212535.md` - 总结任务
- `ddoc/recharge_fix_final_summary.md` - 完整修复总结文档

---

## 📚 相关文档

### 项目文档
- `schema/archive/` - 已完成的任务归档
- `ddoc/` - 详细的技术文档
- `openspec/` - OpenSpec变更管理

### 外部文档
- [Laravel文档](https://laravel.com/docs/8.x)
- [Dcat Admin文档](https://dcatadmin.com/docs/)

---

**维护记录**:
- 2026-01-02: 创建production.md，记录项目全局状态
- 2026-01-02: 完成三方平台自动充token修复（P0/P1/P2）
- 2026-01-02: 修复 Docker 网络支付通知问题（notify_url 使用服务名）
- 2026-01-02: 完成卡密并发安全修复（乐观锁 + 重试机制）
- 2026-01-06: 完成ApiHook机制技术分析（HMAC签名、调用路径、队列机制）
