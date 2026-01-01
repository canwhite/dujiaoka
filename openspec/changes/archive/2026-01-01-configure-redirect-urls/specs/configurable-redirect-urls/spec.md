# Capability: Configurable Redirect URLs

## Overview
支持通过环境变量配置支付成功后的重定向 URL，使不同环境可以灵活配置跳转地址，无需修改代码。

## ADDED Requirements

### Requirement: Environment-based redirect URL configuration
系统 SHALL 支持通过 `.env` 文件配置重定向 URL，而不是硬编码在代码中。

#### Scenario: Configure novel redirect URL in .env
**Given** 系统管理员在 `.env` 文件中配置了 `NOVEL_REDIRECT_URL=https://example.com`
**When** 用户从小说网站（来源: novel）下单并完成支付
**Then** 支付成功页面应在 3 秒后自动跳转到 `https://example.com`
**And** 手动点击「返回目标商户」按钮也应跳转到 `https://example.com`

#### Scenario: Use default URL when environment variable not set
**Given** 系统管理员未在 `.env` 文件中配置 `NOVEL_REDIRECT_URL`
**When** 用户从小说网站（来源: novel）下单并完成支付
**Then** 系统应使用默认 URL `http://127.0.0.1:3000` 进行跳转
**And** 不应抛出错误或警告

#### Scenario: Configure redirect URL for different environments
**Given** 开发环境配置 `NOVEL_REDIRECT_URL=http://localhost:3000`
**And** 生产环境配置 `NOVEL_REDIRECT_URL=https://novel.example.com`
**When** 在对应环境中完成支付
**Then** 应跳转到各自环境配置的 URL
**And** 不需要修改任何代码

---

### Requirement: Smart return button based on from parameter
手动返回按钮 SHALL 与自动跳转使用相同的逻辑，根据 `from` 参数智能决定跳转目标。

#### Scenario: Return button with valid from parameter
**Given** 用户的订单 info 字段包含"来源: novel"
**And** 系统配置了 `NOVEL_REDIRECT_URL=https://example.com`
**When** 用户在支付成功页面手动点击「返回目标商户」按钮
**Then** 应跳转到 `https://example.com`
**And** 不应跳转到默认的订单详情页

#### Scenario: Return button without from parameter
**Given** 用户的订单 info 字段不包含来源信息
**When** 用户在支付成功页面手动点击「返回目标商户」按钮
**Then** 应跳转到默认的订单详情页 (`/detail-order-sn/{orderSN}`)
**And** 不应尝试读取 redirect_urls 配置

#### Scenario: Return button with unrecognized from parameter
**Given** 用户的订单 info 字段包含"来源: unknown"
**And** redirect_urls 配置中没有 'unknown' 键
**When** 用户在支付成功页面手动点击「返回目标商户」按钮
**Then** 应跳转到默认的订单详情页
**And** 不应抛出 JavaScript 错误

---

### Requirement: Documentation and configuration guidance
系统 SHALL 提供清晰的配置说明，帮助管理员正确配置重定向 URL。

#### Scenario: .env.example contains redirect URL configuration
**Given** 开发者查看 `.env.example` 文件
**When** 查找重定向 URL 相关配置
**Then** 应找到 `NOVEL_REDIRECT_URL` 配置项
**And** 应有清晰的注释说明其用途和默认值

#### Scenario: Documentation explains from parameter mechanism
**Given** 开发者阅读相关文档
**When** 需要了解如何添加新的来源（如 game, app）
**Then** 文档应说明：
  - 如何在订单 info 字段中添加来源信息
  - 如何在 PayController 中添加新的 redirect_url 配置
  - 如何在 .env 中添加对应的 URL 配置

---

## MODIFIED Requirements

### Requirement: PayController render method (existing)
原有的 `render()` 方法 SHALL 被修改，支持从环境变量读取重定向 URL。

#### Scenario: Render method reads environment variable
**Given** `PayController` 的 `render()` 方法被调用
**And** 模板名称是 `static_pages/qrpay`
**When** 构建 `$data['redirect_urls']` 数组
**Then** 'novel' 键的值应使用 `env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000')`
**And** 应保持默认值为 `http://127.0.0.1:3000`

---

## REMOVED Requirements
None - this is an additive change, no existing requirements are removed.

---

## Cross-References

### Related Capabilities
- **validate-apihook-redirect** - 依赖该变更中实现的 from 参数提取机制
- **api-hook-callback** - API 回调成功后触发页面跳转

### Affected Components
- `app/Http/Controllers/PayController.php`
- `.env.example`
- `resources/views/unicorn/static_pages/qrpay.blade.php`
- `resources/views/luna/static_pages/qrpay.blade.php`

---

## Notes for Implementation

### Configuration Format
```bash
# .env
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
```

### Code Pattern
```php
// PayController.php
$data['redirect_urls'] = [
    'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
];
```

### Frontend Pattern (JavaScript)
```javascript
const from = '{{ $from ?? '' }}';
const redirectUrls = @json($redirect_urls ?? []);
let redirectUrl = defaultUrl;

if (from && redirectUrls[from]) {
    redirectUrl = redirectUrls[from];
}

// Apply to both auto-redirect and manual button
```

---

## Testing Strategy

### Unit Testing
- Test `env()` function with and without environment variable set
- Test from parameter extraction logic

### Integration Testing
- Test full payment flow with configured URL
- Test fallback behavior when URL not configured
- Test both unicorn and luna themes

### Manual Testing Checklist
- [ ] Configure URL in .env and verify auto-redirect
- [ ] Configure URL in .env and verify manual button
- [ ] Remove URL from .env and verify default behavior
- [ ] Test without from parameter
- [ ] Test with unrecognized from parameter
- [ ] Test both themes (unicorn, luna)
