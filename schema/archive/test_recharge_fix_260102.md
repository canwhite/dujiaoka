# 三方平台自动充token修复 - 测试指南

**修复日期**: 2026-01-02
**修复内容**: P0/P1/P2问题修复

---

## 📋 修复内容总结

### ✅ P0 - 高优先级问题（已修复）

#### 1. API响应验证逻辑
**文件**: `app/Jobs/ApiHook.php:260-293`

**修复内容**:
- ✅ 添加HTTP状态码检查
- ✅ 解析JSON响应的业务状态
- ✅ 验证`response['success']`字段
- ✅ 区分HTTP请求失败和业务失败
- ✅ 添加详细的错误日志

**修复前**:
```php
if ($result === false) {
    \Log::error('API Hook请求失败');
} else {
    \Log::info('API Hook请求成功');  // ❌ 没有检查业务状态
}
```

**修复后**:
```php
if ($result === false) {
    \Log::error('API Hook HTTP请求失败');
    return;
}

$response = json_decode($result, true);

if ($type !== 'default') {
    if (!$response || !isset($response['success'])) {
        \Log::error('API Hook返回格式错误');
        return;
    }

    if (!$response['success']) {
        \Log::error('API Hook业务失败');
        return;
    }

    \Log::info('API Hook充值成功');
}
```

#### 2. 充值账号提取逻辑改进
**文件**: `app/Jobs/ApiHook.php:135-160`

**修复内容**:
- ✅ 添加备用方案：提取失败时使用订单邮箱
- ✅ 验证账号不为空
- ✅ 添加详细的日志记录

**修复前**:
```php
$email = '';
if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
    $email = $matches[1];
}
// ⚠️ 如果提取失败，$email为空，API会拒绝请求
```

**修复后**:
```php
$email = '';
if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
    $email = $matches[1];
}

// ⭐ 备用方案：使用订单邮箱
if (empty($email)) {
    \Log::info('未提取到充值账号，使用订单邮箱');
    $email = $this->order->email;
}

if (empty($email)) {
    \Log::error('充值账号为空，无法调用充值API');
    return;
}
```

---

### ✅ P1 - 中优先级问题（已修复）

#### 1. NOVEL_API_URL未配置警告
**文件**: `app/Jobs/ApiHook.php:127-132`

**修复内容**:
```php
if (empty($apiUrl)) {
    \Log::warning('NOVEL_API_URL未配置，无法调用小说充值API', [
        'order_sn' => $this->order->order_sn,
        'goods_id' => $goodInfo->id
    ]);
    return;
}
```

#### 2. from参数大小写规范化
**文件**: `app/Jobs/ApiHook.php:92`

**修复内容**:
```php
// 修复前：switch ($from)
// 修复后：switch (strtolower($from))
switch (strtolower($from)) {
    case 'novel':  // ✅ 现在支持 Novel, NOVEL, novel 等所有大小写
```

---

### ✅ P2 - 低优先级问题（已修复）

#### 卡密发放控制开关
**文件**:
- `app/Service/OrderProcessService.php:489-584`
- `.env.example:55-62`

**新增环境变量**:
```bash
# 是否使用卡密发货
# true: 使用卡密发货（原有逻辑）
# false: 不使用卡密，直接标记完成（适用于API Hook充值）
RECHARGE_USE_CARMIS=true
```

**实现逻辑**:
1. 检查环境变量`RECHARGE_USE_CARMIS`
2. 如果为`false`，调用`processAutoWithoutCarmis()`
3. 如果为`true`，使用原有`processAutoWithCarmis()`逻辑

**processAutoWithoutCarmis方法**:
```php
private function processAutoWithoutCarmis(Order $order): Order
{
    // 直接标记订单为完成，不发放卡密
    $order->status = Order::STATUS_COMPLETED;
    $order->save();

    // 发送邮件（包含用户输入的充值信息）
    MailSend::dispatch($order->email, $mailBody['tpl_name'], $mailBody['tpl_content']);

    // 记录日志
    \Log::info('订单自动完成（无卡密发货）');

    return $order;
}
```

---

## 🧪 测试步骤

### 1. 环境配置

#### 1.1 更新.env文件
```bash
# 编辑.env文件
vim /Users/zack/Desktop/dujiaoka/.env
```

添加以下配置：
```bash
# 三方平台自动充值配置
RECHARGE_USE_CARMIS=false
NOVEL_API_URL=http://novel-api:8080/api/v1/users/recharge
```

**配置说明**:
- `RECHARGE_USE_CARMIS=false`: 不使用卡密发货（API Hook充值模式）
- `NOVEL_API_URL`: 小说网站充值API地址

#### 1.2 清除缓存
```bash
php artisan cache:clear
php artisan config:clear
php artisan queue:restart
```

---

### 2. 测试场景

#### 场景1: 正常充值流程（充值账号格式正确）

**步骤**:
1. 访问购买页面并添加from参数:
   ```
   http://your-domain.com/buy?gid=1&from=novel
   ```

2. 填写充值账号（格式：`充值账号: user123`）

3. 完成支付

4. 检查日志:
   ```bash
   tail -f storage/logs/laravel.log | grep "API Hook"
   ```

**预期结果**:
- ✅ 订单info包含：`充值账号: user123` 和 `来源: novel`
- ✅ 日志显示："提取到充值账号"
- ✅ 日志显示："API Hook充值成功"
- ✅ 订单状态：STATUS_COMPLETED
- ✅ 邮件发送成功

**验证SQL**:
```sql
SELECT order_sn, info, status FROM orders ORDER BY id DESC LIMIT 1;
```

---

#### 场景2: 充值账号格式不正确（测试备用方案）

**步骤**:
1. 访问购买页面
   ```
   http://your-domain.com/buy?gid=1&from=novel
   ```

2. 只填写邮箱，不填写"充值账号:"前缀

3. 完成支付

**预期结果**:
- ✅ 日志显示："未提取到充值账号，使用订单邮箱"
- ✅ 日志显示："API Hook充值成功"
- ✅ 充值账号使用订单邮箱

---

#### 场景3: from参数大小写测试

**步骤**:
1. 使用不同的from参数大小写访问:
   ```
   http://your-domain.com/buy?gid=1&from=Novel
   http://your-domain.com/buy?gid=1&from=NOVEL
   http://your-domain.com/buy?gid=1&from=novel
   ```

2. 完成支付

**预期结果**:
- ✅ 所有情况都能正确路由到callNovelApi()
- ✅ API被成功调用

---

#### 场景4: NOVEL_API_URL未配置测试

**步骤**:
1. 临时修改.env:
   ```bash
   NOVEL_API_URL=
   ```

2. 清除配置缓存:
   ```bash
   php artisan config:clear
   ```

3. 完成支付

**预期结果**:
- ✅ 日志显示："NOVEL_API_URL未配置，无法调用小说充值API"
- ✅ 订单仍然正常完成（RECHARGE_USE_CARMIS=false时）
- ✅ 不影响订单流程

---

#### 场景5: API返回失败测试

**步骤**:
1. 模拟API返回失败（需要novel-api支持）

2. 完成支付

**预期结果**:
- ✅ 日志显示："API Hook业务失败"
- ✅ 日志包含API返回的错误信息
- ✅ 订单正常完成（本地逻辑不影响）

---

#### 场景6: 卡密发放开关测试

**测试RECHARGE_USE_CARMIS=true**:
```bash
# 1. 修改.env
RECHARGE_USE_CARMIS=true

# 2. 清除缓存
php artisan config:clear

# 3. 下单并支付
```

**预期结果**:
- ✅ 系统尝试从卡密表提取卡密
- ✅ 如果没有库存，订单标记为异常

**测试RECHARGE_USE_CARMIS=false**:
```bash
# 1. 修改.env
RECHARGE_USE_CARMIS=false

# 2. 清除缓存
php artisan config:clear

# 3. 下单并支付
```

**预期结果**:
- ✅ 不检查卡密库存
- ✅ 订单直接标记为完成
- ✅ 日志显示："订单自动完成（无卡密发货）"

---

### 3. 日志检查

#### 3.1 查看所有API Hook日志
```bash
tail -f storage/logs/laravel.log | grep "API Hook"
```

#### 3.2 查看充值成功日志
```bash
tail -f storage/logs/laravel.log | grep "API Hook充值成功"
```

#### 3.3 查看充值失败日志
```bash
tail -f storage/logs/laravel.log | grep "API Hook.*失败"
```

#### 3.4 查看无卡密发货日志
```bash
tail -f storage/logs/laravel.log | grep "订单自动完成（无卡密发货）"
```

---

### 4. 数据库验证

#### 4.1 检查订单info字段
```sql
SELECT
    order_sn,
    info,
    status,
    created_at
FROM orders
ORDER BY id DESC
LIMIT 10;
```

**预期输出**:
```
order_sn      | info                            | status
--------------|---------------------------------|-------
ABC123        | 充值账号: user123               | 3
              | 来源: novel                     |
```

#### 4.2 检查from参数是否保存
```sql
SELECT
    order_sn,
    info,
    CASE
        WHEN info LIKE '%来源:%' THEN 'YES'
        ELSE 'NO'
    END AS has_from
FROM orders
ORDER BY id DESC
LIMIT 10;
```

---

### 5. API验证

#### 5.1 测试NOVEL_API_URL是否可访问
```bash
curl -X POST http://novel-api:8080/api/v1/users/recharge \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "order_sn": "TEST123",
    "amount": 10,
    "good_name": "测试商品",
    "timestamp": 1609459200
  }'
```

**预期响应（成功）**:
```json
{
  "success": true,
  "message": "充值成功",
  "data": {
    "balance": 100,
    "transaction_id": "TXN123"
  }
}
```

**预期响应（失败）**:
```json
{
  "success": false,
  "message": "用户不存在"
}
```

---

## 📊 测试检查表

### 功能测试
- [ ] from参数正确捕获并存储
- [ ] 充值账号正确提取（格式正确时）
- [ ] 充值账号备用方案（格式错误时使用订单邮箱）
- [ ] from参数大小写不敏感
- [ ] API响应验证（成功/失败区分）
- [ ] NOVEL_API_URL未配置时警告
- [ ] RECHARGE_USE_CARMIS=false时不发卡密
- [ ] RECHARGE_USE_CARMIS=true时正常发卡密

### 日志测试
- [ ] 充值成功日志完整
- [ ] 充值失败日志包含详细错误信息
- [ ] HTTP请求失败和业务失败区分清楚
- [ ] 无卡密发货日志记录

### 边界条件
- [ ] from参数为空
- [ ] from参数大小写混合
- [ ] 充值账号格式错误
- [ ] 订单邮箱为空
- [ ] NOVEL_API_URL为空
- [ ] API返回格式错误
- [ ] API返回success=false

---

## 🔧 故障排查

### 问题1: 日志中没有"API Hook充值成功"

**可能原因**:
1. NOVEL_API_URL未配置或配置错误
2. API未启动或网络不通
3. API返回格式不符合预期

**排查步骤**:
```bash
# 1. 检查配置
cat .env | grep NOVEL_API_URL

# 2. 测试API连通性
curl -X POST $NOVEL_API_URL ...

# 3. 查看详细错误日志
tail -f storage/logs/laravel.log | grep -A 5 "API Hook"
```

### 问题2: 订单info中没有"来源: novel"

**可能原因**:
1. OrderController没有正确捕获from参数
2. 代码修改未生效

**排查步骤**:
```bash
# 1. 检查OrderController代码
grep -A 5 "追加 from 参数" app/Http/Controllers/Home/OrderController.php

# 2. 清除缓存
php artisan cache:clear

# 3. 重新测试
```

### 问题3: RECHARGE_USE_CARMIS=false仍然要求卡密

**可能原因**:
1. .env配置未生效
2. 代码逻辑错误

**排查步骤**:
```bash
# 1. 验证配置
php artisan tinker
>>> env('RECHARGE_USE_CARMIS')
=> "false"

# 2. 清除配置缓存
php artisan config:clear
```

---

## ✅ 修复验证完成标准

所有以下条件满足，即表示修复成功：

1. ✅ P0问题：API响应验证正常，能区分成功/失败
2. ✅ P0问题：充值账号提取有备用方案
3. ✅ P1问题：NOVEL_API_URL未配置有警告日志
4. ✅ P1问题：from参数支持大小写
5. ✅ P2问题：RECHARGE_USE_CARMIS可以控制卡密发放
6. ✅ 所有测试场景通过
7. ✅ 日志完整且有意义

---

## 📝 相关文件清单

修改的文件：
1. `app/Jobs/ApiHook.php` - API Hook逻辑修复
2. `app/Service/OrderProcessService.php` - 卡密发放控制
3. `.env.example` - 配置示例

新增配置：
- `RECHARGE_USE_CARMIS` - 卡密发货开关
- `NOVEL_API_URL` - 小说充值API地址（已存在，新增文档）

---

**最后更新**: 2026-01-02
**修复者**: Claude Code
