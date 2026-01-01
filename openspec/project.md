# Project Context

## Purpose
**独角数卡 (Dujiaoka)** - 开源式站长自动化售货解决方案

一个基于 Laravel 的虚拟商品自动化售货系统，主要用于：
- **发卡业务**：自动发放虚拟卡密、激活码等
- **代充服务**：通过 API 回调实现第三方充值服务
- **多渠道支付**：集成国内外主流支付接口
- **订单管理**：完整的订单生命周期管理

项目目标：提供高效、稳定、开源的自动化售货解决方案，支持站长快速搭建虚拟商品销售平台。

## Tech Stack

### Backend
- **PHP 7.4+** (推荐 7.4)
- **Laravel 6.x** - PHP Web 框架
- **dcat/laravel-admin 2.x** - 后台管理系统
- **MySQL 5.6+** - 关系型数据库
- **Redis** - 缓存和消息队列
- **Composer** - PHP 包管理器

### Frontend
- **Bootstrap** - UI 框架
- **Laravel Mix** - 前端构建工具
- **Sass** - CSS 预处理器
- **Axios** - HTTP 客户端
- **jQuery** - JavaScript 库

### Infrastructure
- **Nginx 1.16+** - Web 服务器
- **Supervisor** - 进程管理器（管理 Laravel 队列进程）
- **Docker** - 容器化部署（支持）

### Payment Integrations
- 支付宝（当面付、PC支付、手机支付）
- 微信支付（PayJS、企业扫码、码支付）
- PayPal
- Stripe
- V免签
- 其他通用彩虹支付接口

## Project Conventions

### Code Style
- **PSR-4 自动加载**：命名空间映射到目录结构 (`App\` → `app/`)
- **Laravel 标准命名**：
  - 控制器：`XxxController.php`
  - 模型：单数形式 `Order.php`
  - 数据库表：复数形式 `orders`
  - 迁移：`YYYY_MM_DD_HHMMSS_create_xxx_table.php`
- **中文注释为主**：核心业务逻辑使用中文注释说明
- **Helper 函数**：自定义全局函数放在 `app/Helpers/functions.php`

### Architecture Patterns

#### MVC 架构
- **Models** (`app/Models/`): Eloquent ORM 模型，数据层
- **Views** (`resources/views/`): Blade 模板，支持多主题切换
- **Controllers** (`app/Http/Controllers/`): 请求处理和业务逻辑

#### 队列异步处理
- **支付回调**：通过 Laravel Queue 异步处理，避免阻塞
- **API 回调**：自动发货和充值使用队列任务 (`app/Jobs/`)
- **队列驱动**：生产环境必须使用 Redis，不使用 sync 同步模式

#### 多主题系统
- 主题目录：`resources/views/{theme_name}/`
- 配置项：`tpl_name` 控制前台模板切换
- 内置主题：unicorn（默认）、luna、hyper

#### 支付流程
```
用户下单 → 支付请求 → 支付平台 → 支付回调(异步) → 订单更新 → API回调(异步) → 自动发货/充值
```

### Testing Strategy
- 项目主要依赖手动测试和集成测试
- 使用 PHPUnit（`phpunit/phpunit`）作为测试框架
- 重点测试：
  - 支付回调处理的幂等性
  - API 回调的成功率
  - 队列任务的异常处理

### Git Workflow
- **主分支**：`master`
- **功能开发**：直接在 `master` 分支或功能分支
- **提交规范**：使用约定式提交（Conventional Commits）
  - `feat:` 新功能
  - `fix:` 问题修复
  - `docs:` 文档更新
  - `refactor:` 代码重构
- **项目文档**：重要修改同步更新到 `ddoc/` 目录

## Domain Context

### 核心业务概念

1. **订单状态流转**：
   - ` unpaid`（待支付）
   - ` pending`（处理中，支付已确认）
   - ` success`（已完成，发货/充值成功）
   - ` close`（已关闭）

2. **商品类型**：
   - **卡密商品**：系统自动发放卡密
   - **代充商品**：通过 API 调用第三方充值接口

3. **支付回调机制**：
   - 同步回调：用户支付后跳转回来（不可靠）
   - 异步回调：支付平台服务器主动通知（可靠，作为最终依据）
   - **幂等性处理**：防止重复回调导致重复发货

4. **API Hook 机制**：
   - 配置第三方 API 接口用于自动充值
   - 支持多种参数映射（账号、邮箱等）
   - 失败自动重试机制

### 重要文件说明
- `app/Jobs/ApiHook.php`: API 回调任务，处理代充逻辑
- `app/Http/Controllers/PayController.php`: 支付控制器
- `app/Helpers/functions.php`: 全局辅助函数
- `resources/views/{theme}/`: 前端主题模板

## Important Constraints

### 技术约束
- **PHP 版本**：必须 7.4（8.0 未完全测试）
- **必须开启的 PHP 函数**：`putenv`, `proc_open`, `pcntl_signal`, `pcntl_alarm`
- **必须安装的扩展**：`fileinfo`, `redis`, `opcache`（推荐）
- **不支持 Windows 服务器**：未测试，建议使用 Linux
- **不支持虚拟主机**：需要独立服务器或 VPS

### 安全约束
- **支付回调验证**：必须验证签名，防止伪造回调
- **SQL 注入防护**：使用 Eloquent ORM 参数绑定
- **CSRF 防护**：Laravel 默认开启，支付回调路由需排除
- **HTTPS**：生产环境强烈推荐开启
- **敏感配置**：`.env` 文件不可提交到版本控制

### 业务约束
- **幂等性要求**：支付回调和 API 回调必须支持重复调用
- **队列监控**：Supervisor 必须保持队列进程运行
- **Redis 持久化**：配置合理的持久化策略防止数据丢失

### 法律合规
- 项目仅用于学习交流，不可用于违反法律法规的用途
- 用户自行承担使用过程中的法律责任
- 遵守 MIT 开源协议

## External Dependencies

### 支付服务
- **支付宝开放平台**：https://open.alipay.com
- **微信支付**：https://pay.weixin.qq.com
- **PayPal**：https://www.paypal.com
- **Stripe**：https://stripe.com
- **PayJS**：http://payjs.cn

### 第三方服务
- **充值 API**：用户自行配置的第三方充值接口
- **邮件服务**：通过 Laravel Mail 系统，支持 SMTP 等

### 官方资源
- **GitHub 仓库**：https://github.com/assimon/dujiaoka
- **Telegram 群组**：https://t.me/dujiaoka
- **官方频道**：https://t.me/dujiaoshuka
- **在线文档**：https://github.com/assimon/dujiaoka/wiki

### 开发工具
- **JetBrains**：提供开源许可证支持
- **Docker Hub**：提供官方 Docker 镜像
