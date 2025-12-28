# 充值账号 (userId) 获取与流转完整指南

## 一、核心问题

**Q: 给谁充值？userId 从哪里来？如何流转到充值API？**

**A: 用户在购买页面填写充值账号 → 存入订单 → 通过API回调传给你的充值服务**

---

## 二、完整数据流转链路

### 2.1 总体流程图

```
┌─────────────────────────────────────────────────────────────┐
│ 步骤1: 用户填写充值账号 (购买页面)                            │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 步骤2: 创建订单 (充值账号存入订单)                            │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 步骤3: 用户支付成功                                          │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 步骤4: 支付回调 → 订单完成                                    │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 步骤5: 触发API Hook (携带充值账号)                            │
└────────────────────┬────────────────────────────────────────┘
                     ↓ POST JSON
┌─────────────────────────────────────────────────────────────┐
│ 步骤6: 你的充值API接收 (提取充值账号)                         │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 步骤7: 调用第三方平台充值API (传入userId)                     │
└─────────────────────────────────────────────────────────────┘
```

---

## 三、详细步骤分析

### 步骤1: 用户填写充值账号

#### 3.1.1 配置自定义输入框

**后台管理配置**:
```
商品管理 → 编辑商品 → 其他输入框配置
```

**配置格式** (resources/lang/zh_CN/goods.php:38):
```
account=充值账号=true
```

**字段说明**:
- `account` - 唯一标识(英文)
- `充值账号` - 输入框显示名称
- `true` - 是否必填 (true=必填, false=选填)

**多个输入框示例**:
```
account=充值账号=true
server_id=服务器ID=false
character_role=角色名=false
```

#### 3.1.2 数据库存储

**表**: `goods`
**字段**: `other_ipu_cnf` (text类型, JSON格式)

**SQL 示例**:
```sql
-- 查看商品的自定义输入框配置
SELECT
    id,
    gd_name AS '商品名称',
    other_ipu_cnf AS '自定义输入框配置'
FROM goods
WHERE id = 1;

-- 输出示例:
-- | id | gd_name | other_ipu_cnf            |
-- |----|---------|--------------------------|
-- | 1  | Token充值 | account=充值账号=true  |
```

#### 3.1.3 前端显示

**购买页面** (resources/views/luna/static_pages/buy.blade.php):

根据 `other_ipu_cnf` 配置动态生成输入框:

```blade
<!-- 示例: 配置了 account=充值账号=true 后生成的HTML -->
<div class="form-group">
    <label>充值账号 <span class="required">*</span></label>
    <input type="text"
           name="account"
           class="form-control"
           placeholder="请输入充值账号"
           required>
</div>
```

**用户操作**:
1. 访问购买页面 `/buy/1`
2. 看到充值账号输入框
3. 填写自己的账号,例如: `user123`
4. 点击购买按钮

---

### 步骤2: 创建订单 (充值账号存入订单)

#### 3.2.1 提交订单数据

**用户提交的数据**:
```javascript
{
    goods_id: 1,
    buy_amount: 1,
    email: 'user@example.com',
    account: 'user123'  // ← 用户填写的充值账号
}
```

#### 3.2.2 订单创建逻辑

**文件**: `app/Service/OrderService.php`

**关键代码** (app/Service/OrderService.php:189-194):
```php
// 处理自定义输入框数据
if ($goods->type == Goods::MANUAL_PROCESSING && !empty($goods->other_ipu_cnf)) {
    $formatIpt = format_charge_input($goods->other_ipu_cnf);

    // ⭐ 将自定义输入框内容合并到订单信息中
    $orderInfo = array_merge($orderInfo, $formatIpt);
}
```

**format_charge_input 函数** (helpers.php):
```php
/**
 * 格式化自定义输入框配置
 * 将 account=user123 转换为数组
 */
function format_charge_input($other_ipu_cnf) {
    $inputs = [];
    $lines = explode("\n", $other_ipu_cnf);

    foreach ($lines as $line) {
        $parts = explode('=', $line);
        if (count($parts) >= 3) {
            $key = $parts[0];              // account
            $label = $parts[1];            // 充值账号
            $required = $parts[2] === 'true';  // true

            // 从用户提交的数据中获取值
            $value = request()->input($key, '');

            $inputs[$key] = [
                'label' => $label,
                'value' => $value,
                'required' => $required
            ];
        }
    }

    return $inputs;
}
```

#### 3.2.3 订单数据存储

**订单表**: `orders`

**关键字段**:
```sql
CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_sn VARCHAR(32) NOT NULL COMMENT '订单号',
    goods_id INT UNSIGNED NOT NULL COMMENT '商品ID',
    title VARCHAR(255) NOT NULL COMMENT '商品标题',
    email VARCHAR(255) NOT NULL COMMENT '邮箱',
    info TEXT COMMENT '订单资料',  -- ← 充值账号存在这里!
    actual_price DECIMAL(10,2) COMMENT '实际价格',
    type TINYINT NOT NULL COMMENT '订单类型 1自动 2人工',
    status TINYINT NOT NULL DEFAULT 1 COMMENT '订单状态',
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**存储格式** (info字段):
```
充值账号: user123
```

或 JSON 格式:
```json
{
    "account": {
        "label": "充值账号",
        "value": "user123",
        "required": true
    }
}
```

**查看订单数据**:
```sql
SELECT
    order_sn AS '订单号',
    email AS '邮箱',
    info AS '订单资料(充值账号)',
    actual_price AS '金额'
FROM orders
WHERE order_sn = 'ABC1234567890123';

-- 输出示例:
-- | 订单号            | 邮箱              | 订单资料       | 金额   |
-- |-------------------|-------------------|----------------|--------|
-- | ABC1234567890123  | user@example.com  | 充值账号: user123 | 100.00 |
```

---

### 步骤3: 用户支付成功

#### 3.3.1 支付流程

```
用户选择支付方式 (支付宝/微信等)
    ↓
跳转到支付平台
    ↓
用户完成支付
    ↓
支付平台回调独角兽卡网
```

#### 3.3.2 支付回调处理

**支付宝回调** (app/Http/Controllers/Pay/AlipayController.php:77):
```php
public function notifyUrl(Request $request)
{
    // 获取订单号
    $orderSN = $request->input('out_trade_no');

    // 查询订单 (包含充值账号信息)
    $order = $this->orderService->detailOrderSN($orderSN);

    // 验证支付状态
    if ($result->trade_status == 'TRADE_SUCCESS') {
        // ⭐ 调用订单完成方法
        $this->orderProcessService->completedOrder(
            $orderSN,
            $actualPrice,
            $tradeNo
        );
    }

    return 'success';
}
```

---

### 步骤4: 订单完成处理

#### 3.4.1 completedOrder 方法

**文件**: `app/Service/OrderProcessService.php:385-438`

```php
public function completedOrder(string $orderSN, float $actualPrice, string $tradeNo = '')
{
    // 1. 查询订单 (包含充值账号)
    $order = $this->orderService->detailOrderSN($orderSN);

    // ⭐ $order->info 字段包含用户填写的充值账号
    // 例如: "充值账号: user123"

    // 2. 区分订单类型
    if ($order->type == Order::AUTOMATIC_DELIVERY) {
        // 自动发货
        $completedOrder = $this->processAuto($order);
    } else {
        // 人工处理
        $completedOrder = $this->processManual($order);
    }

    // 3. ⭐ 触发API Hook (携带充值账号)
    ApiHook::dispatch($order);

    return $completedOrder;
}
```

**此时订单数据结构**:
```php
Order {
    order_sn: "ABC1234567890123",
    email: "user@example.com",
    info: "充值账号: user123",  // ← 充值账号在这里
    actual_price: 100.00,
    goods_id: 1,
    title: "Token充值 x 1"
}
```

---

### 步骤5: 触发API Hook (携带充值账号)

#### 3.5.1 ApiHook 队列任务

**文件**: `app/Jobs/ApiHook.php:58-86`

```php
public function handle()
{
    // 1. 获取商品信息
    $goodInfo = $this->goodsService->detail($this->order->goods_id);

    // 2. 判断是否配置了回调
    if(empty($goodInfo->api_hook)){
        return;
    }

    // 3. ⭐⭐⭐ 准备回调数据 (包含充值账号)
    $postdata = [
        'title' => $this->order->title,
        'order_sn' => $this->order->order_sn,
        'email' => $this->order->email,
        'actual_price' => $this->order->actual_price,
        'order_info' => $this->order->info,  // ← 用户填写的充值账号
        'good_id' => $goodInfo->id,
        'gd_name' => $goodInfo->gd_name
    ];

    // 4. POST 请求到你的API
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'content' => json_encode($postdata, JSON_UNESCAPED_UNICODE)
        ]
    ];
    $context  = stream_context_create($opts);

    // ⭐ 发送HTTP请求
    file_get_contents($goodInfo->api_hook, false, $context);
}
```

**发送的JSON数据**:
```json
{
    "title": "Token充值 x 1",
    "order_sn": "ABC1234567890123",
    "email": "user@example.com",
    "actual_price": 100.00,
    "order_info": "充值账号: user123",  // ← 充值账号在这里
    "good_id": 1,
    "gd_name": "Token充值"
}
```

---

### 步骤6: 你的充值API接收 (提取充值账号)

#### 3.6.1 API 入口代码

**文件**: `/var/www/recharge-api/public/index.php`

```php
<?php
/**
 * 接收独角兽卡网的回调
 * URL: https://your-api.com/recharge
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 1. 接收回调数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 验证数据
if (!$data || !isset($data['order_sn'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// 2. ⭐⭐⭐ 提取充值账号 (关键步骤!)
$userId = extractUserId($data);

// 记录日志
file_put_contents(
    '/var/www/recharge-api/logs/hook_' . date('Y-m-d') . '.log',
    date('Y-m-d H:i:s') . ' - Order: ' . $data['order_sn'] . ', UserID: ' . $userId . "\n",
    FILE_APPEND
);

try {
    // 3. ⭐⭐⭐ 调用第三方充值API (传入userId)
    $result = rechargeToken($userId, $data['actual_price']);

    if ($result['success']) {
        // 充值成功
        sendNotificationEmail($data['email'], $result);

        echo json_encode([
            'status' => 'success',
            'user_id' => $userId,
            'new_balance' => $result['balance']
        ]);
    } else {
        // 充值失败
        echo json_encode([
            'status' => 'failed',
            'error' => $result['error']
        ]);
    }

} catch (Exception $e) {
    // 异常处理
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * ⭐⭐⭐ 提取充值账号 (关键函数!)
 */
function extractUserId($orderData) {
    // 方式1: 从 order_info 提取 (最常见)
    if (!empty($orderData['order_info'])) {
        $orderInfo = $orderData['order_info'];

        // 格式1: "充值账号: user123"
        if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $orderInfo, $matches)) {
            return $matches[1];
        }

        // 格式2: "账号:user123"
        if (preg_match('/账号[:\s]+([^\s\n]+)/', $orderInfo, $matches)) {
            return $matches[1];
        }

        // 格式3: "account: user123"
        if (preg_match('/account[:\s]+([^\s\n]+)/i', $orderInfo, $matches)) {
            return $matches[1];
        }

        // 格式4: 直接就是账号
        if (strlen($orderInfo) > 0 && strlen($orderInfo) < 100) {
            return trim($orderInfo);
        }
    }

    // 方式2: 从 email 提取 (备选方案)
    if (!empty($orderData['email'])) {
        return $orderData['email'];
    }

    // 方式3: 返回默认值或抛出异常
    throw new Exception('无法提取充值账号');
}
```

#### 3.6.2 提取充值账号的多种方式

**场景1: 标准格式**
```php
// order_info: "充值账号: user123"
$userId = extractUserId($orderData);
// 结果: "user123"
```

**场景2: JSON格式**
```php
// order_info: '{"account":{"label":"充值账号","value":"user123"}}'
$data = json_decode($orderData['order_info'], true);
$userId = $data['account']['value'];
// 结果: "user123"
```

**场景3: 多个字段**
```php
// order_info: "充值账号: user123\n服务器ID: 1\n角色名: warrior"
preg_match('/充值账号[:\s]+([^\s\n]+)/', $orderInfo, $matches);
$userId = $matches[1];
$serverId = extractField($orderInfo, '服务器ID');
$roleName = extractField($orderInfo, '角色名');
```

#### 3.6.3 调试日志

```php
// 记录接收到的完整数据
file_put_contents(
    '/var/www/recharge-api/logs/debug_' . date('Y-m-d_His') . '.log',
    date('Y-m-d H:i:s') . " - Received data:\n" .
    "Order SN: " . $data['order_sn'] . "\n" .
    "Email: " . $data['email'] . "\n" .
    "Order Info: " . $data['order_info'] . "\n" .
    "Extracted UserID: " . $userId . "\n" .
    "--------------------------------------\n",
    FILE_APPEND
);
```

---

### 步骤7: 调用第三方平台充值API (传入userId)

#### 3.7.1 充值函数

```php
/**
 * ⭐⭐⭐ 调用第三方平台充值
 */
function rechargeToken($userId, $amount) {
    // 第三方平台配置
    $apiUrl = 'https://third-party-platform.com/api/v2/recharge';
    $apiKey = 'your_api_key';
    $apiSecret = 'your_api_secret';

    // ⭐ 构建请求参数
    $params = [
        'account' => $userId,        // ← 充值账号
        'amount' => $amount,         // 充值金额
        'currency' => 'CNY',
        'timestamp' => time(),
        'order_id' => $GLOBALS['data']['order_sn']  // 订单号
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
```

**发送给第三方平台的请求**:
```json
{
    "account": "user123",           // ← 充值账号
    "amount": 100.00,
    "currency": "CNY",
    "timestamp": 1704067200,
    "order_id": "ABC1234567890123",
    "signature": "abc123..."
}
```

---

## 四、完整数据流转示例

### 4.1 实际案例演示

**场景**: 用户购买Token充值商品

#### 阶段1: 用户购买

```
用户操作:
1. 访问 /buy/1 (Token充值商品)
2. 看到输入框: "充值账号: [________]"
3. 填写: user123
4. 填写邮箱: user@example.com
5. 点击购买
```

#### 阶段2: 订单数据

**数据库中的订单记录**:
```sql
SELECT order_sn, email, info, actual_price FROM orders WHERE order_sn = 'ABC1234567890123';

-- 结果:
-- | order_sn          | email              | info              | actual_price |
-- |-------------------|--------------------|-------------------|--------------|
-- | ABC1234567890123  | user@example.com   | 充值账号: user123 | 100.00       |
```

#### 阶段3: 支付成功后API回调

**独角兽卡网发送给你的API**:
```json
POST https://your-api.com/recharge
Content-Type: application/json

{
    "title": "Token充值 x 1",
    "order_sn": "ABC1234567890123",
    "email": "user@example.com",
    "actual_price": 100.00,
    "order_info": "充值账号: user123",
    "good_id": 1,
    "gd_name": "Token充值"
}
```

#### 阶段4: 你的API处理

```php
// 接收数据
$data = json_decode(file_get_contents('php://input'), true);

// ⭐ 提取充值账号
$userId = extractUserId($data);
// 结果: "user123"

// ⭐ 调用第三方充值API
$result = rechargeToken($userId, 100.00);
// 请求: { "account": "user123", "amount": 100.00, ... }
```

#### 阶段5: 第三方平台充值

**你的API → 第三方平台**:
```json
POST https://third-party-platform.com/api/v2/recharge

{
    "account": "user123",
    "amount": 100.00,
    "currency": "CNY",
    "timestamp": 1704067200,
    "order_id": "ABC1234567890123",
    "signature": "..."
}
```

**第三方平台响应**:
```json
{
    "success": true,
    "new_balance": 500.00,
    "transaction_id": "TXN987654321"
}
```

#### 阶段6: 通知用户

```php
// 发送邮件
sendNotificationEmail(
    'user@example.com',
    [
        'account' => 'user123',
        'amount' => 100.00,
        'new_balance' => 500.00,
        'transaction_id' => 'TXN987654321'
    ]
);
```

---

## 五、关键函数实现

### 5.1 提取充值账号 (完整版)

```php
/**
 * 提取充值账号 (支持多种格式)
 */
function extractUserId($orderData) {
    $orderInfo = $orderData['order_info'] ?? '';

    // 优先级1: 从 order_info 提取
    if (!empty($orderInfo)) {
        // 格式1: "充值账号: user123"
        $patterns = [
            '/充值账号[:\s]+([^\s\n]+)/',
            '/账号[:\s]+([^\s\n]+)/',
            '/account[:\s]+([^\s\n]+)/i',
            '/user\s*id[:\s]+([^\s\n]+)/i',
            '/用户名[:\s]+([^\s\n]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $orderInfo, $matches)) {
                return trim($matches[1]);
            }
        }

        // 尝试JSON解析
        $json_data = json_decode($orderInfo, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // 格式: {"account": "user123"}
            if (isset($json_data['account'])) {
                return $json_data['account'];
            }
            // 格式: {"account": {"value": "user123"}}
            if (isset($json_data['account']['value'])) {
                return $json_data['account']['value'];
            }
        }

        // 格式2: 直接就是账号 (简短文本)
        $orderInfo = trim($orderInfo);
        if (strlen($orderInfo) > 0 && strlen($orderInfo) < 100) {
            // 移除可能的标签
            $orderInfo = preg_replace('/\[.*?\]/', '', $orderInfo);
            $orderInfo = trim($orderInfo);
            if (!empty($orderInfo)) {
                return $orderInfo;
            }
        }
    }

    // 优先级2: 从 email 提取
    if (!empty($orderData['email'])) {
        return $orderData['email'];
    }

    // 优先级3: 使用订单号作为标识
    if (!empty($orderData['order_sn'])) {
        return $orderData['order_sn'];
    }

    // 都找不到,抛出异常
    throw new Exception('无法提取充值账号');
}
```

### 5.2 充值账号验证

```php
/**
 * 验证充值账号格式
 */
function validateUserId($userId) {
    // 检查长度
    if (strlen($userId) < 3 || strlen($userId) > 50) {
        throw new Exception('充值账号长度必须在3-50个字符之间');
    }

    // 检查格式 (根据实际情况调整)
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $userId)) {
        throw new Exception('充值账号格式不正确');
    }

    // 检查是否包含敏感词
    $blacklist = ['admin', 'root', 'test'];
    if (in_array(strtolower($userId), $blacklist)) {
        throw new Exception('充值账号包含敏感词');
    }

    return true;
}
```

---

## 六、常见问题

### Q1: 用户不填写充值账号怎么办?

**解决方案1**: 配置为必填
```
后台 → 商品管理 → 编辑商品 → 其他输入框配置
填写: account=充值账号=true  ← true表示必填
```

**解决方案2**: API中使用邮箱作为账号
```php
$userId = extractUserId($orderData);
// 如果用户没填写,会返回 email
```

### Q2: 如何支持多个充值字段?

**配置多个输入框**:
```
account=充值账号=true
server_id=服务器ID=false
role_name=角色名=false
```

**API中提取多个字段**:
```php
function extractAllFields($orderData) {
    $orderInfo = $orderData['order_info'];
    $fields = [];

    // 提取充值账号
    if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $orderInfo, $matches)) {
        $fields['account'] = $matches[1];
    }

    // 提取服务器ID
    if (preg_match('/服务器ID[:\s]+([^\s\n]+)/', $orderInfo, $matches)) {
        $fields['server_id'] = $matches[1];
    }

    // 提取角色名
    if (preg_match('/角色名[:\s]+([^\s\n]+)/', $orderInfo, $matches)) {
        $fields['role_name'] = $matches[1];
    }

    return $fields;
}
```

### Q3: 充值账号格式不对怎么办?

**在购买页面验证**:
```javascript
// 前端JS验证
function validateAccount() {
    const account = document.getElementById('account').value;

    if (account.length < 3) {
        alert('充值账号至少3个字符');
        return false;
    }

    if (!/^[a-zA-Z0-9_-]+$/.test(account)) {
        alert('充值账号只能包含字母、数字、下划线和连字符');
        return false;
    }

    return true;
}
```

**在API端验证**:
```php
// 后端PHP验证
function validateUserId($userId) {
    if (strlen($userId) < 3) {
        throw new Exception('充值账号过短');
    }

    // 返回错误给独角兽卡网
    return false;
}
```

### Q4: 如何测试充值账号流转?

**测试步骤**:
1. 配置商品: `account=测试账号=true`
2. 访问购买页面,填写: `testuser001`
3. 支付成功
4. 查看API日志:
   ```bash
   tail -f /var/www/recharge-api/logs/debug_*.log
   ```
5. 验证提取的userId是否为 `testuser001`

---

## 七、调试技巧

### 7.1 查看订单中的充值账号

```sql
-- 查看最近10个订单的充值账号
SELECT
    order_sn AS '订单号',
    email AS '邮箱',
    info AS '充值账号',
    actual_price AS '金额',
    created_at AS '创建时间'
FROM orders
ORDER BY created_at DESC
LIMIT 10;
```

### 7.2 API端调试日志

```php
// 详细日志
file_put_contents(
    '/var/www/recharge-api/logs/debug.log',
    date('Y-m-d H:i:s') . "\n" .
    "=== 收到回调 ===\n" .
    "Order SN: " . $data['order_sn'] . "\n" .
    "Email: " . $data['email'] . "\n" .
    "Order Info (原始): " . $data['order_info'] . "\n" .
    "Order Info (解析): " . print_r(json_decode($data['order_info'], true), true) . "\n" .
    "提取的UserID: " . $userId . "\n" .
    "充值金额: " . $data['actual_price'] . "\n" .
    "==================\n\n",
    FILE_APPEND
);
```

### 7.3 使用Postman测试

**创建测试请求**:
```
POST http://localhost/recharge-api/public/index.php
Content-Type: application/json

{
    "title": "测试商品",
    "order_sn": "TEST1234567890123",
    "email": "test@example.com",
    "actual_price": 10.00,
    "order_info": "充值账号: testuser001",
    "good_id": 1,
    "gd_name": "测试商品"
}
```

---

## 八、总结

### 核心流程

```
用户填写充值账号 (购买页面)
    ↓
存入订单 info 字段
    ↓
支付成功后触发 ApiHook
    ↓
通过 order_info 字段传递给你的API
    ↓
你的API从 order_info 中提取充值账号
    ↓
调用第三方平台充值API
```

### 关键字段

| 阶段 | 字段名 | 说明 | 示例 |
|------|--------|------|------|
| **配置** | `other_ipu_cnf` | 商品自定义输入框配置 | `account=充值账号=true` |
| **订单** | `orders.info` | 订单资料(包含充值账号) | `充值账号: user123` |
| **回调** | `order_info` | API回调中的充值账号 | `充值账号: user123` |
| **充值** | `account` | 第三方平台API的账号参数 | `user123` |

### 代码位置总结

| 位置 | 文件 | 作用 |
|------|------|------|
| **前端输入** | buy.blade.php | 显示充值账号输入框 |
| **订单存储** | OrderService.php:189 | 将充值账号存入订单 |
| **API回调** | ApiHook.php:210 | 将充值账号放入回调数据 |
| **账号提取** | recharge-api/index.php | 从回调数据提取充值账号 |
| **第三方充值** | rechargeToken() | 调用第三方平台充值 |

---

**文档版本**: v1.0
**最后更新**: 2025-01-01
**相关文档**: 自动发货与API回调充值完整指南.md, 商品回调配置与自动发货设置指南.md
