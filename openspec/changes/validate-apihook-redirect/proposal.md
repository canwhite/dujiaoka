# Proposal: Validate ApiHook Payment Callback and Redirect Flow

## Metadata
- **Change ID**: `validate-apihook-redirect`
- **Type**: Validation
- **Status**: In Review
- **Created**: 2026-01-01
- **Author**: System Validation

## Overview
验证独角数卡系统中 ApiHook 支付回调后的两个关键流程：
1. **支付成功 → 调用第三方 API 发送 token**
2. **Token 发送完成 → 页面重定向到小说网站 (127.0.0.1:3000)**

## Context

### 业务场景
用户从小说网站 (127.0.0.1:3000) 跳转到独角数卡系统进行支付，支付成功后：
1. 独角数卡需要调用小说网站的 API，发送 token
2. 完成后需要将用户重定向回小说网站

### 实现方案概述
- 通过订单 `info` 字段传递 `来源: novel` 参数
- ApiHook 队列任务根据来源参数调用不同的 API
- 前端 qrpay 页面通过轮询检测支付状态，成功后根据 `from` 参数重定向

## Validation Findings

### ✅ 流程 1：支付成功 → API 调用发送 Token

**实现路径**：
```
支付回调 → OrderProcessService::completedOrder() (line 385)
→ ApiHook::dispatch($order) (line 432)
→ ApiHook::handle() (line 58)
→ callApiByFrom() (line 83)
→ callNovelApi() (line 123)
→ sendPostRequest() (line 208)
```

**验证结果**：✅ **实现正确**

**详细分析**：
1. **触发时机正确**：ApiHook 在 `OrderProcessService::completedOrder()` 的 DB commit 之后被 dispatch（line 432），确保订单已成功保存
2. **队列异步处理**：ApiHook 实现 `ShouldQueue` 接口，异步执行不阻塞用户请求
3. **from 参数提取**：正确从订单 `info` 字段中提取"来源: novel"（line 66-72）
4. **API 调用逻辑**：
   - 根据不同的 `from` 值路由到不同的 API 方法（line 92-116）
   - `callNovelApi()` 从环境变量读取 API URL（line 125）
   - 正确提取订单中的邮箱字段（line 132-136）
   - 构造完整的 POST 数据：email, order_sn, amount, good_name, timestamp（line 139-145）
5. **错误处理**：
   - 使用 try-catch 捕获异常（line 237-243）
   - 记录详细日志（成功和失败都有日志）
   - 30秒超时保护（line 215）

**潜在问题**：
⚠️ **环境变量未配置**：系统检查发现 `.env` 文件中没有 `NOVEL_API_URL` 配置
- 当前代码会在 `NOVEL_API_URL` 为空时直接 return（line 127-129）
- 不会抛出错误，但会导致 API 调用被跳过

**建议改进**：
```php
// 在 ApiHook::callNovelApi() 中添加更明确的日志
if (empty($apiUrl)) {
    \Log::warning('Novel API URL not configured', [
        'order_sn' => $this->order->order_sn,
        'from' => 'novel'
    ]);
    return;
}
```

### ✅ 流程 2：Token 发送完成 → 页面重定向

**实现路径**：
```
用户在 qrpay 页面 → 每5秒轮询 /check-order-status
→ 支付成功返回 code 200
→ 前端检测 from 参数
→ 根据 redirect_urls 配置跳转
→ 3秒后跳转到 127.0.0.1:3000
```

**验证结果**：✅ **实现正确**

**详细分析**：

#### PayController::render() 方法（line 165-177）
- ✅ 正确拦截 `static_pages/qrpay` 模板渲染
- ✅ 调用 `extractFromFromOrder()` 提取 from 参数（line 169）
- ✅ 定义 redirect_urls 配置数组（line 170-172）
- ✅ 将数据传递给模板

#### 前端 qrpay 模板（unicorn 和 luna 主题）

**Unicorn 主题**（line 48-63）：
- ✅ 轮询成功后获取 `from` 和 `redirectUrls`（line 49-50）
- ✅ 默认跳转到订单详情页（line 53）
- ✅ 如果有 from 参数且在 redirect_urls 中存在，使用对应 URL（line 55-58）
- ✅ 3秒延迟后跳转（line 61-63）

**Luna 主题**（line 95-112）：
- ✅ 同样的逻辑实现
- ✅ 使用 layer.alert 提示用户"支付成功！3秒后自动跳转..."（line 107-112）
- ✅ 用户体验更好，有明确的提示信息

**重定向配置**：
```php
'direct_urls' => [
    'novel' => 'http://127.0.0.1:3000',
]
```
✅ 硬编码的 URL 符合需求

**潜在问题**：
⚠️ **跨域问题**：如果独角数卡使用 HTTPS 而小说网站使用 HTTP，可能会有混合内容警告
⚠️ **硬编码 URL**：`127.0.0.1:3000` 写在代码中，不同环境需要修改

**建议改进**：
1. 将重定向 URL 移到环境变量：
```php
// .env
NOVEL_REDIRECT_URL=http://127.0.0.1:3000

// PayController.php
$data['redirect_urls'] = [
    'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
];
```

2. 考虑添加 URL 参数传递，例如订单号：
```php
redirectUrl = redirectUrls[from] + '?order=' + orderSN;
```

## Risks and Limitations

### 当前风险
1. **API URL 未配置**：`.env` 中缺少 `NOVEL_API_URL`，会导致 API 调用被静默跳过
2. **硬编码 URL**：重定向地址写死在代码中，不便于多环境部署
3. **错误监控不足**：API 调用失败虽然有日志，但缺少主动通知机制

### 已有的安全措施
✅ **幂等性保护**：订单状态检查（line 395-396）防止重复处理
✅ **事务保护**：订单处理使用 DB 事务（line 387, 414）
✅ **队列重试**：ApiHook 配置了 `$tries = 2`，失败会重试一次
✅ **超时控制**：API 请求 30 秒超时
✅ **异常捕获**：所有可能出错的地方都有 try-catch

## Testing Recommendations

### 手动测试场景
1. **正常流程**：
   - 从小说网站下单（info 中包含"来源: novel"）
   - 完成支付
   - 验证 API 被正确调用
   - 验证 3 秒后跳转到 127.0.0.1:3000

2. **异常场景**：
   - API URL 未配置：观察日志，确认有警告信息
   - API 请求超时：模拟 30 秒超时，验证队列重试机制
   - API 返回错误：验证错误日志记录
   - 订单 info 中没有 from 参数：验证默认行为（跳转到订单详情页）

3. **边界条件**：
   - from 参数大小写不一致（当前代码严格匹配 'novel'）
   - 订单 info 字段格式不正确
   - 重复支付回调（幂等性测试）

### 集成测试建议
```php
// tests/Feature/ApiHookTest.php
public function test_novel_api_hook_with_valid_order()
{
    $order = Order::factory()->create([
        'info' => "充值账号: test@example.com\n来源: novel",
        'status' => Order::STATUS_PENDING
    ]);

    // Mock HTTP 请求
    Http::fake([
        env('NOVEL_API_URL') => Http::response(['success' => true], 200),
    ]);

    // 执行队列任务
    $job = new ApiHook($order);
    $job->handle();

    // 验证 API 被调用
    Http::assertSent(function ($request) {
        return $request->url() === env('NOVEL_API_URL') &&
               $request['email'] === 'test@example.com';
    });
}
```

## Related Artifacts

### 修改的文件
- `app/Jobs/ApiHook.php` - API 回调核心逻辑
- `app/Http/Controllers/PayController.php` - from 参数提取和重定向配置
- `resources/views/unicorn/static_pages/qrpay.blade.php` - Unicorn 主题前端轮询
- `resources/views/luna/static_pages/qrpay.blade.php` - Luna 主题前端轮询

### 相关文档
- `ddoc/ApiHook与支付成功回调的关系分析.md`
- `ddoc/多渠道充值与回调完整实现总结.md`

## Conclusion

### 总体评价
✅ **两个流程的实现都是正确的**，代码质量良好，有以下亮点：

1. **架构清晰**：使用队列异步处理，不阻塞主流程
2. **可扩展性好**：通过 switch-case 可以轻松添加新的 from 类型
3. **错误处理完善**：有详细的日志记录和异常捕获
4. **用户体验良好**：前端有明确提示和自动跳转

### 需要注意的问题
⚠️ **必须配置环境变量**：`NOVEL_API_URL` 必须在 `.env` 中配置
⚠️ **硬编码 URL**：建议移到配置文件或环境变量
⚠️ **监控不足**：建议添加 API 调用失败的主动通知（邮件、钉钉、企业微信等）

### 下一步行动
1. 在 `.env` 中配置 `NOVEL_API_URL`
2. 考虑将 `127.0.0.1:3000` 移到环境变量
3. 添加 API 调用失败的监控和告警
4. 编写集成测试确保流程稳定
