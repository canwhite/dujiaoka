# Task: 理解computeHMACSignature函数和ApiHook触发时机

**任务ID**: task_understand_hmac_260106_163505
**创建时间**: 2026-01-06
**状态**: 已完成
**目标**: 分析computeHMACSignature函数实现和ApiHook.php的触发机制

## 最终目标
1. 理解computeHMACSignature函数的HMAC-SHA256签名生成逻辑
2. 了解ApiHook.php在什么情况下被触发（调用时机、触发条件）
3. 掌握ApiHook.php在整个订单流程中的作用

## 拆解步骤
### 1. 分析computeHMACSignature函数
- [x] 阅读函数代码，理解每个步骤
- [x] 分析函数用途和调用场景
- [x] 查看函数在ApiHook.php中的调用位置

### 2. 分析ApiHook.php文件
- [x] 阅读ApiHook.php整体结构
- [x] 查找触发ApiHook的代码位置
- [x] 理解ApiHook的dispatch调用时机

### 3. 查看相关调用链
- [x] 查找OrderProcessService中的ApiHook调用
- [x] 查看支付回调中的触发逻辑
- [x] 理解from参数如何影响ApiHook执行路径

### 4. 总结理解
- [x] 整理computeHMACSignature函数的作用
- [x] 总结ApiHook触发时机和流程
- [x] 提供完整的技术说明

## 当前进度
### 已完成: 提供完整的调用路径和机制分析
已完成完整的调用路径分析、Dispatchable trait机制解释和Laravel队列系统架构分析

## 下一步行动
1. 归档任务文档
2. 更新production.md（如需）

## 详细技术分析

### 1. computeHMACSignature 函数分析

**位置**: `app/Jobs/ApiHook.php:170-187`

**功能**: 生成 HMAC-SHA256 签名，用于第三方 API 的身份验证和请求完整性校验。

**实现步骤**:
1. **参数排序**: `ksort($params)` 按字母序排序参数数组
2. **拼接字符串**: 将参数转换为 `key=value&key=value` 格式
3. **计算签名**: 使用 `hash_hmac('sha256', $paramStr, $secretKey)` 生成 HMAC-SHA256 签名

**调用场景**: 在 `callNovelApi()` 方法中 (`app/Jobs/ApiHook.php:273`) 为小说网站充值 API 请求生成签名。

**签名参数**:
- `actual_price`: 实际支付金额（整数，单位：分/token）
- `email`: 充值账号（从订单 info 提取或使用订单邮箱）
- `order_sn`: 订单号
- `timestamp`: 当前时间戳（字符串）

**安全作用**:
- 防止请求篡改：服务端使用相同密钥重新计算签名进行验证
- 身份认证：只有拥有密钥的客户端才能生成有效签名
- 防止重放攻击：通过 timestamp 参数限制请求有效期

### 2. 完整的调用路径分析

#### 2.1 支付回调入口
**位置**: `app/Http/Controllers/Pay/EpusdtController.php:98-99`
```php
$this->orderProcessService->completedOrder(
    $data['order_id'],    // 订单号
    $data['amount'],      // 支付金额
    $data['trade_id']     // 交易号
);
```

**其他支付渠道**:
- `app/Http/Controllers/Pay/AlipayController.php:101` - 支付宝
- `app/Http/Controllers/Pay/CoinbaseController.php:125` - Coinbase
- `app/Http/Controllers/Pay/VpayController.php:81` - Vpay
- `app/Http/Controllers/Pay/TokenPayController.php:92` - TokenPay

#### 2.2 OrderProcessService::completedOrder() 处理
**位置**: `app/Service/OrderProcessService.php:385-438`

**关键步骤**:
1. **开启事务**: `DB::beginTransaction()` (line 387)
2. **验证订单**: 检查订单存在性、状态、金额一致性
3. **订单处理**: 根据`order->type`调用`processAuto()`或`processManual()`
4. **提交事务**: `DB::commit()` (line 414) - **重要: 确保数据持久化**
5. **触发ApiHook**: `ApiHook::dispatch($order)` (line 438)

#### 2.3 关键设计决策
**事务保护**:
```php
DB::beginTransaction();
try {
    // 核心业务逻辑
    DB::commit();  // ✅ 成功后才提交
    // 队列任务在事务外触发
    ApiHook::dispatch($order);  // 不会回滚
} catch (\Exception $exception) {
    DB::rollBack();  // 失败回滚
    throw $exception;
}
```

**异步设计**: ApiHook在`try-catch`块之外，即使ApiHook失败也不影响订单完成状态。

### 3. Dispatchable Trait 和队列机制

#### 3.1 ApiHook类的队列配置
**位置**: `app/Jobs/ApiHook.php:12-14`
```php
class ApiHook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;      // 最大尝试次数
    public $timeout = 30;   // 超时时间(秒)
}
```

#### 3.2 Laravel队列系统架构
**配置**: `.env.example:35` & `config/queue.php:16`
```
QUEUE_CONNECTION=redis  # 使用Redis作为队列驱动
```

#### 3.3 Dispatch方法的工作原理
**Dispatchable trait提供的静态方法**:
```php
// Illuminate/Foundation/Bus/Dispatchable.php
public static function dispatch(...$arguments)
{
    return new PendingDispatch(new static(...$arguments));
}
```

**实际执行流程**:
1. `ApiHook::dispatch($order)` 创建`PendingDispatch`实例
2. `PendingDispatch`调用`dispatchToQueue()`方法
3. 任务被序列化并推送到Redis队列
4. 立即返回，不等待任务执行

### 4. 完整的调用流程图

```
┌─────────────────────────────────────────────────────────────┐
│                   支付回调请求                                │
│  (bepusdt/支付宝/Coinbase等第三方支付平台)                     │
└─────────────────────────────┬───────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                支付回调控制器                                 │
│  - EpusdtController::notifyUrl()                           │
│  - AlipayController::notifyUrl()                           │
│  - 验证签名、支付状态                                        │
└─────────────────────────────┬───────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│           OrderProcessService::completedOrder()              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ 1. DB::beginTransaction()                           │    │
│  │ 2. 验证订单(存在性、状态、金额)                       │    │
│  │ 3. 订单处理:                                        │    │
│  │    - order->type == AUTOMATIC_DELIVERY → processAuto() │
│  │    - 否则 → processManual()                         │    │
│  │ 4. 更新销量                                         │    │
│  │ 5. DB::commit() ← 关键: 数据持久化                  │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────┬───────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                ApiHook::dispatch($order)                     │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ ⭐ Dispatchable trait静态方法                        │    │
│  │ ⭐ 创建PendingDispatch实例                           │    │
│  │ ⭐ 序列化任务数据                                    │    │
│  │ ⭐ 推送到Redis队列(异步)                             │    │
│  │ ⭐ 立即返回，不阻塞当前请求                          │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────┬───────────────────────────────┘
                              ↓
                     [Redis队列存储]
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                队列工作者进程                                 │
│  (php artisan queue:work 或 Supervisor守护进程)              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ 1. 从Redis队列取出任务                               │    │
│  │ 2. 反序列化ApiHook实例                              │    │
│  │ 3. 调用ApiHook::handle()方法                        │    │
│  │ 4. 执行API回调逻辑                                   │    │
│  │    - 提取from参数                                    │
│  │    - 调用对应API (novel/game/vip等)                  │
│  │    - 计算HMAC签名(computeHMACSignature)              │
│  │    - 发送HTTP请求                                    │
│  │    - 验证响应                                        │
│  │ 5. 记录日志                                         │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

### 5. 关键机制详解

#### 5.1 发布订阅模式实现
**发布者 (Publisher)**: `OrderProcessService::completedOrder()`
- 在事务提交后发布任务
- 不关心任务何时执行、是否成功
- 实现了解耦：订单处理与API回调分离

**订阅者 (Subscriber)**: `ApiHook::handle()`
- 异步处理任务
- 独立的错误处理机制
- 支持重试机制 (`$tries = 2`)

#### 5.2 异步队列的优势
1. **性能**: 支付回调立即响应，不等待API调用
2. **可靠性**: 队列持久化，任务不会丢失
3. **可扩展性**: 可增加多个队列工作者处理积压任务
4. **错误隔离**: API调用失败不影响订单完成状态

#### 5.3 事务与队列的协同
```php
// ✅ 正确的顺序：先提交事务，再分发队列任务
DB::commit();                    // 1. 确保订单数据已保存
ApiHook::dispatch($order);       // 2. 触发异步任务

// ❌ 错误的顺序：队列任务在事务内
ApiHook::dispatch($order);       // 任务可能基于未提交的数据
DB::commit();                    // 如果回滚，任务已发出但数据不存在
```

#### 5.4 环境配置
**队列启动命令**:
```bash
# 开发环境测试
php artisan queue:work --tries=2 --timeout=30

# 生产环境使用Supervisor
# /etc/supervisor/conf.d/dujiaoka-worker.conf
[program:dujiaoka-worker]
command=php /var/www/dujiaoka/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
```

### 6. 总结
`ApiHook::dispatch($order)` 实现了典型的**发布-订阅模式**：

1. **发布时机**: 在`OrderProcessService::completedOrder()`的事务提交之后
2. **分发机制**: 通过Laravel的`Dispatchable` trait推送到Redis队列
3. **异步处理**: 由队列工作者进程异步执行`ApiHook::handle()`
4. **解耦设计**: 订单处理与API回调完全解耦，互不影响

这种设计确保了：
- ✅ 支付回调快速响应
- ✅ 订单数据事务安全
- ✅ API调用异步可靠
- ✅ 系统可扩展性强