# Task: 调研支付token失败问题

**任务ID**: task_investigate_payment_token_260102
**创建时间**: 2026-01-02
**状态**: 已完成
**目标**: 通过Docker日志分析为什么调用三方接口自动支付token没成功

## 最终目标
找到三方接口自动支付token失败的根本原因，并提供解决方案

## 拆解步骤

### 1. 收集Docker日志信息
- [x] 查看Docker容器状态
- [x] 获取所有容器的日志
- [x] 重点关注Laravel应用日志和支付相关日志

### 2. 分析日志中的错误信息
- [x] 搜索API Hook相关日志
- [x] 搜索支付回调相关日志
- [x] 搜索token相关错误

### 3. 检查相关代码逻辑
- [x] 检查ApiHook.php中的token传递逻辑
- [x] 检查OrderController.php中的from参数捕获
- [x] 检查.env配置

### 4. 复现问题（如果可能）
- [x] 模拟支付流程
- [x] 观察日志输出

### 5. 总结问题原因
- [x] 整理问题点
- [x] 提出解决方案

## 当前进度

### 正在进行: 总结问题原因和解决方案
所有调研步骤已完成，正在整理问题和解决方案

## 调研结果

### 关键发现

#### 1. bepusdt 通知失败
从 bepusdt 日志中发现：
```
订单号: PCNU98GIVLJRVJOV
notify_url: http://localhost:9595/pay/epusdt/notify_url
notify_num: 3
notify_state: 0 (表示通知失败)
```

**问题**: bepusdt 尝试了 3 次通知，但每次都失败了（notify_state 始终为 0）

#### 2. Docker 网络问题
**根本原因**: 在 Docker 网络中，`localhost` 指向 bepusdt 容器自己，而不是 dujiaoka_app 容器

当 bepusdt 尝试访问 `http://localhost:9595/pay/epusdt/notify_url` 时：
- 它访问的是 bepusdt 容器内部的 9595 端口
- 但 Laravel 应用运行在 dujiaoka_app 容器中
- 所以连接失败，通知无法送达

#### 3. 连锁反应
```
bepusdt 通知失败
    ↓
Laravel EpusdtController::notifyUrl() 未被调用
    ↓
OrderProcessService::completedOrder() 未执行
    ↓
ApiHook::dispatch($order) 未触发
    ↓
novel-api 充值接口未调用
    ↓
用户 token 未充值
```

### 相关代码位置

1. **支付回调处理**: `app/Http/Controllers/Pay/EpusdtController.php:72-101`
   - notifyUrl() 方法负责接收 bepusdt 的通知
   - 第 98 行调用 `completedOrder()` 处理订单

2. **订单完成处理**: `app/Service/OrderProcessService.php:385-444`
   - 第 438 行调用 `ApiHook::dispatch($order)`
   - 这里会触发三方平台充值

3. **notify_url 生成**: `app/Http/Controllers/Pay/EpusdtController.php:32`
   ```php
   'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
   ```
   - 使用 Laravel 的 `url()` 函数生成 URL
   - 基于 `APP_URL` 配置

### 配置检查

#### Laravel 配置
```bash
APP_URL=http://dujiaoka.test
NOVEL_API_URL=http://novel-api:8080/api/v1/users/recharge
```

#### bepusdt 配置
```bash
merchant_pem: http://bepusdt:8085/api/v1/order/create-transaction
```

**问题**: bepusdt 的 notify_url 被设置为 `http://localhost:9595/...`，而不是 Docker 内部网络地址 `http://dujiaoka_app/...`

## 解决方案

### 方案 1: 修改 APP_URL（推荐）

将 Laravel 的 APP_URL 改为外部可访问的域名或 IP：

```bash
# .env
APP_URL=http://your-domain.com  # 或 http://your-ip:9595
```

然后重启容器：
```bash
docker-compose restart dujiaoka
```

**优点**:
- bepusdt 可以从外部访问 Laravel
- 适合生产环境

**缺点**:
- 需要有外部可访问的域名或 IP

### 方案 2: 使用 Docker 内部网络地址

修改 EpusdtController.php 中的 notify_url 生成逻辑，使用 Docker 内部网络地址：

```php
// app/Http/Controllers/Pay/EpusdtController.php:32
'notify_url' => 'http://dujiaoka_app' . $this->payGateway->pay_handleroute . '/notify_url',
```

**优点**:
- 不依赖外部网络
- 适合开发和测试环境

**缺点**:
- 需要修改代码
- 不适合生产环境

### 方案 3: 使用 docker-compose 网络别名

在 docker-compose.yml 中为 dujiaoka 服务添加网络别名：

```yaml
services:
  dujiaoka:
    networks:
      - default
      - payment
    # ...

networks:
  payment:
    name: payment_network

# bepusdt 使用相同的网络
```

然后 notify_url 使用别名：
```bash
APP_URL=http://dujiaoka:80
```

## 推荐步骤

1. **立即修复**: 使用方案 1，修改 .env 文件：
   ```bash
   APP_URL=http://localhost:9595  # 如果是本地开发
   # 或
   APP_URL=http://your-domain.com  # 如果是生产环境
   ```

2. **重启服务**:
   ```bash
   docker-compose restart dujiaoka
   ```

3. **测试验证**:
   - 创建新订单并支付
   - 检查 bepusdt 日志，确认 notify_state 变为 1
   - 检查 novel-api 日志，确认收到充值请求

4. **长期优化**: 考虑使用方案 3，建立独立的支付网络

## 下一步行动
