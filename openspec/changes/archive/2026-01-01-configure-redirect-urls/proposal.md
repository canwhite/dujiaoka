# Proposal: Configure Redirect URLs via Environment

## Metadata
- **Change ID**: `configure-redirect-urls`
- **Type**: Enhancement
- **Status**: Proposed
- **Created**: 2026-01-01
- **Author': Zack

## Overview
将支付成功后的重定向 URL 从硬编码改为通过环境变量配置，同时增强「返回目标商户」按钮功能，使其也根据 `from` 参数进行智能跳转。

## Motivation

### Current Problem
目前重定向 URL 硬编码在 `PayController.php:171` 中：
```php
$data['redirect_urls'] = [
    'novel' => 'http://127.0.0.1:3000',
];
```

**存在的问题**：
1. **多环境部署困难**：开发、测试、生产环境的 URL 不同，需要修改代码
2. **配置不灵活**：每次修改 URL 需要改代码并重新部署
3. **按钮行为不一致**：自动跳转已经支持 from 参数判断，但手动返回按钮还未实现同样的逻辑

### Desired Solution
1. **配置化重定向 URL**：通过 `.env` 文件配置 `NOVEL_REDIRECT_URL`
2. **统一跳转逻辑**：自动跳转和手动按钮都使用相同的 from 参数判断逻辑
3. **保持向后兼容**：使用 `env()` 函数提供默认值，未配置时使用默认 URL

## Scope

### In Scope
1. 将 `PayController.php` 中硬编码的 `novel` 重定向 URL 改为从环境变量读取
2. 在 `.env.example` 中添加 `NOVEL_REDIRECT_URL` 配置说明
3. 增强 unicorn 和 luna 主题的 qrpay 页面，让「返回目标商户」按钮也根据 from 参数跳转
4. 更新相关文档说明

### Out of Scope
1. 不涉及多个来源（game、app 等）的通用配置设计（按用户需求，只配置 novel）
2. 不修改后端 API 或支付回调逻辑
3. 不涉及其他主题（hyper）的修改（如有需要可后续添加）

## Affected Components

### Backend
- `app/Http/Controllers/PayController.php` - 修改 `render()` 方法中的 redirect_urls 配置
- `.env.example` - 添加 `NOVEL_REDIRECT_URL` 配置说明

### Frontend
- `resources/views/unicorn/static_pages/qrpay.blade.php` - 增强手动返回按钮的跳转逻辑
- `resources/views/luna/static_pages/qrpay.blade.php` - 增强手动返回按钮的跳转逻辑

## Dependencies

### Related Changes
- 依赖 `validate-apihook-redirect` 中已实现的 from 参数提取和传递机制

### Technical Dependencies
- Laravel `env()` 函数用于读取环境变量
- 现有的订单 info 字段中"来源: xxx"格式

## Risks and Mitigations

### Risk 1: 环境变量未配置导致功能异常
**Mitigation**:
- 使用 `env()` 函数提供默认值：`env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000')`
- 在 `.env.example` 中明确说明配置项

### Risk 2: 多个主题需要同步修改
**Mitigation**:
- 本次只修改 unicorn 和 luna 主题（当前使用的主要主题）
- 在文档中说明如何扩展到其他主题

### Risk 3: 跨域或混合内容问题
**Mitigation**:
- 在文档中提醒用户确保协议一致（HTTP/HTTPS）
- 建议使用相对协议（//）或完整 URL

## Success Criteria

### Functional Requirements
1. ✅ `.env` 中配置 `NOVEL_REDIRECT_URL` 后，支付成功页面自动跳转到配置的 URL，这个已配置
2. ✅ 未配置时使用默认值 `http://127.0.0.1:3000`
3. ✅ 手动点击「返回商户平台」按钮时，也根据 from 参数跳转到对应 URL
4. ✅ 没有 from 参数时，按钮和自动跳转都回退到默认的订单详情页

### Non-functional Requirements
1. ✅ 配置修改不需要修改代码
2. ✅ 向后兼容，不影响现有功能
3. ✅ 性能无明显影响

## Implementation Preview

### Proposed Code Changes

#### PayController.php
```php
protected function render(string $tpl, $data = [], string $pageTitle = '')
{
    if ($tpl === 'static_pages/qrpay') {
        $data['from'] = $this->extractFromFromOrder();
        $data['redirect_urls'] = [
            'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
        ];
    }

    return parent::render($tpl, $data, $pageTitle);
}
```

#### qrpay.blade.php (Unicorn/Luna)
- 自动跳转逻辑（已实现，无需修改）
- 手动返回按钮逻辑（新增）：根据 from 参数动态设置按钮的 `href` 或点击事件

## Alternatives Considered

### Alternative 1: 使用配置文件而非环境变量
**Rejected**:
- 配置文件需要提交到代码仓库，不同环境仍然需要修改
- 环境变量更适合环境相关的配置

### Alternative 2: 支持多个来源的通用配置
**Rejected**:
- 用户明确表示当前只需要 novel 配置
- 过度设计，增加复杂度

### Alternative 3: 存储到数据库配置表
**Rejected**:
- 重定向 URL 是环境相关的配置，不适合存储在数据库
- 环境变量更简单直接

## Open Questions
None - requirements are clear.

## Timeline
- **Estimated Implementation**: Small change, can be completed in one session
- **Testing**: Manual testing required for both unicorn and luna themes