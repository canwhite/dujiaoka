# Design: ApiHook Payment Callback and Redirect Architecture

## Architecture Overview

### System Components

```
┌─────────────────┐
│  Novel Website  │
│  (127.0.0.1:3000)│
└────────┬────────┘
         │ 1. 用户点击购买
         │    (携带 from=novel 参数)
         ▼
┌─────────────────┐
│  Dujiaoka System│
│  - Create Order │
│  - Save info:   │
│    "来源: novel" │
└────────┬────────┘
         │ 2. 用户支付
         ▼
┌─────────────────┐
│  Payment Gateway│
│  (Alipay/WeChat)│
└────────┬────────┘
         │ 3. 异步回调
         ▼
┌─────────────────────────────┐
│ OrderProcessService          │
│ - completedOrder()           │
│ - Update order status        │
│ - DB Transaction Commit      │
│ - Dispatch ApiHook Job       │
└────────┬────────────────────┘
         │ 4. Queue Job
         ▼
┌─────────────────────────────┐
│ ApiHook (Laravel Queue)      │
│ - Extract from parameter     │
│ - Route to callNovelApi()    │
│ - Send POST request          │
└────────┬────────────────────┘
         │ 5. API Callback
         ▼
┌─────────────────┐
│  Novel API      │
│  POST /recharge │
│  - Send token   │
└─────────────────┘

[Parallel Flow - Frontend Polling]
┌─────────────────────────────┐
│ Frontend qrpay.blade.php    │
│ - setInterval(5s)           │
│ - AJAX: check-order-status  │
│ - Wait for code=200         │
└────────┬────────────────────┘
         │ 6. Payment Success
         ▼
┌─────────────────────────────┐
│ Redirect Logic              │
│ - Check from='novel'        │
│ - Get redirect_urls['novel']│
│ - setTimeout(3s)            │
│ - window.location.href      │
└────────┬────────────────────┘
         │ 7. Redirect back
         ▼
┌─────────────────┐
│  Novel Website  │
│  (127.0.0.1:3000)│
└─────────────────┘
```

## Data Flow Analysis

### 1. 订单创建阶段

**Input Parameters (from Novel Website)**:
```php
// Novel website sends purchase request with these parameters:
$email = 'user@example.com';
$from = 'novel';  // 来源标识
$productId = 123;

// Saved to Order::info field:
$order->info = "充值账号: user@example.com\n来源: novel";
```

**Why this design?**
- 使用 `info` 字段存储灵活的键值对数据
- 避免修改数据库表结构
- 支持多种代充类型（email、游戏账号、手机号等）

### 2. 支付回调阶段

**Synchronous Callback** (用户跳转):
```
Payment Gateway → Browser → Dujiaoka
- 速度快，但不可靠
- 用户可能关闭浏览器
- 仅用于用户体验优化
```

**Asynchronous Callback** (服务器通知):
```
Payment Gateway → Server → Dujiaoka API
- 可靠，作为最终依据
- 幂等性保护
- 触发 OrderProcessService::completedOrder()
```

**Code Flow**:
```php
// OrderProcessService.php:385
public function completedOrder($orderSN, $actualPrice, $tradeNo = '')
{
    DB::beginTransaction();  // ← 开启事务

    try {
        // 1. 验证订单
        $order = $this->orderService->detailOrderSN($orderSN);

        // 2. 幂等性检查
        if ($order->status == Order::STATUS_COMPLETED) {
            throw new \Exception('Order already completed');
        }

        // 3. 金额验证
        if (bccomp($order->actual_price, $actualPrice, 2) != 0) {
            throw new \Exception('Amount mismatch');
        }

        // 4. 处理订单（自动发货/手动发货）
        if ($order->type == Order::AUTOMATIC_DELIVERY) {
            $this->processAuto($order);
        } else {
            $this->processManual($order);
        }

        // 5. 增加销量
        $this->goodsService->salesVolumeIncr($order->goods_id, $order->buy_amount);

        DB::commit();  // ← 提交事务

        // 6. 异步任务（在事务外）
        ApiHook::dispatch($order);  // ← 关键：队列任务

        return $order;
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }
}
```

**Key Design Decisions**:
1. **事务保护**：订单状态更新必须原子性
2. **异步 ApiHook**：在 DB commit 后 dispatch，确保订单已保存
3. **幂等性**：防止重复回调导致重复发货
4. **异常隔离**：ApiHook 失败不影响订单完成

### 3. ApiHook 队列处理

**Queue Configuration**:
```php
// ApiHook.php:12
class ApiHook implements ShouldQueue
{
    public $tries = 2;        // 失败重试 1 次
    public $timeout = 30;     // 30 秒超时

    public function handle()
    {
        $goodInfo = $this->goodsService->detail($this->order->goods_id);

        // 判断是否有配置 API Hook
        if(empty($goodInfo->api_hook)){
            return;  // 没有配置则跳过
        }

        // 提取 from 参数
        $from = '';
        if (!empty($this->order->info)) {
            if (preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
                $from = $matches[1];
            }
        }

        // 根据 from 路由到不同的 API
        $this->callApiByFrom($from, $goodInfo);
    }
}
```

**Why Queue?**
1. **不阻塞用户**：API 调用可能慢，不应阻塞支付成功响应
2. **重试机制**：网络失败自动重试
3. **解耦系统**：支付完成和 API 回调解耦
4. **并发控制**：避免同时过多 API 请求

**from 参数路由机制**:
```php
private function callApiByFrom($from, $goodInfo)
{
    switch ($from) {
        case 'novel':
            $this->callNovelApi($goodInfo);
            break;

        case 'game':
            $this->callGameApi($goodInfo);
            break;

        // ... 更多渠道

        default:
            // 默认使用商品配置的 api_hook
            $this->sendDefaultApiHook($goodInfo);
            break;
    }
}
```

**Novel API 实现**:
```php
private function callNovelApi($goodInfo)
{
    // 1. 从环境变量读取 API URL
    $apiUrl = env('NOVEL_API_URL', '');
    if (empty($apiUrl)) {
        \Log::warning('NOVEL_API_URL not configured');
        return;
    }

    // 2. 从订单 info 提取邮箱
    $email = '';
    if (!empty($this->order->info)) {
        if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
            $email = $matches[1];
        }
    }

    // 3. 构造请求数据
    $postdata = [
        'email' => $email,
        'order_sn' => $this->order->order_sn,
        'amount' => $this->order->actual_price,
        'good_name' => $goodInfo->gd_name,
        'timestamp' => time()
    ];

    // 4. 发送 POST 请求
    $this->sendPostRequest($apiUrl, $postdata);
}
```

**HTTP 请求实现**:
```php
private function sendPostRequest($url, $data)
{
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'timeout' => 30
        ]
    ];

    $context = stream_context_create($opts);

    try {
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            \Log::error('API Hook请求失败', [
                'url' => $url,
                'data' => $data,
                'error' => error_get_last()
            ]);
        } else {
            \Log::info('API Hook请求成功', [
                'url' => $url,
                'response' => $result
            ]);
        }
    } catch (\Exception $e) {
        \Log::error('API Hook异常', [
            'url' => $url,
            'data' => $data,
            'exception' => $e->getMessage()
        ]);
    }
}
```

**Why file_get_contents instead of Guzzle?**
- 轻量级，无需额外依赖
- 对于简单 POST 请求足够
- 项目已有的代码风格保持一致

**Potential improvements**:
```php
// 建议：使用 HTTP 客户端库
use Illuminate\Support\Facades\Http;

private function sendPostRequest($url, $data)
{
    try {
        $response = Http::timeout(30)
            ->post($url, $data);

        if ($response->successful()) {
            \Log::info('API Hook请求成功', [
                'response' => $response->body()
            ]);
        } else {
            \Log::error('API Hook返回错误', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
        }
    } catch (\Exception $e) {
        \Log::error('API Hook异常', [
            'exception' => $e->getMessage()
        ]);
        throw $e;  // 重新抛出异常以触发队列重试
    }
}
```

### 4. 前端轮询和重定向

**Polling Mechanism** (qrpay.blade.php):

```javascript
// Unicorn 主题
var getting = {
    url: '{{ url('check-order-status', ['orderSN' => $orderid]) }}',
    dataType: 'json',
    success: function(res) {
        if (res.code == 400001) {
            // 订单过期
            window.clearTimeout(timer);
            alert("订单已过期");
            setTimeout("window.location.href ='/'", 3000);
        }

        if (res.code == 200) {
            // 支付成功
            window.clearTimeout(timer);

            // ⭐ 获取 from 参数和重定向配置
            const from = '{{ $from ?? '' }}';
            const redirectUrls = @json($redirect_urls ?? []);

            // ⭐ 决定跳转 URL
            let redirectUrl = '{{ url('detail-order-sn', ['orderSN' => $orderid]) }}';

            if (from && redirectUrls[from]) {
                redirectUrl = redirectUrls[from];
            }

            // ⭐ 3秒后跳转
            setTimeout(function() {
                window.location.href = redirectUrl;
            }, 3000);
        }
    }
};

// ⭐ 每5秒轮询一次
var timer = window.setInterval(function() {
    $.ajax(getting)
}, 5000);
```

**Data Passing Chain**:
```
PayController::render()
    ↓
Extract from from Order::info
    ↓
Pass $from and $redirect_urls to template
    ↓
Template renders JavaScript
    ↓
Frontend polling receives code=200
    ↓
Check from and redirect_urls
    ↓
Redirect to 127.0.0.1:3000
```

**Backend Implementation** (PayController.php):
```php
protected function render(string $tpl, $data = [], string $pageTitle = '')
{
    // ⭐ 如果是 qrpay 页面，自动添加 from 和 redirect_urls
    if ($tpl === 'static_pages/qrpay') {
        $data['from'] = $this->extractFromFromOrder();
        $data['redirect_urls'] = [
            'novel' => 'http://127.0.0.1:3000',
        ];
    }

    return parent::render($tpl, $data, $pageTitle);
}

protected function extractFromFromOrder(): string
{
    if (empty($this->order->info)) {
        return '';
    }

    if (preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
        return $matches[1];
    }

    return '';
}
```

**Why Polling instead of WebSocket?**
1. **简单性**：轮询实现简单，无需额外服务器
2. **兼容性**：所有浏览器都支持
3. **可靠性**：不受网络连接断开影响
4. **低频**：5秒间隔对服务器压力小

**Trade-offs**:
- **延迟**：最多5秒延迟才能检测到支付成功
- **服务器负载**：大量订单时会产生频繁请求
- **可优化**：可考虑 Server-Sent Events (SSE) 或 WebSocket

## Error Handling and Edge Cases

### 1. API Call Failures

**Scenario 1: API URL 未配置**
```php
if (empty($apiUrl)) {
    \Log::warning('NOVEL_API_URL not configured', [
        'order_sn' => $this->order->order_sn
    ]);
    return;  // 静默失败，不影响订单
}
```
**Impact**: 订单完成，但 token 未发送
**Solution**: 必须配置环境变量

**Scenario 2: API 请求超时**
```php
'timeout' => 30  // 30秒超时
```
**Impact**: 队列任务失败，自动重试 1 次（`$tries = 2`）
**Solution**: Laravel Queue 自动重试机制

**Scenario 3: API 返回错误**
```php
$result = @file_get_contents($url, false, $context);
if ($result === false) {
    \Log::error('API Hook请求失败', [
        'error' => error_get_last()
    ]);
}
```
**Impact**: 记录错误日志，不抛出异常
**Solution**: 需要手动检查日志

### 2. Redirect Failures

**Scenario 1: from 参数丢失**
```javascript
const from = '{{ $from ?? '' }}';
if (from && redirectUrls[from]) {
    redirectUrl = redirectUrls[from];
} else {
    // 默认跳转到订单详情页
    redirectUrl = '{{ url('detail-order-sn', ['orderSN' => $orderid]) }}';
}
```
**Impact**: 跳转到默认订单详情页
**User Experience**: 用户需要手动返回小说网站

**Scenario 2: 重定向 URL 不可达**
```javascript
window.location.href = 'http://127.0.0.1:3000';
```
**Impact**: 浏览器显示"无法访问此网站"
**Solution**: 需要确保小说网站正常运行

### 3. Race Conditions

**Potential Race Condition**:
```
Time 0s:  User completes payment
Time 0s:  Payment callback triggers ApiHook
Time 1s:  Frontend polling detects payment success
Time 1s:  User redirects to novel website
Time 2s:  ApiHook still processing...
Time 3s:  ApiHook sends token
```
**Impact**: 用户可能先到达小说网站，但 token 还未到账
**Solution**: 小说网站需要显示"充值中，请稍候"提示

## Security Considerations

### 1. API Authentication
**Current**: 无认证机制
**Risk**: 任何人都可以调用 Novel API
**Recommendation**:
```php
// 添加签名验证
$postdata = [
    'email' => $email,
    'order_sn' => $this->order->order_sn,
    'timestamp' => time(),
    'sign' => md5($email . $orderSN . $timestamp . env('API_SECRET'))
];
```

### 2. Data Validation
**Current**: 假设订单 info 格式正确
**Risk**: 正则表达式可能匹配失败
**Current Protection**:
```php
if (empty($email)) {
    // 不会发送 API 请求，或发送空邮箱
}
```

### 3. HTTPS
**Recommendation**: 生产环境使用 HTTPS
```
Payment Gateway: HTTPS ✅
Dujiaoka: HTTPS ✅
Novel API: HTTPS ⚠️ (建议)
Novel Website: HTTP ⚠️ (本地开发可接受)
```

## Performance Optimization

### Current Performance
- **API Call**: ~100-500ms (取决于网络)
- **Queue Processing**: 异步，不阻塞用户
- **Frontend Polling**: 每5秒一次，最多持续几分钟

### Bottlenecks
1. **API 同步调用**: 每个 ApiHook 任务顺序执行
2. **频繁轮询**: 大量订单时产生很多请求

### Optimization Opportunities

**1. API Call 批处理** (如果支持):
```php
// 收集多个订单，批量发送
$orders = Order::where('status', 'pending')
    ->where('created_at', '>', now()->subMinute())
    ->get();

$this->batchSendNovelApi($orders);
```

**2. 减少 Polling 频率**:
```javascript
// 使用指数退避
let interval = 1000; // 初始1秒
const maxInterval = 10000; // 最大10秒

var timer = setInterval(function() {
    $.ajax({
        url: checkUrl,
        success: function(res) {
            if (res.code == 200) {
                clearInterval(timer);
                redirectToNovel();
            } else {
                // 增加轮询间隔
                interval = Math.min(interval * 1.5, maxInterval);
                clearInterval(timer);
                timer = setInterval(arguments.callee, interval);
            }
        }
    });
}, interval);
```

**3. 使用 WebSocket** (高级):
```php
// Laravel Echo + Pusher/Soketi
Broadcast::channel('order.{orderSN}', function ($user, $orderSN) {
    return true;
});

// 在 OrderProcessService::completedOrder() 中
broadcast(new OrderCompleted($order))->toOthers();
```

## Monitoring and Observability

### Current Logging
```php
\Log::info('API Hook请求成功', ['url' => $url, 'response' => $result]);
\Log::error('API Hook请求失败', ['url' => $url, 'error' => error_get_last()]);
```

### Recommended Metrics

1. **Success Rate**:
```php
// 记录到监控系统
Metrics::increment('apihook.success', ['type' => 'novel']);
Metrics::increment('apihook.failure', ['type' => 'novel']);
```

2. **Response Time**:
```php
$startTime = microtime(true);
$this->sendPostRequest($apiUrl, $postdata);
$duration = microtime(true) - $startTime;
\Log::info('API Hook性能', ['duration' => $duration]);
```

3. **Queue Depth**:
```bash
# 监控队列积压
php artisan queue:monitor
```

### Alerting
```php
// 失败率超过阈值时告警
if ($failureRate > 0.1) { // 10%
    \Notification::route('mail', 'admin@example.com')
        ->notify(new ApiHookFailureAlert());
}
```

## Conclusion

### Design Strengths
1. ✅ **异步处理**：不阻塞用户支付流程
2. ✅ **可扩展性**：易于添加新的 from 类型
3. ✅ **错误隔离**：API 失败不影响订单
4. ✅ **用户友好**：自动重定向，用户体验好

### Design Weaknesses
1. ⚠️ **缺少认证**：API 调用无签名验证
2. ⚠️ **硬编码配置**：URL 写死在代码中
3. ⚠️ **监控不足**：失败无主动告警
4. ⚠️ **文档缺失**：缺少 API 接口文档

### Prioritized Improvements
1. **High Priority**: 配置 `NOVEL_API_URL` 环境变量
2. **High Priority**: 添加 API 调用失败告警
3. **Medium Priority**: 将重定向 URL 移到环境变量
4. **Medium Priority**: 添加 API 签名验证
5. **Low Priority**: 考虑使用 WebSocket 替代轮询
