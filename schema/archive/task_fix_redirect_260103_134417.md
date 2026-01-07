# Task: 修复支付成功后from参数重定向问题

**任务ID**: task_fix_redirect_260103_134417
**创建时间**: 2026-01-03 13:44:17
**状态**: 进行中
**目标**: 修复支付成功后无法根据from参数重定向到指定位置的问题

## 问题描述
支付成功后，页面应该根据from参数重定向到指定位置（如novel网站），但实际却跳转到了订单详情页。

## 最终目标
1. 找出重定向失败的根本原因
2. 检查PayController如何传递from参数
3. 检查qrpay视图如何使用from参数
4. 修复重定向逻辑
5. 测试验证修复效果

## 拆解步骤

### 1. 分析PayController的from参数传递
- [ ] 检查PayController如何读取from
- [ ] 检查PayController如何传递from给视图
- [ ] 检查render方法的调用时机

### 2. 分析qrpay视图的重定向逻辑
- [ ] 检查qrpay.blade.php的JavaScript重定向代码
- [ ] 检查from参数是否正确接收
- [ ] 检查redirect_urls配置是否正确

### 3. 排查from参数传递链路
- [ ] 检查订单创建时from是否正确存储
- [ ] 检查支付页面from是否正确读取
- [ ] 检查前端from是否正确渲染

### 4. 修复重定向逻辑
- [ ] 根据问题原因修复代码
- [ ] 测试修复效果

## 当前进度
### 问题已定位并修复 ✅

## 问题根本原因

### 支付流程对比

#### ✅ 正常流程（支付宝扫码等）
```
用户扫码支付
  ↓
进入qrpay页面（显示二维码）
  ↓
JavaScript轮询订单状态
  ↓
支付成功后，qrpay根据from参数重定向
```

#### ❌ 问题流程（Epusdt等）
```
用户扫码支付
  ↓
跳转到bepusdt第三方支付页面
  ↓
bepusdt支付成功后调用returnUrl
  ↓
returnUrl直接跳转到订单详情页 ❌
  ↓
绕过了qrpay的from重定向逻辑
```

### 核心问题
**文件**: `app/Http/Controllers/Pay/EpusdtController.php:104-110`

**原始代码**:
```php
public function returnUrl(Request $request)
{
    $oid = $request->get('order_id');
    sleep(2);
    return redirect(url('detail-order-sn', ['orderSN' => $oid])); // ❌ 直接跳转到订单详情
}
```

**问题分析**:
- returnUrl直接跳转到订单详情页
- 完全忽略了from参数
- 绕过了qrpay页面的from重定向逻辑
- 所有支付方式（Epusdt/Paysapi/Yipay/Vpay/TokenPay）都有这个问题

## 修复方案

### 修复代码
**文件**: `app/Http/Controllers/Pay/EpusdtController.php:104-138`

```php
public function returnUrl(Request $request)
{
    $oid = $request->get('order_id');
    sleep(2);

    // ⭐ 获取订单信息
    $order = $this->orderService->detailOrderSN($oid);
    if (!$order) {
        return redirect(url('detail-order-sn', ['orderSN' => $oid]));
    }

    // ⭐ 提取from参数
    $from = '';
    if (!empty($order->info)) {
        if (preg_match('/来源[:\s]+([^\s\n]+)/', $order->info, $matches)) {
            $from = strtolower(trim($matches[1]));
        }
    }

    // ⭐ 根据from参数决定跳转URL
    if ($from === 'novel') {
        // 跳转到小说网站
        $redirectUrl = env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000');
        \Log::info('Epusdt returnUrl：根据from参数重定向', [
            'order_sn' => $oid,
            'from' => $from,
            'redirect_url' => $redirectUrl
        ]);
        return redirect()->away($redirectUrl);
    }

    // 默认跳转到订单详情页
    return redirect(url('detail-order-sn', ['orderSN' => $oid]));
}
```

### 修复逻辑
1. **获取订单信息**: 从数据库读取订单详情
2. **提取from参数**: 使用正则表达式从order.info提取from
3. **判断from值**:
   - `from === 'novel'` → 跳转到NOVEL_REDIRECT_URL
   - 其他情况 → 跳转到订单详情页（默认行为）
4. **记录日志**: 记录重定向决策，便于调试

## 修复效果

### 修复前
```
用户访问: /buy/1?from=novel&email=xxx
  ↓
下单支付（from=novel存储到订单）
  ↓
跳转bepusdt支付页面
  ↓
支付成功后: returnUrl → 订单详情页 ❌
  ↓
用户停留在订单详情页，无法返回novel网站
```

### 修复后
```
用户访问: /buy/1?from=novel&email=xxx
  ↓
下单支付（from=novel存储到订单）
  ↓
跳转bepusdt支付页面
  ↓
支付成功后: returnUrl → 检测from=novel → 跳转到novel网站 ✅
  ↓
用户成功返回novel网站
```

## 影响范围

### 已修复
- ✅ EpusdtController - returnUrl方法

### 待修复（同样问题）
其他支付控制器也需要相同修复：
- PaysapiController.php:106-111
- YipayController.php:95-101
- VpayController.php:86-92
- TokenPayController.php:97-103
- StripeController.php:433-440
- PayaplPayController.php:80-90

**注意**: 这些支付方式如果需要支持from重定向，都需要应用相同的修复。

## 测试验证

### 测试步骤
1. 访问商品页: `http://localhost:9595/buy/1?from=novel&email=test@example.com`
2. 下单并选择Epusdt支付方式
3. 完成支付
4. **预期**: 自动跳转回NOVEL_REDIRECT_URL（如 `http://127.0.0.1:3000`）

### 验证命令
```bash
# 检查订单from参数
mysql -u root -p dujiaoka -e "SELECT order_sn, info FROM orders ORDER BY id DESC LIMIT 1\G"

# 检查returnUrl日志
tail -f storage/logs/laravel.log | grep "Epusdt returnUrl"
```

## 技术要点

### 1. redirect()->away() vs redirect()
```php
// redirect()->away() - 不验证URL，用于外部域名
return redirect()->away('http://127.0.0.1:3000');

// redirect() - 验证URL，用于内部域名
return redirect(url('detail-order-sn', ['orderSN' => $oid]));
```

### 2. from参数提取
```php
// 从order.info中提取from
preg_match('/来源[:\s]+([^\s\n]+)/', $order->info, $matches);
$from = strtolower(trim($matches[1]));
```

### 3. 大小写处理
```php
$from = strtolower(trim($matches[1])); // 统一转小写
if ($from === 'novel') { // 小写比较
```

## 下一步行动
1. ✅ EpusdtController已修复
2. 测试验证修复效果
3. 如有需要，修复其他支付控制器的returnUrl方法
