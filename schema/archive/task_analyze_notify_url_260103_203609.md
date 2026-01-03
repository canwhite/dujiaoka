# Task: 分析notify_url的拼接逻辑和Docker网络通信

**任务ID**: task_analyze_notify_url_260103_203609
**创建时间**: 2026-01-03 20:36:09
**完成时间**: 2026-01-03 20:40:00
**状态**: 已完成
**目标**: 深入理解notify_url的拼接原理和Docker网络内部通信机制

## 最终目标
1. 分析notify_url的完整拼接逻辑
2. 理解Docker内部网络通信机制
3. 解释为什么是bepusdt访问独角卡
4. 梳理支付回调的完整流程

## 拆解步骤

### 1. 分析notify_url的拼接逻辑 ✅
- [x] 分析拼接前的各个组成部分
- [x] 计算拼接后的完整URL
- [x] 理解trim()函数的作用

### 2. 理解Docker网络通信 ✅
- [x] 分析Docker内部网络架构
- [x] 理解为什么使用dujiaoka服务名
- [x] 对比外部访问和内部访问的区别

### 3. 分析支付回调流程 ✅
- [x] bepusdt如何调用notify_url
- [x] 独角卡如何处理回调请求
- [x] 回调失败的常见原因

### 4. 总结最佳实践 ✅
- [x] Docker网络中的URL配置规范
- [x] 回调URL的调试方法

## 当前进度
### 分析完成 ✅

已完成notify_url拼接逻辑和Docker网络通信的完整分析。

## notify_url拼接逻辑详解

### 原始代码
**文件**: `app/Http/Controllers/Pay/EpusdtController.php:32-33`

```php
// ⭐ Docker内部网络：bepusdt通过服务名访问dujiaoka
'notify_url' => 'http://dujiaoka/' . trim($this->payGateway->pay_handleroute, '/') . '/notify_url',
```

### 拼接组成分析

#### 1️⃣ 固定前缀：`'http://dujiaoka/'`
- **协议**: `http://`
- **主机名**: `dujiaoka`
- **作用**: Docker内部网络中的服务名，指向dujiaoka_app容器
- **关键**: 不是localhost或域名，而是Docker服务名

#### 2️⃣ 变量部分：`trim($this->payGateway->pay_handleroute, '/')`
- **来源**: 数据库表 `payment.pay_handleroute`
- **实际值**: `'pay/epusdt'`（从路由配置推断）
- **trim作用**: 去除首尾的斜杠，避免重复斜杠
  - `trim('/pay/epusdt/', '/')` → `'pay/epusdt'`
  - `trim('pay/epusdt', '/')` → `'pay/epusdt'`

#### 3️⃣ 固定后缀：`'/notify_url'`
- **作用**: 指定回调路由路径
- **对应路由**: `Route::post('epusdt/notify_url', 'EpusdtController@notifyUrl')`

### 拼接后的完整URL

```
http://dujiaoka/pay/epusdt/notify_url
    ↓         ↓    ↓      ↓
    ↓         ↓    ↓      回调路由
    ↓         ↓    控制器路径
    ↓         Docker服务名
    HTTP协议
```

**完整URL示例**:
```php
'notify_url' => 'http://dujiaoka/pay/epusdt/notify_url'
```

## Docker网络通信机制

### Docker网络架构

#### 服务配置（docker-compose.yml）
```yaml
services:
  dujiaoka:                    # 独角数卡应用
    container_name: dujiaoka_app
    networks:
      - dujiaoka_network
      - bepusdt_default        # ⭐ 与bepusdt在同一网络
      - novelapi

networks:
  dujiaoka_network:
    driver: bridge
  bepusdt_default:             # ⭐ 外部网络（bepusdt的）
    external: true
  novelapi:
    external: true
```

#### 网络拓扑
```
┌─────────────────────────────────────────────────┐
│ bepusdt_default 网络 (外部网络)                  │
│                                                 │
│  ┌──────────────┐          ┌──────────────┐   │
│  │  bepusdt     │◄────────►│ dujiaoka_app │   │
│  │  容器        │  同网络   │  容器        │   │
│  └──────────────┘          └──────────────┘   │
│       ↓                           ↑           │
│    调用notify_url            响应请求         │
└─────────────────────────────────────────────────┘
```

### 为什么是bepusdt访问独角卡？

#### 支付回调流程
```
1. 用户下单
   ↓
2. 独角卡调用 bepusdt API 创建支付
   ↓
3. bepusdt 返回支付URL，用户跳转支付
   ↓
4. 用户在 bepusdt 完成支付
   ↓
5. ⭐ bepusdt 需要通知独角卡"支付成功"
   ↓
6. bepusdt 调用 notify_url
   URL: http://dujiaoka/pay/epusdt/notify_url
   ↓
7. 独角卡处理回调，完成订单
```

#### 关键点
- **主动方**: bepusdt（支付网关）
- **被动方**: dujiaoka（独角数卡）
- **通信方向**: bepusdt → dujiaoka
- **通信目的**: 异步通知支付结果

### 为什么使用服务名"dujiaoka"？

#### Docker内部网络特性

| 访问方式 | 在bepusdt容器中的含义 | 是否可行 |
|---------|---------------------|---------|
| `http://localhost` | bepusdt容器自己 | ❌ 错误 |
| `http://host.docker.internal` | bepusdt的宿主机 | ❌ 错误 |
| `http://dujiaoka` | dujiaoka_app容器 | ✅ 正确 |
| `http://dujiaoka_app` | dujiaoka_app容器 | ✅ 正确 |

#### 服务名解析
```bash
# 在beprusdt容器内测试
docker exec bepusdt ping dujiaoka
# 会解析到dujiaoka_app容器的IP

docker exec bepusdt curl http://dujiaoka/pay/epusdt/notify_url
# 可以访问独角卡应用
```

## 完整的支付回调流程

### 流程图

```
┌─────────────────────────────────────────────────────┐
│ 1. 用户下单（独角数卡）                              │
│    POST /create-order                               │
│    from=novel, email=xxx                            │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 2. EpusdtController::gateway()                      │
│    构造支付参数，包括notify_url                      │
│    notify_url = 'http://dujiaoka/pay/epusdt/notify_url' │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 3. 调用 bepusdt API 创建支付                        │
│    POST {beprusdt_url}                              │
│    {                                                │
│      "order_id": "xxx",                             │
│      "notify_url": "http://dujiaoka/pay/epusdt/notify_url" │
│    }                                                │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 4. bepusdt 保存 notify_url 到数据库                 │
│    等待用户支付                                      │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 5. 用户跳转到 bepusdt 支付页面                       │
│    扫码/确认支付                                     │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 6. 用户支付成功                                      │
│    bepusdt 检测到支付成功                            │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 7. ⭐ bepusdt 主动调用 notify_url                   │
│    POST http://dujiaoka/pay/epusdt/notify_url      │
│    {                                                │
│      "order_id": "xxx",                             │
│      "amount": 10.00,                               │
│      "status": 2,  // 2=支付成功                    │
│      "trade_id": "yyy"                              │
│    }                                                │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 8. EpusdtController::notifyUrl()                    │
│    验证签名                                          │
│    检查订单状态                                      │
│    调用 completedOrder() 完成订单                    │
│    触发 ApiHook 异步任务（充值token）                │
│    return 'ok'                                      │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│ 9. bepusdt 收到 'ok' 响应                           │
│    回调通知完成                                      │
└─────────────────────────────────────────────────────┘
```

## 关键技术点

### 1. trim() 函数的作用
```php
// 防止重复斜杠
$handleroute = '/pay/epusdt/';
$clean = trim($handleroute, '/'); // 'pay/epusdt'
$url = 'http://dujiaoka/' . $clean . '/notify_url';
// 结果: http://dujiaoka/pay/epusdt/notify_url ✅

// 如果没有trim
$url = 'http://dujiaoka/' . $handleroute . '/notify_url';
// 结果: http://dujiaoka//pay/epusdt//notify_url ❌
```

### 2. 外部网络 vs 内部网络
```yaml
# bepusdt_default 是外部网络
networks:
  bepusdt_default:
    external: true  # ⭐ 由bepusdt创建

# dujiaoka 加入这个网络
services:
  dujiaoka:
    networks:
      - bepusdt_default  # ⭐ 可以与bepusdt通信
```

### 3. 服务名 vs 容器名
```yaml
services:
  dujiaoka:              # 服务名
    container_name: dujiaoka_app  # 容器名
```

**在Docker网络中**:
- 可以使用服务名: `http://dujiaoka`
- 可以使用容器名: `http://dujiaoka_app`
- 两者都指向同一个容器

### 4. 为什么不能使用localhost？

```php
// ❌ 错误：在bepusdt容器中
'notify_url' => 'http://localhost/pay/epusdt/notify_url'
// localhost指向bepusdt自己，不是独角卡

// ❌ 错误：在bepusdt容器中
'notify_url' => 'http://host.docker.internal/pay/epusdt/notify_url'
// host.docker.internal指向宿主机，不是独角卡

// ✅ 正确：在bepusdt容器中
'notify_url' => 'http://dujiaoka/pay/epusdt/notify_url'
// dujiaoka是独角卡容器的服务名
```

## 常见问题排查

### Q1: notify_url回调失败？
**排查步骤**:
1. 检查bepusdt日志，看回调URL是什么
   ```bash
   docker logs bepusdt | grep notify_url
   ```
2. 在bepusdt容器内测试连通性
   ```bash
   docker exec bepusdt curl http://dujiaoka/pay/epusdt/notify_url
   ```
3. 检查Docker网络
   ```bash
   docker network inspect bepusdt_default
   ```
4. 检查dujiaoka_app是否在网络中
   ```bash
   docker network inspect bepusdt_default | grep dujiaoka
   ```

### Q2: 如何验证notify_url是否正确？
**方法1**: 查看bepusdt接收到的参数
```bash
# 在EpusdtController::gateway()中添加日志
\Log::info('Epusdt notify_url', [
    'notify_url' => $parameter['notify_url']
]);

# 查看日志
tail -f storage/logs/laravel.log | grep notify_url
```

**方法2**: 在bepusdt数据库查看
```sql
-- bepusdt数据库
SELECT order_id, notify_url FROM orders WHERE order_id = 'xxx';
```

### Q3: Docker网络不通怎么办？
**检查清单**:
```bash
# 1. 检查网络是否存在
docker network ls | grep bepusdt

# 2. 检查dujiaoka是否在网络中
docker network inspect bepusdt_default | grep dujiaoka

# 3. 检查bepusdt是否在网络中
docker network inspect bepusdt_default | grep bepusdt

# 4. 测试连通性
docker exec bepusdt ping dujiaoka
docker exec bepusdt curl http://dujiaoka

# 5. 如果不在网络中，重新连接
docker network connect bepusdt_default dujiaoka_app
```

## 最佳实践

### 1. Docker网络中的URL配置
```php
// ✅ 正确：使用服务名
$notify_url = 'http://dujiaoka/pay/epusdt/notify_url';

// ❌ 错误：使用localhost
$notify_url = 'http://localhost/pay/epusdt/notify_url';

// ❌ 错误：使用外部域名
$notify_url = 'http://example.com/pay/epusdt/notify_url';
```

### 2. 路由拼接规范
```php
// ✅ 正确：使用trim防止重复斜杠
$url = 'http://service/' . trim($route, '/') . '/path';

// ✅ 正确：使用rtrim
$url = 'http://service/' . rtrim($route, '/') . '/path';

// ❌ 错误：直接拼接可能产生双斜杠
$url = 'http://service/' . $route . '/path';
```

### 3. 回调URL调试
```php
// 在gateway()中记录notify_url
\Log::info('支付网关notify_url', [
    'order_sn' => $orderSN,
    'notify_url' => $parameter['notify_url'],
    'container_hostname' => gethostname(),
    'docker_network' => 'bepusdt_default'
]);
```

### 4. 网络配置验证
```bash
# 添加到docker-compose.yml
healthcheck:
  test: |
    curl -f http://dujiaoka/pay/epusdt/notify_url || exit 1
  interval: 30s
  timeout: 10s
  retries: 3
```

## 总结

### notify_url拼接公式
```
notify_url = 协议 + 服务名 + 路由路径 + 回调路由
           = http://  + dujiaoka  + /pay/epusdt  + /notify_url
           = http://dujiaoka/pay/epusdt/notify_url
```

### 为什么这样设计？
1. **Docker内部通信**: 使用服务名访问其他容器
2. **网络隔离**: 避免使用localhost造成混淆
3. **灵活性**: 路由路径可配置，适应不同支付网关
4. **安全性**: 内部网络通信，不暴露到公网

### 为什么是bepusdt访问独角卡？
1. **异步回调**: 支付网关需要通知商户系统支付结果
2. **主动通知**: bepusdt主动调用独角卡的notify_url
3. **解耦合**: 用户支付完成后不需要停留在支付页面
4. **可靠性**: 异步通知确保订单状态更新
