# file_get_contents() 方法详解与重定向实现

## 📖 目录

- [file_get_contents() 基础](#file_get_contents-基础)
- [参数详解](#参数详解)
- [Context 上下文详解](#context-上下文详解)
- [实际使用场景](#实际使用场景)
- [完整工作流程](#完整工作流程)
- [错误处理](#错误处理)
- [与其他方法对比](#与其他方法对比)
- [请求成功后重定向](#请求成功后重定向)

---

## file_get_contents() 基础

### 定义

`file_get_contents()` 是 PHP 的内置函数，用于**读取文件内容**或**发送HTTP请求**。

### 基本语法

```php
file_get_contents(
    string $filename,
    bool $use_include_path = false,
    resource $context = null,
    int $offset = 0,
    int $length = 0
): string|false
```

### 最简单的用法

```php
// 1. 读取本地文件
$content = file_get_contents('/path/to/file.txt');

// 2. 读取远程文件（发送GET请求）
$html = file_get_contents('https://www.example.com');
```

---

## 参数详解

### 参数列表

| 参数 | 类型 | 必需 | 说明 | 示例值 |
|------|------|------|------|--------|
| `$filename` | string | ✅ | 文件路径或URL | `'http://api.example.com'` |
| `$use_include_path` | bool | ❌ | 是否在include_path中查找 | `false` |
| `$context` | resource | ❌ | 上下文资源（控制HTTP行为） | `stream_context_create($opts)` |
| `$offset` | int | ❌ | 读取起始位置 | `0` |
| `$length` | int | ❌ | 读取最大长度 | `1024` |

### 参数1：$filename

```php
// 本地文件
file_get_contents('/var/www/html/config.json');

// HTTP URL
file_get_contents('http://api.example.com/users');

// HTTPS URL
file_get_contents('https://api.example.com/users');

// Docker 容器间通信
file_get_contents('http://novel-api:8080/api/recharge');
```

### 参数2：$use_include_path

```php
// false - 不在 include_path 中查找（HTTP请求始终用false）
file_get_contents('http://api.example.com', false);

// true - 在 include_path 中查找（仅用于本地文件）
file_get_contents('config.json', true);
```

### 参数3：$context

**最关键的参数！** 用于控制HTTP请求的行为。

```php
$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-type: application/json',
        'content' => '{"name":"John"}'
    ]
];
$context = stream_context_create($opts);
file_get_contents('http://api.example.com', false, $context);
```

---

## Context 上下文详解

### 什么是 Context？

Context 就像是给 HTTP 请求发的"指令说明书"，告诉它：
- 用什么方法（GET/POST/PUT/DELETE）
- 带什么请求头
- 发送什么数据
- 超时时间是多少
- 是否验证SSL证书

### 创建 Context 的步骤

```php
// Step 1: 定义选项数组
$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-type: application/json',
        'content' => json_encode($data),
        'timeout' => 30
    ]
];

// Step 2: 创建上下文资源
$context = stream_context_create($opts);

// Step 3: 传递给 file_get_contents()
$result = file_get_contents($url, false, $context);
```

### Context 选项完整列表

```php
$opts = [
    'http' => [
        // === 必需选项 ===
        'method'  => 'POST',                           // HTTP方法：GET, POST, PUT, DELETE

        // === 请求头 ===
        'header'  => "Content-type: application/json\r\n" .
                     "Authorization: Bearer token123\r\n" .
                     "User-Agent: MyAPI/1.0",

        // === 请求内容 ===
        'content' => json_encode($data),               // POST数据

        // === 超时设置 ===
        'timeout' => 30,                               // 超时时间（秒）

        // === 其他选项 ===
        'protocol_version' => '1.1',                   // HTTP版本：1.0, 1.1, 2.0
        'ignore_errors' => false,                      // 是否忽略HTTP错误码
        'max_redirects' => 5,                          // 最大重定向次数
        'follow_location' => true,                     // 是否跟随重定向

        // === 代理设置 ===
        'proxy' => 'tcp://proxy.example.com:8080',
        'request_fulluri' => true,

        // === SSL验证 ===
        'verify_peer' => true,                         // 验证对等证书
        'verify_host' => true,                         // 验证主机名
    ]
];
```

---

## 实际使用场景

### 场景1：简单的 GET 请求

```php
// 不需要 context
$html = file_get_contents('https://www.example.com');
```

**等效的 HTTP 请求：**
```http
GET / HTTP/1.1
Host: www.example.com
```

---

### 场景2：POST JSON 数据（最常用）

```php
$data = ['name' => 'John', 'age' => 30];

$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-type: application/json',
        'content' => json_encode($data)
    ]
];

$context = stream_context_create($opts);
$result = file_get_contents('http://api.example.com/users', false, $context);
```

**等效的 HTTP 请求：**
```http
POST /users HTTP/1.1
Host: api.example.com
Content-Type: application/json

{"name":"John","age":30}
```

---

### 场景3：带认证的请求

```php
$opts = [
    'http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer your_token\r\n" .
                     "Content-Type: application/json"
    ]
];

$context = stream_context_create($opts);
$result = file_get_contents('http://api.example.com/protected', false, $context);
```

**等效的 HTTP 请求：**
```http
GET /protected HTTP/1.1
Host: api.example.com
Authorization: Bearer your_token
Content-Type: application/json
```

---

### 场景4：在 ApiHook 中的实际应用

```php
/**
 * 发送POST请求的通用方法
 */
private function sendPostRequest($url, $data)
{
    // 1. 配置HTTP选项
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'timeout' => 30
        ]
    ];

    // 2. 创建上下文
    $context = stream_context_create($opts);

    // 3. 发送请求
    try {
        $result = @file_get_contents($url, false, $context);

        // 4. 处理响应
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

---

## 完整工作流程

### 以小说充值为例

```
1. 准备数据
   ↓
   $data = ['email' => 'user@gmail.com', 'order_sn' => '20241230123456'];

2. 编码为JSON
   ↓
   json_encode($data, JSON_UNESCAPED_UNICODE)
   → '{"email":"user@gmail.com","order_sn":"20241230123456"}'

3. 配置HTTP选项
   ↓
   $opts = [
       'http' => [
           'method'  => 'POST',
           'header'  => 'Content-type: application/json',
           'content' => '...',
           'timeout' => 30
       ]
   ];

4. 创建上下文
   ↓
   stream_context_create($opts) → resource(context)

5. 发送HTTP请求
   ↓
   file_get_contents('http://novel-api:8080/api/recharge', false, $context)

6. 网络传输
   ↓
   POST /api/recharge HTTP/1.1
   Host: novel-api:8080
   Content-Type: application/json

   {"email":"user@gmail.com","order_sn":"20241230123456"}

7. novel-api 处理请求
   ↓
   验证参数 → 充值逻辑 → 返回结果

8. 接收响应
   ↓
   $result = '{"code":200,"msg":"充值成功","data":{"balance":999}}'

9. 处理结果
   ↓
   if ($result === false) {
       记录错误日志
   } else {
       记录成功日志
       解析响应数据
   }
```

---

## 错误处理

### 错误抑制符 `@`

```php
// 不使用 @，失败时会产生 PHP Warning
$result = file_get_contents('http://invalid-url');
// PHP Warning:  file_get_contents(): php_network_getaddresses: getaddrinfo failed...

// 使用 @，抑制警告
$result = @file_get_contents('http://invalid-url');
// 静默失败
```

### 正确的错误处理

```php
$context = stream_context_create($opts);
$result = @file_get_contents($url, false, $context);

if ($result === false) {
    // 请求失败
    $error = error_get_last();
    \Log::error('请求失败', [
        'url' => $url,
        'error' => $error['message']
    ]);
} else {
    // 请求成功
    \Log::info('请求成功', [
        'url' => $url,
        'response' => $result
    ]);
}
```

### 常见错误类型

| 错误 | 原因 | 解决方法 |
|------|------|----------|
| `false` | URL无法访问 | 检查URL是否正确，网络是否连通 |
| `false` | 超时 | 增加 `timeout` 值 |
| `false` | DNS解析失败 | 检查域名是否正确 |
| `false` | SSL证书验证失败 | 设置 `verify_peer => false` |

---

## 与其他方法对比

### file_get_contents vs cURL

| 特性 | file_get_contents | cURL |
|------|-------------------|------|
| **简单性** | ✅ 简单直接 | ❌ 配置复杂 |
| **功能** | ⚠️ 基础功能 | ✅ 功能强大 |
| **性能** | ⚠️ 一般 | ✅ 更好 |
| **错误处理** | ⚠️ 较弱 | ✅ 完善 |
| **并发请求** | ❌ 不支持 | ✅ 支持 |
| **调试能力** | ❌ 有限 | ✅ 详细 |
| **进度监控** | ❌ 不支持 | ✅ 支持 |
| **HTTP/2** | ❌ 不支持 | ✅ 支持 |

### 使用建议

**✅ 使用 file_get_contents 的场景：**
- 简单的 GET/POST 请求
- 不需要复杂的HTTP功能
- 快速实现原型
- 默认设置就能满足需求

**✅ 使用 cURL 的场景：**
- 需要详细调试信息
- 需要并发请求
- 需要进度监控
- 需要复杂的认证逻辑
- 需要更好的性能
- 需要HTTP/2支持

### cURL 等效写法

```php
// file_get_contents 写法
$opts = ['http' => ['method' => 'POST']];
$context = stream_context_create($opts);
$result = @file_get_contents($url, false, $context);

// 等效的 cURL 写法
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($ch);
curl_close($ch);
```

---

## 请求成功后重定向

### 场景说明

在某些业务场景中，API调用成功后，您可能希望：
1. **前端页面重定向**：跳转到充值成功页面
2. **API返回重定向URL**：让前端根据返回的URL进行跳转

### 方案对比

| 方案 | 适用场景 | 实现位置 |
|------|----------|----------|
| **方案1：在响应中返回重定向URL** | 前端需要知道跳转地址 | ApiHook.php（后端） |
| **方案2：直接在前端重定向** | 支付成功后自动跳转 | 支付回调页面（前端） |
| **方案3：在API响应中设置重定向头** | RESTful API标准做法 | API接口 |

---

### 方案1：在响应中返回重定向URL（推荐）

#### 修改 sendPostRequest 方法

```php
/**
 * 发送POST请求的通用方法
 * @param string $url API地址
 * @param array $data POST数据
 * @return array 返回包含成功状态和重定向URL的数组
 */
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

            return [
                'success' => false,
                'redirect_url' => null
            ];
        }

        // 解析响应
        $response = json_decode($result, true);

        \Log::info('API Hook请求成功', [
            'url' => $url,
            'response' => $response
        ]);

        // ⭐ 检查响应中是否有重定向URL
        $redirectUrl = null;
        if (isset($response['redirect_url'])) {
            $redirectUrl = $response['redirect_url'];
        } elseif (isset($response['data']['redirect_url'])) {
            $redirectUrl = $response['data']['redirect_url'];
        }

        return [
            'success' => true,
            'redirect_url' => $redirectUrl,
            'response' => $response
        ];

    } catch (\Exception $e) {
        \Log::error('API Hook异常', [
            'url' => $url,
            'data' => $data,
            'exception' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'redirect_url' => null
        ];
    }
}
```

#### 修改 callNovelApi 方法

```php
/**
 * 调用小说充值API
 */
private function callNovelApi($goodInfo)
{
    $apiUrl = env('NOVEL_API_URL', '');

    if (empty($apiUrl)) {
        return;
    }

    // 从订单info中提取邮箱
    $email = '';
    if (!empty($this->order->info)) {
        if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
            $email = $matches[1];
        }
    }

    $postdata = [
        'email' => $email,
        'order_sn' => $this->order->order_sn,
        'amount' => $this->order->actual_price,
        'good_name' => $goodInfo->gd_name,
        'timestamp' => time(),
        // ⭐ 传递前端回调URL
        'callback_url' => url('/payment/success?order_sn=' . $this->order->order_sn)
    ];

    // ⭐ 接收返回结果
    $result = $this->sendPostRequest($apiUrl, $postdata);

    // ⭐ 如果API返回了重定向URL，记录到订单
    if ($result['success'] && !empty($result['redirect_url'])) {
        \Log::info('API返回重定向URL', [
            'order_sn' => $this->order->order_sn,
            'redirect_url' => $result['redirect_url']
        ]);

        // 可以保存到订单的某个字段，供前端查询
        // 例如：$this->order->redirect_url = $result['redirect_url'];
        // $this->order->save();
    }
}
```

#### API 端返回格式示例

```json
{
    "code": 200,
    "msg": "充值成功",
    "data": {
        "balance": 999.99,
        "redirect_url": "https://novel-site.com/success?order_id=12345"
    }
}
```

---

### 方案2：前端主动重定向

#### 支付成功页面重定向

```blade
{{-- resources/views/unicorn/static_pages/payment-success.blade.php --}}

<script>
// 支付成功后，检查是否需要重定向
document.addEventListener('DOMContentLoaded', function() {
    // 方式1：延迟跳转
    setTimeout(function() {
        window.location.href = 'https://novel-site.com/success';
    }, 3000); // 3秒后跳转

    // 方式2：根据API返回的URL跳转
    @if(isset($redirectUrl))
    window.location.href = '{{ $redirectUrl }}';
    @endif
});
</script>

<div class="alert alert-success">
    <h4>充值成功！</h4>
    <p>页面将在 <span id="countdown">3</span> 秒后自动跳转...</p>
    <p>如果没有跳转，<a href="https://novel-site.com/success">点击这里</a></p>
</div>

<script>
// 倒计时
let count = 3;
setInterval(function() {
    count--;
    document.getElementById('countdown').textContent = count;
    if (count <= 0) {
        window.location.href = 'https://novel-site.com/success';
    }
}, 1000);
</script>
```

#### 支付回调处理

```php
// app/Http/Controllers/Home/PaymentController.php

public function success(Request $request)
{
    $orderSN = $request->input('order_sn');
    $order = $this->orderService->detailOrderSN($orderSN);

    if (!$order) {
        return redirect('/')->with('error', '订单不存在');
    }

    // ⭐ 检查订单是否有重定向URL
    if (!empty($order->redirect_url)) {
        return redirect($order->redirect_url);
    }

    // ⭐ 根据 from 参数决定跳转
    $from = '';
    if (!empty($order->info)) {
        if (preg_match('/来源[:\s]+([^\s\n]+)/', $order->info, $matches)) {
            $from = $matches[1];
        }
    }

    // ⭐ 根据来源跳转到不同的成功页面
    $redirectUrls = [
        'novel' => 'https://novel-site.com/success',
        'game' => 'https://game-site.com/success',
        'vip' => 'https://vip-site.com/success',
        'app' => 'app://payment/success'  // App深度链接
    ];

    if (isset($redirectUrls[$from])) {
        return redirect($redirectUrls[$from]);
    }

    // 默认显示成功页面
    return view('static_pages/payment-success', [
        'order' => $order,
        'from' => $from
    ]);
}
```

---

### 方案3：在API响应中设置HTTP重定向头

#### API 端实现

```go
// novel-api 端的 Go 代码示例
func RechargeHandler(w http.ResponseWriter, r *http.Request) {
    // 处理充值逻辑...

    // 充值成功后，设置重定向头
    w.Header().Set("Location", "https://novel-site.com/success?order_id=12345")
    w.Header().Set("Content-Type", "application/json")
    w.WriteHeader(http.StatusCreated) // 201 或其他成功状态码

    json.NewEncoder(w).Encode(map[string]interface{}{
        "code": 200,
        "msg": "充值成功",
        "data": map[string]interface{}{
            "balance": 999.99,
        },
    })
}
```

---

### 完整的端到端流程

```
1. 用户在小说网站点击充值
   ↓
   https://novel-site.com/buy?email=user@gmail.com

2. 跳转到 dujiaoka 支付页面
   ↓
   http://dujiaoka:9595/buy/1?email=user@gmail.com&from=novel

3. 用户完成支付
   ↓
   支付回调 → 创建 ApiHook Job

4. ApiHook 执行
   ↓
   callNovelApi() → 发送请求到 novel-api

5. novel-api 处理充值
   ↓
   充值成功 → 返回响应

6. novel-api 返回 JSON
   ↓
   {
     "code": 200,
     "msg": "充值成功",
     "data": {
       "balance": 999.99,
       "redirect_url": "https://novel-site.com/account/recharge?success=1"
     }
   }

7. ApiHook 接收响应
   ↓
   记录日志 → 保存 redirect_url 到订单（可选）

8. 用户查看订单详情
   ↓
   http://dujiaoka:9595/order-detail/20241230123456

9. 前端检测到 redirect_url
   ↓
   显示"充值成功，正在跳转..."页面

10. 3秒后自动跳转
    ↓
    https://novel-site.com/account/recharge?success=1
```

---

### 最佳实践建议

#### ✅ 推荐做法

1. **API返回重定向URL**（而不是直接在后端跳转）
   - 原因：保持API的幂等性，前端可以灵活处理

2. **使用前端重定向**（而不是后端header Location）
   - 原因：可以显示过渡页面，提升用户体验

3. **添加延迟跳转**
   - 原因：让用户看到成功提示，避免突兀的跳转

4. **提供手动跳转按钮**
   - 原因：自动跳转可能被浏览器拦截

#### ❌ 不推荐做法

1. 在异步Job中直接重定向
   ```php
   // ❌ 错误：Job中没有HTTP响应对象
   public function handle()
   {
       // ...
       return redirect($url);  // 这样是不行的
   }
   ```

2. 在API调用后立即重定向
   ```php
   // ❌ 错误：这是异步后台任务，不能影响用户浏览器
   private function sendPostRequest($url, $data)
   {
       file_get_contents($url, false, $context);
       header('Location: https://novel-site.com');  // 不会生效
   }
   ```

---

## 实际代码示例

### 完整的重定向实现

#### Step 1: 修改 ApiHook.php

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
            return ['success' => false, 'redirect_url' => null];
        }

        $response = json_decode($result, true);

        // ⭐ 提取重定向URL
        $redirectUrl = $response['data']['redirect_url'] ?? null;

        return [
            'success' => true,
            'redirect_url' => $redirectUrl,
            'response' => $response
        ];

    } catch (\Exception $e) {
        return ['success' => false, 'redirect_url' => null];
    }
}

private function callNovelApi($goodInfo)
{
    $apiUrl = env('NOVEL_API_URL', '');
    if (empty($apiUrl)) {
        return;
    }

    $email = '';
    if (!empty($this->order->info)) {
        if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
            $email = $matches[1];
        }
    }

    $postdata = [
        'email' => $email,
        'order_sn' => $this->order->order_sn,
        'amount' => $this->order->actual_price,
        'good_name' => $goodInfo->gd_name,
        'timestamp' => time()
    ];

    $result = $this->sendPostRequest($apiUrl, $postdata);

    // ⭐ 保存重定向URL到订单（需要数据库字段支持）
    if ($result['success'] && !empty($result['redirect_url'])) {
        // 方式1：保存到订单的备注字段
        $this->order->add_info($result['redirect_url']);

        // 方式2：保存到缓存
        Cache::put("redirect_{$this->order->order_sn}", $result['redirect_url'], 3600);
    }
}
```

#### Step 2: 修改订单查询接口

```php
// app/Http/Controllers/Home/OrderController.php

public function detailOrderSN(string $orderSN)
{
    $order = $this->orderService->detailOrderSN($orderSN);

    if (!$order) {
        return $this->err(__('dujiaoka.prompt.order_does_not_exist'));
    }

    // ⭐ 检查是否需要重定向
    $redirectUrl = Cache::get("redirect_{$orderSN}");

    if ($redirectUrl) {
        // 方式1：直接跳转
        // return redirect($redirectUrl);

        // 方式2：显示过渡页面（推荐）
        return view('static_pages/redirect', [
            'redirect_url' => $redirectUrl,
            'delay' => 3
        ]);
    }

    return $this->render('static_pages/orderinfo', ['orders' => [$order]], __('dujiaoka.page-title.order-detail'));
}
```

#### Step 3: 创建重定向过渡页面

```blade
{{-- resources/views/unicorn/static_pages/redirect.blade.php --}}

@extends('unicorn.layouts.seo')

@section('content')
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body text-center">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-success" style="font-size: 64px;"></i>
                    </div>

                    <h4 class="card-title mb-3">充值成功！</h4>

                    <p class="card-text">
                        页面将在 <span id="countdown" class="badge bg-primary">{{ $delay }}</span> 秒后自动跳转...
                    </p>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        如果没有自动跳转，请
                        <a href="{{ $redirectUrl }}" class="alert-link">点击这里</a>
                    </div>

                    <div class="mt-4">
                        <a href="{{ url('/') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-home"></i> 返回首页
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@stop

@section('js')
<script>
let count = {{ $delay }};
const countdownElement = document.getElementById('countdown');

const timer = setInterval(function() {
    count--;
    countdownElement.textContent = count;

    if (count <= 0) {
        clearInterval(timer);
        window.location.href = '{{ $redirectUrl }}';
    }
}, 1000);
</script>
@stop
```

---

## 总结

### file_get_contents() 核心要点

1. **简单直接**：适合基础HTTP请求
2. **Context是关键**：通过stream_context_create()控制请求行为
3. **错误抑制**：使用@避免警告，配合error_get_last()获取错误信息
4. **返回值检查**：始终检查返回值是否为false

### 重定向实现要点

1. **异步Job不能重定向**：ApiHook是后台任务，没有HTTP响应对象
2. **API返回URL**：让API在JSON响应中包含redirect_url字段
3. **前端执行跳转**：在用户查看订单时，根据返回的URL跳转
4. **用户体验**：添加延迟跳转和手动跳转按钮

---

## 相关文档

- [ApiHook多渠道充值回调实现详解](./ApiHook多渠道充值回调实现详解.md)
- [自动发货与API回调充值完整指南](./自动发货与API回调充值完整指南.md)

---

**📌 文档版本：v1.0.0**
**最后更新：2024-12-30**
