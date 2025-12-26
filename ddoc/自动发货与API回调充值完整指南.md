# 自动发货与API回调充值完整指南

## 一、核心需求

### 业务场景
```
用户下单 → 支付成功 → 自动调用第三方API充值Token → 通知用户
```

**不是发卡密给用户,而是直接调用第三方平台API完成充值!**

---

## 二、项目当前机制

### 2.1 支付回调流程

**文件**: `app/Http/Controllers/Pay/AlipayController.php:77-107`

```php
/**
 * 支付宝异步通知
 */
public function notifyUrl(Request $request)
{
    // 1. 获取订单号
    $orderSN = $request->input('out_trade_no');

    // 2. 查询订单
    $order = $this->orderService->detailOrderSN($orderSN);

    // 3. 验证签名
    $result = $pay->verify();

    // 4. 判断支付状态
    if ($result->trade_status == 'TRADE_SUCCESS' || $result->trade_status == 'TRADE_FINISHED') {
        // ⭐⭐⭐ 调用订单完成方法
        $this->orderProcessService->completedOrder(
            $result->out_trade_no,    // 订单号
            $result->total_amount,     // 支付金额
            $result->trade_no          // 第三方交易号
        );
    }

    return 'success';
}
```

**其他支付方式**:
- 微信支付: `routes/common/pay.php:20` - `WepayController@notifyUrl`
- PayPal: `routes/common/pay.php:38` - `PaypalPayController@notifyUrl`
- 易支付: `routes/common/pay.php:33` - `YipayController@notifyUrl`

---

### 2.2 订单完成主逻辑

**文件**: `app/Service/OrderProcessService.php:385-438`

```php
/**
 * 订单成功方法 (支付成功后调用)
 *
 * @param string $orderSN 订单号
 * @param float $actualPrice 实际支付金额
 * @param string $tradeNo 第三方订单号
 * @return Order
 */
public function completedOrder(string $orderSN, float $actualPrice, string $tradeNo = '')
{
    DB::beginTransaction();
    try {
        // 1. 查询订单
        $order = $this->orderService->detailOrderSN($orderSN);

        // 2. 验证订单状态
        if ($order->status == Order::STATUS_COMPLETED) {
            throw new \Exception(__('dujiaoka.prompt.order_status_completed'));
        }

        // 3. 验证金额
        if (bccomp($order->actual_price, $actualPrice, 2) != 0) {
            throw new \Exception(__('dujiaoka.prompt.order_inconsistent_amounts'));
        }

        $order->actual_price = $actualPrice;
        $order->trade_no = $tradeNo;

        // 4. ⭐⭐⭐ 区分订单类型
        if ($order->type == Order::AUTOMATIC_DELIVERY) {
            // 自动发货
            $completedOrder = $this->processAuto($order);
        } else {
            // 人工处理
            $completedOrder = $this->processManual($order);
        }

        // 5. 增加销量
        $this->goodsService->salesVolumeIncr($order->goods_id, $order->buy_amount);

        DB::commit();

        // 6. ⭐⭐⭐ 触发API Hook (重要!)
        ApiHook::dispatch($order);

        // 7. 发送各种通知
        if (dujiaoka_config_get('is_open_server_jiang', 0) == BaseModel::STATUS_OPEN) {
            ServerJiang::dispatch($order);
        }
        if (dujiaoka_config_get('is_open_telegram_push', 0) == BaseModel::STATUS_OPEN) {
            TelegramPush::dispatch($order);
        }
        // ... 其他通知

        return $completedOrder;
    } catch (\Exception $exception) {
        DB::rollBack();
        throw new RuleValidationException($exception->getMessage());
    }
}
```

**关键点**:
- 第407行: 判断订单类型 (自动发货 vs 人工处理)
- 第408行: 调用 `processAuto()` 自动发货逻辑
- 第432行: ⭐ **触发 API Hook 异步任务**

---

### 2.3 当前自动发货逻辑 (卡密模式)

**文件**: `app/Service/OrderProcessService.php:489-524`

```php
/**
 * 处理自动发货
 *
 * @param Order $order 订单
 * @return Order 订单
 */
public function processAuto(Order $order): Order
{
    // 1. 从数据库获取卡密
    $carmis = $this->carmisService->withGoodsByAmountAndStatusUnsold(
        $order->goods_id,
        $order->buy_amount
    );

    // 2. 检查库存
    if (count($carmis) != $order->buy_amount) {
        $order->info = __('dujiaoka.prompt.order_carmis_insufficient_quantity_available');
        $order->status = Order::STATUS_ABNORMAL;
        $order->save();
        return $order;
    }

    // 3. 提取卡密信息
    $carmisInfo = array_column($carmis, 'carmi');
    $ids = array_column($carmis, 'id');

    // 4. 将卡密写入订单详情
    $order->info = implode(PHP_EOL, $carmisInfo);  // ← 卡密内容
    $order->status = Order::STATUS_COMPLETED;
    $order->save();

    // 5. 标记卡密已售出
    $this->carmisService->soldByIDS($ids);

    // 6. 发送邮件
    MailSend::dispatch($order->email, $mailBody['tpl_name'], $mailBody['tpl_content']);

    return $order;
}
```

**⚠️ 当前逻辑**: 从 `carmis` 表提取卡密,发送给用户

**✅ 需要改为**: 调用第三方API充值

---

## 三、解决方案

### 方案1: 使用 API Hook (推荐) ⭐⭐⭐

#### 3.1.1 API Hook 工作原理

**文件**: `app/Jobs/ApiHook.php:58-86`

```php
/**
 * Execute the job.
 */
public function handle()
{
    // 1. 获取商品信息
    $goodInfo = $this->goodsService->detail($this->order->goods_id);

    // 2. 判断是否配置了回调
    if(empty($goodInfo->api_hook)){
        return;  // 没配置则跳过
    }

    // 3. 准备回调数据
    $postdata = [
        'title' => $this->order->title,
        'order_sn' => $this->order->order_sn,
        'email' => $this->order->email,
        'actual_price' => $this->order->actual_price,
        'order_info' => $this->order->info,        // ← 用户填写的充值信息
        'good_id' => $goodInfo->id,
        'gd_name' => $goodInfo->gd_name
    ];

    // 4. POST 请求到配置的API
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'content' => json_encode($postdata, JSON_UNESCAPED_UNICODE)
        ]
    ];
    $context  = stream_context_create($opts);
    file_get_contents($goodInfo->api_hook, false, $context);
}
```

#### 3.1.2 API Hook 完整流程

```
用户支付成功
    ↓
completedOrder() 执行
    ↓
processAuto() 完成 (订单标记为已完成)
    ↓
⭐ ApiHook::dispatch($order) 异步队列任务
    ↓
Laravel 队列处理
    ↓
POST 请求到你的API
    URL: https://your-api.com/recharge
    Method: POST
    Content-Type: application/json
    Body: {
        "title": "商品A x 1",
        "order_sn": "ABC1234567890123",
        "email": "user@example.com",
        "actual_price": 100.00,
        "order_info": "充值账号: user123",  // ← 用户填的信息
        "good_id": 1,
        "gd_name": "商品A"
    }
    ↓
你的API接收 (需要你自己开发)
    ↓
调用第三方平台充值API
    ↓
充值成功
    ↓
发送邮件通知用户
```

#### 3.1.3 代码注入位置: 你的API服务

**位置**: 独立的API服务 (可以是任何语言/框架)

```php
<?php
// 文件: /var/www/recharge-api/public/index.php
// 你的充值API服务

/**
 * 接收独角兽卡网的回调
 * URL: https://your-api.com/recharge
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 接收回调数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 验证数据
if (!$data || !isset($data['order_sn'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// 记录日志
file_put_contents(
    '/var/www/recharge-api/logs/hook_' . date('Y-m-d') . '.log',
    date('Y-m-d H:i:s') . ' - Received: ' . $input . "\n",
    FILE_APPEND
);

try {
    // ⭐⭐⭐ 代码注入位置1: 调用第三方充值API
    $result = rechargeToken($data);

    // 充值成功
    if ($result['success']) {
        // ⭐⭐⭐ 代码注入位置2: 发送通知邮件
        sendNotificationEmail($data['email'], $result);

        // 记录成功日志
        logSuccess($data['order_sn'], $result);

        echo json_encode(['status' => 'success', 'data' => $result]);
    } else {
        // 充值失败,需要人工处理
        logFailure($data['order_sn'], $result['error']);
        notifyAdmin($data, $result['error']);

        echo json_encode(['status' => 'failed', 'error' => $result['error']]);
    }

} catch (Exception $e) {
    // 异常处理
    logError($data['order_sn'], $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * ⭐⭐⭐ 代码注入位置3: 充值函数
 * 调用第三方平台API充值Token
 */
function rechargeToken($orderData) {
    // 提取充值账号 (从 order_info 或 email)
    $account = extractAccount($orderData['order_info'], $orderData['email']);
    $amount = $orderData['actual_price'];

    // 第三方平台API配置
    $apiUrl = 'https://third-party-platform.com/api/v2/recharge';
    $apiKey = 'your_api_key_here';
    $apiSecret = 'your_api_secret_here';

    // 构建请求参数
    $params = [
        'account' => $account,
        'amount' => $amount,
        'currency' => 'USD',
        'timestamp' => time(),
        'order_id' => $orderData['order_sn']
    ];

    // 生成签名
    $params['signature'] = generateSignature($params, $apiSecret);

    // 发送HTTP请求
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 解析响应
    $result = json_decode($response, true);

    if ($httpCode == 200 && isset($result['success']) && $result['success']) {
        return [
            'success' => true,
            'balance' => $result['new_balance'],
            'transaction_id' => $result['transaction_id']
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['message'] ?? 'Unknown error'
        ];
    }
}

/**
 * ⭐⭐⭐ 代码注入位置4: 提取充值账号
 */
function extractAccount($orderInfo, $email) {
    // 如果用户填写了充值信息,从 order_info 提取
    if (!empty($orderInfo)) {
        // 假设格式: "充值账号: user123"
        if (preg_match('/充值账号[:\s]+([^\s]+)/', $orderInfo, $matches)) {
            return $matches[1];
        }
        // 或者直接就是账号
        return $orderInfo;
    }

    // 否则使用邮箱作为账号
    return $email;
}

/**
 * ⭐⭐⭐ 代码注入位置5: 发送通知邮件
 */
function sendNotificationEmail($toEmail, $rechargeResult) {
    $subject = '充值成功通知';
    $message = "您的充值已成功!\n\n";
    $message .= "充值后余额: " . $rechargeResult['balance'] . "\n";
    $message .= "交易号: " . $rechargeResult['transaction_id'] . "\n";
    $message .= "\n感谢您的购买!";

    // 使用PHPMailer或SwiftMailer发送
    mail($toEmail, $subject, $message);
}

/**
 * 生成API签名
 */
function generateSignature($params, $secret) {
    ksort($params);
    $string = http_build_query($params);
    return hash_hmac('sha256', $string, $secret);
}

/**
 * 日志函数
 */
function logSuccess($orderSN, $result) {
    file_put_contents(
        '/var/www/recharge-api/logs/success_' . date('Y-m-d') . '.log',
        date('Y-m-d H:i:s') . " Order: {$orderSN}, Result: " . json_encode($result) . "\n",
        FILE_APPEND
    );
}

function logFailure($orderSN, $error) {
    file_put_contents(
        '/var/www/recharge-api/logs/failed_' . date('Y-m-d') . '.log',
        date('Y-m-d H:i:s') . " Order: {$orderSN}, Error: {$error}\n",
        FILE_APPEND
    );
}

function logError($orderSN, $error) {
    file_put_contents(
        '/var/www/recharge-api/logs/error_' . date('Y-m-d') . '.log',
        date('Y-m-d H:i:s') . " Order: {$orderSN}, Exception: {$error}\n",
        FILE_APPEND
    );
}

/**
 * 通知管理员
 */
function notifyAdmin($orderData, $error) {
    $adminEmail = 'admin@yourdomain.com';
    $subject = '充值失败通知';
    $message = "订单充值失败,请人工处理!\n\n";
    $message .= "订单号: " . $orderData['order_sn'] . "\n";
    $message .= "商品: " . $orderData['gd_name'] . "\n";
    $message .= "金额: " . $orderData['actual_price'] . "\n";
    $message .= "错误: " . $error . "\n";

    mail($adminEmail, $subject, $message);
}
```

#### 3.1.4 配置步骤

**步骤1**: 配置商品 API Hook

```sql
-- 方式1: 后台配置
后台管理 → 商品管理 → 编辑商品 → 回调事件
填写: https://your-api.com/recharge

-- 方式2: 数据库直接配置
UPDATE goods
SET api_hook = 'https://your-api.com/recharge'
WHERE id = 1;
```

**步骤2**: 设置商品为自动发货

```sql
UPDATE goods
SET type = 1  -- 1 = 自动发货, 2 = 人工处理
WHERE id = 1;
```

**步骤3**: 确保 Laravel 队列运行

```bash
# 启动队列处理器
php artisan queue:work

# 或使用 Supervisor 保持运行
# /etc/supervisor/conf.d/laravel-worker.conf
```

---

### 方案2: 直接修改 processAuto 方法

#### 3.2.1 代码注入位置: OrderProcessService.php

**文件**: `app/Service/OrderProcessService.php:489`

**原始代码**:
```php
public function processAuto(Order $order): Order
{
    // 获得卡密
    $carmis = $this->carmisService->withGoodsByAmountAndStatusUnsold($order->goods_id, $order->buy_amount);

    // ... 卡密发货逻辑

    return $order;
}
```

**⭐⭐⭐ 代码注入位置: 替换为充值逻辑**

```php
/**
 * 处理自动发货
 *
 * @param Order $order 订单
 * @return Order 订单
 */
public function processAuto(Order $order): Order
{
    try {
        // ⭐⭐⭐ 代码注入位置1: 调用第三方充值API
        $rechargeResult = $this->rechargeToken($order);

        if ($rechargeResult['success']) {
            // 充值成功
            $order->info = "充值成功!\n\n";
            $order->info .= "充值账号: " . $rechargeResult['account'] . "\n";
            $order->info .= "充值金额: " . $order->actual_price . "\n";
            $order->info .= "当前余额: " . $rechargeResult['balance'] . "\n";
            $order->info .= "交易号: " . $rechargeResult['transaction_id'] . "\n";

            $order->status = Order::STATUS_COMPLETED;

            // ⭐⭐⭐ 代码注入位置2: 记录充值日志
            $this->logRechargeSuccess($order, $rechargeResult);

        } else {
            // 充值失败
            $order->info = "充值失败: " . $rechargeResult['error'] . "\n";
            $order->info .= "请联系客服人工处理";

            $order->status = Order::STATUS_ABNORMAL;

            // ⭐⭐⭐ 代码注入位置3: 通知管理员
            $this->notifyAdminForFailedRecharge($order, $rechargeResult['error']);
        }

        $order->save();

        // 发送邮件通知用户
        $mailData = [
            'ord_info' => $order->info,
            'order_id' => $order->order_sn,
            'product_name' => $order->goods->gd_name,
            'ord_price' => $order->actual_price,
            // ... 其他邮件数据
        ];

        $tpl = $this->emailtplService->detailByToken('card_send_user_email');
        $mailBody = replace_mail_tpl($tpl, $mailData);
        MailSend::dispatch($order->email, $mailBody['tpl_name'], $mailBody['tpl_content']);

        return $order;

    } catch (\Exception $e) {
        // 异常处理
        $order->info = "充值异常: " . $e->getMessage();
        $order->status = Order::STATUS_ABNORMAL;
        $order->save();

        throw $e;
    }
}

/**
 * ⭐⭐⭐ 代码注入位置4: 充值函数
 * 调用第三方平台API充值
 */
private function rechargeToken(Order $order)
{
    // 提取充值账号
    $account = $this->extractRechargeAccount($order);

    // 第三方平台配置 (建议从配置文件读取)
    $apiUrl = config('recharge.api_url', 'https://third-party.com/api/recharge');
    $apiKey = config('recharge.api_key');
    $apiSecret = config('recharge.api_secret');

    // 构建请求参数
    $params = [
        'account' => $account,
        'amount' => $order->actual_price,
        'order_id' => $order->order_sn,
        'timestamp' => time(),
    ];

    // 生成签名
    $params['signature'] = $this->generateSignature($params, $apiSecret);

    // 发送HTTP请求
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // 检查 cURL 错误
    if ($curlError) {
        return [
            'success' => false,
            'error' => '网络请求失败: ' . $curlError
        ];
    }

    // 解析响应
    $result = json_decode($response, true);

    if ($httpCode == 200 && isset($result['success']) && $result['success']) {
        return [
            'success' => true,
            'account' => $account,
            'balance' => $result['data']['balance'] ?? 0,
            'transaction_id' => $result['data']['transaction_id'] ?? ''
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['message'] ?? '充值失败'
        ];
    }
}

/**
 * ⭐⭐⭐ 代码注入位置5: 提取充值账号
 */
private function extractRechargeAccount(Order $order)
{
    // 优先从 order_info 提取 (用户填写的充值信息)
    if (!empty($order->info)) {
        // 可能的格式:
        // "充值账号: user123"
        // "账号:user123"
        // "user123"
        if (preg_match('/[账号account][:\s]+([^\s\n]+)/i', $order->info, $matches)) {
            return $matches[1];
        }
        return trim($order->info);
    }

    // 否则使用邮箱
    return $order->email;
}

/**
 * ⭐⭐⭐ 代码注入位置6: 生成签名
 */
private function generateSignature($params, $secret)
{
    ksort($params);
    $string = http_build_query($params);
    return hash_hmac('sha256', $string, $secret);
}

/**
 * ⭐⭐⭐ 代码注入位置7: 记录充值成功日志
 */
private function logRechargeSuccess(Order $order, $result)
{
    $log = [
        'order_sn' => $order->order_sn,
        'account' => $result['account'],
        'amount' => $order->actual_price,
        'balance' => $result['balance'],
        'transaction_id' => $result['transaction_id'],
        'time' => date('Y-m-d H:i:s')
    ];

    \Log::info('Recharge success', $log);

    // 或者写入文件
    file_put_contents(
        storage_path('logs/recharge_success.log'),
        date('Y-m-d H:i:s') . ' ' . json_encode($log) . "\n",
        FILE_APPEND
    );
}

/**
 * ⭐⭐⭐ 代码注入位置8: 通知管理员充值失败
 */
private function notifyAdminForFailedRecharge(Order $order, $error)
{
    $adminEmail = dujiaoka_config_get('manage_email', '');

    if (empty($adminEmail)) {
        return;
    }

    $mailData = [
        'subject' => '充值失败通知 - 订单: ' . $order->order_sn,
        'order_sn' => $order->order_sn,
        'goods_name' => $order->goods->gd_name,
        'amount' => $order->actual_price,
        'email' => $order->email,
        'error' => $error,
        'time' => date('Y-m-d H:i:s')
    ];

    // 发送邮件给管理员
    MailSend::dispatch(
        $adminEmail,
        '充值失败通知',
        view('emails.recharge_failed', $mailData)->render()
    );
}
```

#### 3.2.2 添加配置文件

**文件**: `config/recharge.php` (新建)

```php
<?php
/**
 * ⭐⭐⭐ 代码注入位置9: 第三方充值配置
 */

return [
    // 第三方平台API地址
    'api_url' => env('RECHARGE_API_URL', 'https://third-party.com/api/recharge'),

    // API密钥
    'api_key' => env('RECHARGE_API_KEY', ''),

    // API密钥
    'api_secret' => env('RECHARGE_API_SECRET', ''),

    // 请求超时时间(秒)
    'timeout' => env('RECHARGE_TIMEOUT', 30),

    // 重试次数
    'retry_times' => env('RECHARGE_RETRY_TIMES', 3),
];
```

#### 3.2.3 添加环境变量

**文件**: `.env`

```bash
# ⭐⭐⭐ 代码注入位置10: 第三方充值配置
RECHARGE_API_URL=https://third-party.com/api/v2/recharge
RECHARGE_API_KEY=your_api_key_here
RECHARGE_API_SECRET=your_api_secret_here
RECHARGE_TIMEOUT=30
RECHARGE_RETRY_TIMES=3
```

---

## 四、两种方案对比

| 对比项 | 方案1: API Hook | 方案2: 修改 processAuto |
|--------|----------------|---------------------|
| **代码改动** | ✅ 不改核心代码 | ❌ 修改核心文件 |
| **部署位置** | 独立API服务器 | Laravel项目内 |
| **执行方式** | ⚠️ 异步队列 | ✅ 同步执行 |
| **实时反馈** | 延迟(队列处理) | 立即 |
| **升级维护** | ✅ 不受影响 | ❌ 升级会被覆盖 |
| **调试难度** | 中等 | 简单 |
| **适用场景** | 充值服务独立部署 | 深度定制需求 |
| **失败处理** | 需要单独处理 | 直接在订单中处理 |
| **多平台支持** | ✅ 一个API管理多个平台 | ⚠️ 代码耦合 |

---

## 五、推荐方案: API Hook (独立服务)

### 5.1 架构设计

```
┌─────────────────────────────────────────────────────┐
│              独角兽卡网 (Laravel)                    │
│  ┌────────────────────────────────────────────┐    │
│  │  1. 用户下单支付                            │    │
│  │  2. 支付宝回调                              │    │
│  │  3. completedOrder()                       │    │
│  │  4. processAuto() - 订单标记完成            │    │
│  │  5. ApiHook::dispatch($order) ⭐           │    │
│  └────────────────────────────────────────────┘    │
│                      ↓                              │
│              Laravel Queue                         │
│                      ↓                              │
└──────────────────────┼──────────────────────────────┘
                       ↓ POST
┌──────────────────────────────────────────────────────┐
│         你的API服务 (独立部署)                        │
│  ┌─────────────────────────────────────────────┐    │
│  │  接收回调: /recharge                         │    │
│  │  ├─ 解析订单数据                            │    │
│  │  ├─ 调用第三方充值API ⭐                    │    │
│  │  ├─ 处理充值结果                            │    │
│  │  └─ 发送通知邮件                            │    │
│  └─────────────────────────────────────────────┘    │
│                      ↓                               │
│              第三方充值平台                          │
└──────────────────────────────────────────────────────┘
```

### 5.2 优势

1. **解耦**: 充值逻辑独立,不影响商城升级
2. **灵活**: 可以随时修改充值逻辑
3. **可扩展**: 可以管理多个第三方平台
4. **监控**: 独立的日志和监控
5. **技术栈自由**: 可以用任何语言实现API服务

---

## 六、实施步骤 (API Hook 方案)

### 步骤1: 开发你的充值API

参考上述 `方案1 > 3.1.3` 中的代码示例

**文件结构**:
```
/var/www/recharge-api/
├── public/
│   └── index.php          # API入口
├── logs/                  # 日志目录
│   ├── success_2025-01-01.log
│   ├── failed_2025-01-01.log
│   └── error_2025-01-01.log
├── config/
│   └── config.php         # 配置文件
└── vendor/                # 依赖包
```

### 步骤2: 部署API服务

```bash
# 使用 Nginx + PHP-FPM
sudo apt install nginx php-fpm

# 配置 Nginx
# /etc/nginx/sites-available/recharge-api
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/recharge-api/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
    }
}

# 启用站点
sudo ln -s /etc/nginx/sites-available/recharge-api /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 步骤3: 配置 SSL 证书 (推荐)

```bash
# 使用 Let's Encrypt
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.yourdomain.com
```

### 步骤4: 配置商品 API Hook

**后台管理** → **商品管理** → **编辑商品** → **回调事件**

填写: `https://api.yourdomain.com/recharge`

或者数据库配置:

```sql
UPDATE goods
SET api_hook = 'https://api.yourdomain.com/recharge'
WHERE id = 1;
```

### 步骤5: 设置商品为自动发货

```sql
UPDATE goods
SET type = 1
WHERE id = 1;
```

### 步骤6: 确保 Laravel 队列运行

```bash
# 方式1: 手动运行 (测试)
php artisan queue:work

# 方式2: 使用 Supervisor (生产环境)
sudo apt install supervisor

# /etc/supervisor/conf.d/laravel-worker.conf
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/dujiaoka/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/dujiaoka/storage/logs/worker.log

# 启动
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

### 步骤7: 测试流程

```
1. 下单测试商品
2. 支付成功
3. 查看队列日志: tail -f storage/logs/laravel.log
4. 查看API日志: tail -f /var/www/recharge-api/logs/*.log
5. 检查充值是否成功
6. 检查用户是否收到邮件
```

---

## 七、调试与监控

### 7.1 日志位置

**独角兽卡网日志**:
- Laravel 日志: `storage/logs/laravel.log`
- 队列日志: `storage/logs/worker.log`
- 异常日志: `storage/logs/` (按日期)

**充值API日志**:
- 成功: `/var/www/recharge-api/logs/success_YYYY-MM-DD.log`
- 失败: `/var/www/recharge-api/logs/failed_YYYY-MM-DD.log`
- 错误: `/var/www/recharge-api/logs/error_YYYY-MM-DD.log`

### 7.2 调试技巧

```php
// 在 ApiHook.php 中添加调试日志
public function handle()
{
    // 调试1: 记录接收到的订单
    \Log::info('ApiHook triggered', [
        'order_sn' => $this->order->order_sn,
        'goods_id' => $this->order->goods_id
    ]);

    $goodInfo = $this->goodsService->detail($this->order->goods_id);

    // 调试2: 记录API Hook配置
    \Log::info('API Hook config', [
        'api_hook' => $goodInfo->api_hook
    ]);

    if(empty($goodInfo->api_hook)){
        \Log::warning('API Hook is empty');
        return;
    }

    // 调试3: 记录POST数据
    $postdata = [...];
    \Log::info('Posting to API', [
        'url' => $goodInfo->api_hook,
        'data' => $postdata
    ]);

    // 调试4: 记录响应
    $response = file_get_contents(...);
    \Log::info('API Response', ['response' => $response]);
}
```

### 7.3 监控指标

- **队列积压**: `redis-cli -> llen queues:default`
- **API响应时间**: 在API中记录
- **成功率**: 统计成功/失败日志
- **充值金额**: 统计实际充值总额

---

## 八、常见问题

### Q1: API Hook 没有被调用?

**检查清单**:
1. ✅ 商品是否配置了 `api_hook`?
   ```sql
   SELECT gd_name, api_hook, type FROM goods WHERE id = 1;
   ```
2. ✅ 商品是否设置为自动发货 (type=1)?
3. ✅ Laravel 队列是否在运行?
   ```bash
   ps aux | grep queue:work
   ```
4. ✅ 查看队列日志是否有错误

### Q2: 充值失败怎么办?

**自动重试机制**:
```php
// ApiHook.php:21
public $tries = 2;  // 失败后会重试2次
```

**人工处理流程**:
1. 查看失败日志
2. 手动调用第三方API充值
3. 更新订单状态
4. 通知用户

### Q3: 如何防止重复充值?

**幂等性设计**:
```php
// 你的API中
$cacheKey = 'recharge_' . $data['order_sn'];

if (Cache::has($cacheKey)) {
    echo json_encode(['status' => 'already_processed']);
    exit;
}

Cache::put($cacheKey, true, 3600);

// 执行充值...
```

### Q4: API Hook 是同步还是异步?

**异步**!

```
订单完成 → 加入队列 → (延迟几秒) → 执行ApiHook
```

**优点**: 不阻塞订单流程
**缺点**: 有延迟,需要处理失败重试

---

## 九、安全建议

### 9.1 验证回调来源

```php
// 在你的API中验证
$allowedIPs = ['独角兽卡网服务器IP'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    http_response_code(403);
    exit('Forbidden');
}
```

### 9.2 使用签名验证

```php
// 独角兽卡网配置签名密钥
$signature = hash_hmac('sha256', json_encode($postdata), $secret);

// 发送到你的API
$postdata['signature'] = $signature;

// 你的API验证
$expectedSignature = hash_hmac('sha256', json_encode($data), $secret);
if ($data['signature'] !== $expectedSignature) {
    http_response_code(403);
    exit('Invalid signature');
}
```

### 9.3 敏感信息加密

```php
// API密钥不要硬编码
$apiKey = env('RECHARGE_API_KEY');

// 第三方平台凭证加密存储
encrypt_and_store_credential();
```

---

## 十、总结

### 代码注入位置总结

| 方案 | 注入位置 | 文件 | 行号 |
|------|---------|------|------|
| **API Hook** | 独立API服务 | `/var/www/recharge-api/public/index.php` | 全文 |
| **方案2-1** | 主方法 | `app/Service/OrderProcessService.php` | 489-524 |
| **方案2-2** | 充值函数 | `app/Service/OrderProcessService.php` | 新增方法 |
| **方案2-3** | 配置文件 | `config/recharge.php` | 新建 |
| **方案2-4** | 环境变量 | `.env` | 新增 |

### 推荐实施顺序

1. ✅ 使用 **API Hook 方案** (不影响升级)
2. ✅ 开发独立的充值API服务
3. ✅ 配置商品的 api_hook 字段
4. ✅ 测试完整流程
5. ✅ 部署监控和日志

### 核心要点

- **支付回调**: `AlipayController@notifyUrl` → `completedOrder()`
- **自动发货**: `processAuto()` 方法
- **API Hook**: `ApiHook::dispatch($order)` 异步任务
- **你的任务**: 开发充值API,接收回调,调用第三方平台

---

**文档版本**: v1.0
**最后更新**: 2025-01-01
**项目**: 独角兽卡网 (dujiaoka)
