# Proposal: Fix ApiHook Third-Party Recharge Interface Not Being Called

## Metadata
- **Change ID**: `fix-apihook-from-parameter`
- **Type**: Bug Fix
- **Status**: Proposed
- **Created**: 2026-01-01
- **Related Issues**: 支付成功后未调用三方充值接口，token未到账

## Overview
修复支付成功后第三方充值接口未被调用的关键问题。问题的根本原因是订单创建时未捕获和保存 `from` 参数，导致 `ApiHook` 无法识别订单来源并调用对应的充值 API。

## Why
当前系统存在一个关键的数据流断裂问题：

1. **用户预期**: 从小说网站支付成功后，token 应该自动到账
2. **实际情况**: 支付成功但 token 未到账，因为充值 API 未被调用
3. **根本原因**: 订单创建时 `from` 参数丢失，导致 ApiHook 无法路由到正确的充值接口

这个问题影响了：
- **用户体验**: 支付成功后需要手动联系客服充值
- **业务流程**: 自动化充值流程不完整
- **系统信任**: 用户可能认为系统有问题

修复这个问题后：
- ✅ 从小说网站下单的用户支付成功后 token 自动到账
- ✅ 完整的自动化充值流程
- ✅ 支持扩展到其他来源（游戏、VIP 等）

## Problem Statement

### Current Issue
用户报告支付成功后，第三方充值接口未被调用，token 未到账。

### Root Cause Analysis
通过代码分析发现完整的数据流断裂：

1. **预期流程**:
   ```
   用户从小说网站下单 (URL: ?from=novel)
   ↓
   订单创建时捕获 from 参数
   ↓
   from 参数存入 order.info 字段 (格式: "来源: novel")
   ↓
   支付成功后 ApiHook 提取 from 参数
   ↓
   根据from调用对应的充值API (callNovelApi)
   ```

2. **实际情况**:
   ```
   用户从小说网站下单 (URL: ?from=novel)
   ↓
   订单创建时 ❌ 未捕获 from 参数
   ↓
   order.info 字段中只有用户输入信息 (如 "充值账号: xxx")
   ↓
   支付成功后 ApiHook 尝试提取 from 参数
   ↓
   提取失败，from = '' (空字符串)
   ↓
   调用默认API钩子 (sendDefaultApiHook)
   ↓
   ❌ 未调用三方充值接口，token未到账
   ```

### Technical Details

#### 问题位置 1: OrderController 未处理 from 参数
**File**: `app/Http/Controllers/Home/OrderController.php:57-92`

```php
public function createOrder(Request $request)
{
    // ... 验证逻辑 ...

    // ⚠️ 缺少: 没有从 $request 中提取 'from' 参数
    $otherIpt = $this->orderService->validatorChargeInput($goods, $request);
    $this->orderProcessService->setOtherIpt($otherIpt); // ← 只设置了用户输入

    // ... 创建订单 ...
}
```

#### 问题位置 2: validatorChargeInput 只处理用户输入
**File**: `app/Service/OrderService.php:185-200`

```php
public function validatorChargeInput(Goods $goods, Request $request): string
{
    $otherIpt = '';
    if ($goods->type == Goods::MANUAL_PROCESSING && !empty($goods->other_ipu_cnf)) {
        $formatIpt = format_charge_input($goods->other_ipu_cnf);
        foreach ($formatIpt as $item) {
            // ... 验证并拼接用户输入 ...
            $otherIpt .= $item['desc'].':'.$request->input($item['field']) . PHP_EOL;
        }
    }
    return $otherIpt; // ⚠️ 只返回用户输入，不包含 from
}
```

#### 问题位置 3: ApiHook 无法提取 from
**File**: `app/Jobs/ApiHook.php:66-72`

```php
// ⭐ 从订单info中提取from参数
$from = '';
if (!empty($this->order->info)) {
    if (preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
        $from = $matches[1];
    }
}
// ⚠️ 因为 order.info 中没有 "来源: novel"，所以 $from 始终为空
```

### Impact
- **用户影响**: 支付成功后未收到 token，影响充值体验
- **业务影响**: 充值流程不完整，可能导致用户投诉
- **系统影响**: ApiHook 的 from 参数路由机制形同虚设

## Solution

### Fix Strategy
在订单创建流程中捕获 `from` 参数并存入 `order.info` 字段。

### Implementation Plan

#### 修改点 1: OrderController - 捕获 from 参数
**File**: `app/Http/Controllers/Home/OrderController.php`

在 `createOrder()` 方法中，处理 `otherIpt` 后追加 `from` 参数:

```php
public function createOrder(Request $request)
{
    // ... 现有验证逻辑 ...

    $otherIpt = $this->orderService->validatorChargeInput($goods, $request);

    // ⭐ 新增: 追加 from 参数到 otherIpt
    if ($request->has('from') && !empty($request->input('from'))) {
        $from = $request->input('from');
        $otherIpt .= "\n来源: " . $from;
    }

    $this->orderProcessService->setOtherIpt($otherIpt);
    // ... 后续逻辑 ...
}
```

#### 修改点 2: (可选) OrderService - 提供辅助方法
**File**: `app/Service/OrderService.php`

添加辅助方法用于格式化 from 参数:

```php
/**
 * 追加来源信息到订单详情
 *
 * @param string $otherIpt 订单详情
 * @param string $from 来源标识
 * @return string
 */
public function appendFromToOrderInfo(string $otherIpt, string $from): string
{
    if (empty($from)) {
        return $otherIpt;
    }
    return $otherIpt . "\n来源: " . trim($from);
}
```

### Benefits
1. **最小化修改**: 只修改订单创建流程，不改变现有 API 逻辑
2. **向后兼容**: 没有 `from` 参数时行为不变
3. **易于测试**: 可以通过 URL 参数直接验证
4. **支持多来源**: 可以扩展支持 `from=game`, `from=vip` 等

## Out of Scope
- 不修改 ApiHook 的 from 参数提取逻辑 (已验证工作正常)
- 不修改支付回调流程 (已验证工作正常)
- 不添加新的 from 类型 (只修复现有的 novel 类型)
- 不修改前端重定向逻辑 (已验证工作正常)

## Risks and Limitations

### Risks
1. **from 参数来源不可信**: 如果用户手动添加 `?from=novel`，可能会绕过来源验证
   - **缓解措施**: 可以在后端添加白名单验证，只允许预定义的 from 值

2. **订单 info 字段格式**: 如果已有订单 info 格式不统一，可能影响正则提取
   - **缓解措施**: 当前的正则 `/来源[:\s]+([^\s\n]+)/` 已经考虑了中文冒号和空格

3. **历史订单兼容**: 支付前已有的订单没有 from 参数
   - **缓解措施**: 这是预期行为，只有新订单才会包含 from 参数

### Limitations
1. **硬编码来源格式**: 依赖固定的 "来源: xxx" 格式
2. **手动参数传递**: 需要外部系统主动传递 from 参数
3. **无来源验证**: 当前实现不验证 from 参数的有效性

## Testing Strategy

### Manual Testing
1. **正常流程测试**:
   ```
   1. 访问购买页面并添加 ?from=novel 参数
   2. 填写充值账号并下单
   3. 检查数据库 order.info 字段是否包含 "来源: novel"
   4. 完成支付
   5. 检查 Laravel 日志是否显示 "API Hook请求成功"
   6. 验证 token 是否到账
   ```

2. **边界条件测试**:
   - 没有 from 参数 (应该不影响下单)
   - from 参数为空字符串 (?from=)
   - from 参数包含特殊字符
   - from 参数大小写 (?from=Novel)

3. **向后兼容测试**:
   - 创建没有 from 参数的订单
   - 验证支付流程正常工作

### Automated Testing
创建集成测试验证完整流程:

```php
// tests/Feature/OrderFromParameterTest.php
public function test_order_created_with_from_parameter()
{
    $response = $this->post('/order/create', [
        'gid' => 1,
        'email' => 'test@example.com',
        'payway' => 1,
        'by_amount' => 1,
        'from' => 'novel',
        // ... 其他参数 ...
    ]);

    $order = Order::where('email', 'test@example.com')->first();
    $this->assertStringContainsString('来源: novel', $order->info);
}

public function test_apihook_calls_novel_api_when_from_is_novel()
{
    $order = Order::factory()->create([
        'info' => "充值账号: test@example.com\n来源: novel",
        'status' => Order::STATUS_PENDING
    ]);

    Http::fake([
        env('NOVEL_API_URL') => Http::response(['success' => true], 200),
    ]);

    $job = new ApiHook($order);
    $job->handle();

    Http::assertSent(function ($request) {
        return $request->url() === env('NOVEL_API_URL') &&
               $request['email'] === 'test@example.com';
    });
}
```

## Related Artifacts
- Related changes: `validate-apihook-redirect`
- Related specs: 订单创建, API Hook
- Documentation: `ddoc/多渠道充值与回调完整实现总结.md`

## Timeline
- **Proposal Review**: 待审批
- **Implementation**: 预计 1-2 小时
- **Testing**: 预计 1 小时
- **Deployment**: 低风险，可随时部署
