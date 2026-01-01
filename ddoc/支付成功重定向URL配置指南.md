# 支付成功重定向 URL 配置指南

## 概述

当用户从第三方网站（如小说网站）跳转到独角数卡进行支付时，支付成功后需要将用户重定向回原网站。本指南说明如何配置和管理支付成功后的重定向 URL。

## 功能特性

1. **环境变量配置**：通过 `.env` 文件灵活配置重定向 URL，无需修改代码
2. **智能跳转**：根据订单来源（`from` 参数）自动判断跳转目标
3. **手动返回**：支持用户点击确认按钮立即返回
4. **自动跳转**：支付成功后 3 秒自动跳转
5. **向后兼容**：未配置时使用默认 URL，不影响现有功能

## 配置步骤

### 1. 配置重定向 URL

在项目根目录的 `.env` 文件中添加或修改以下配置：

```bash
# 支付成功后重定向URL配置
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
```

**配置说明**：
- `NOVEL_REDIRECT_URL`：小说网站的重定向地址
- 协议必须一致（HTTP/HTTPS）
- 建议使用完整的 URL（包含协议、域名、端口）

### 2. 设置订单来源参数

在创建订单时，需要在订单的 `info` 字段中包含来源信息：

```php
$info = "充值账号: user@example.com\n来源: novel";
```

**格式要求**：
- 使用 `来源: xxx` 格式
- 换行符 `\n` 分隔不同信息
- `from` 参数值（如 `novel`）需要与 PayController 中的配置键对应

### 3. PayController 配置

`app/Http/Controllers/PayController.php` 的 `render()` 方法会自动处理重定向配置：

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

## 工作流程

### 完整流程

```
用户从小说网站访问
    ↓
跳转到独角数卡下单
    ↓
订单 info 包含 "来源: novel"
    ↓
用户完成支付
    ↓
PayController 提取 from 参数
    ↓
前端读取 redirect_urls 配置
    ↓
支付成功页面显示：
  - 手动按钮：点击确定立即返回
  - 自动跳转：3 秒后自动返回
    ↓
跳转到 NOVEL_REDIRECT_URL 配置的地址
```

### 前端实现

#### Unicorn 主题

使用原生 `confirm()` 对话框：

```javascript
if (confirm(message)) {
    // 用户点击确定，立即跳转
    window.location.href = redirectUrl;
} else {
    // 3秒后自动跳转
    setTimeout(function() {
        window.location.href = redirectUrl;
    }, 3000);
}
```

#### Luna 主题

使用 Layer.js 弹窗：

```javascript
layer.alert(message, {
    icon: 1,
    closeBtn: 0
}, function () {
    // 点击确定后跳转
    window.location.href = redirectUrl;
});
```

## 扩展到其他来源

如果需要支持多个来源（如游戏、APP 等），按以下步骤操作：

### 1. 添加环境变量

在 `.env` 中添加新的配置：

```bash
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
GAME_REDIRECT_URL=http://game.example.com/callback
APP_REDIRECT_URL=https://app.example.com/return
```

### 2. 更新 PayController

在 `redirect_urls` 数组中添加新键值对：

```php
$data['redirect_urls'] = [
    'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
    'game'  => env('GAME_REDIRECT_URL', 'http://game.example.com'),
    'app'   => env('APP_REDIRECT_URL', 'https://app.example.com'),
];
```

### 3. 设置订单来源

创建订单时使用对应的来源标识：

```php
// 小说网站
$info = "来源: novel";

// 游戏网站
$info = "来源: game";

// APP
$info = "来源: app";
```

## 常见问题

### Q1: 配置后没有生效？

**可能原因**：
1. `.env` 文件未保存或格式错误
2. Laravel 配置缓存未清除
3. 订单 `info` 字段中没有来源信息

**解决方法**：
```bash
# 清除配置缓存
php artisan config:clear

# 检查 .env 文件格式
cat .env | grep NOVEL_REDIRECT_URL

# 检查订单 info 字段
# 查看数据库或日志确认格式正确
```

### Q2: 跨域或混合内容警告？

**问题描述**：独角数卡使用 HTTPS，但重定向 URL 是 HTTP（或反之）。

**解决方法**：
- 确保协议一致（都用 HTTPS 或都用 HTTP）
- 或使用相对协议（但仍有安全风险）

### Q3: 如何测试重定向功能？

**测试步骤**：
1. 配置 `.env` 中的 `NOVEL_REDIRECT_URL`
2. 创建包含"来源: novel"的订单
3. 完成支付（可以使用测试金额）
4. 观察支付成功页面的提示和跳转行为

### Q4: 不同的主题如何配置？

**已支持的主题**：
- ✅ Unicorn 主题（使用原生 confirm）
- ✅ Luna 主题（使用 Layer.js）

**其他主题**：
参考 Unicorn/Luna 的实现，在支付成功的回调中添加类似的逻辑。

## 安全建议

1. **URL 验证**：确保重定向 URL 是可信的域名
2. **协议一致**：避免混合内容（HTTP/HTTPS 混用）
3. **参数过滤**：来源参数应该从可信渠道获取（订单 info 字段）
4. **日志记录**：建议记录重定向行为，便于排查问题

## 相关文档

- [ApiHook与支付成功回调的关系分析.md](./ApiHook与支付成功回调的关系分析.md)
- [多渠道充值与回调完整实现总结.md](./多渠道充值与回调完整实现总结.md)
- [外部跳转携带邮箱自动填充-改造方案.md](./外部跳转携带邮箱自动填充-改造方案.md)

## 更新日志

- **2026-01-01**：初始版本，支持环境变量配置重定向 URL
- 支持 Unicorn 和 Luna 主题的手动返回按钮
- 添加智能 from 参数判断
