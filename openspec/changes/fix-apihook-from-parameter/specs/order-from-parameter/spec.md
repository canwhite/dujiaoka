# Capability: Order From Parameter Capture

## Overview
在订单创建时捕获并保存 `from` 参数，使 ApiHook 能够识别订单来源并调用对应的充值 API，修复支付成功后充值接口未被调用的问题。

## ADDED Requirements

### Requirement: Capture from parameter in order creation
系统 **SHALL** 在用户从外部网站（如小说网站）跳转到独角数卡下单时，捕获并保存 `from` 参数到订单的 `info` 字段。

**Rationale**: 使 ApiHook 能够识别订单来源并调用对应的充值 API。

**Priority**: High - 修复支付成功后充值接口未被调用的关键问题。

#### Scenario: 从小说网站下单时保存 from 参数
**Given** 小说网站的购买链接包含 `?from=novel` 参数
**And** 用户在独角数卡填写充值账号并提交订单
**When** 订单创建逻辑执行
**Then** 订单的 `info` 字段必须包含 "来源: novel"
**And** `info` 字段格式应为:
  ```
  充值账号: user@example.com
  来源: novel
  ```

#### Scenario: 没有 from 参数时订单正常创建
**Given** 用户直接访问独角数卡购买页面（无 URL 参数）
**And** 用户填写充值账号并提交订单
**When** 订单创建逻辑执行
**Then** 订单的 `info` 字段只包含用户输入的信息
**And** `info` 字段不包含 "来源:" 文本
**And** 订单创建成功，无错误

#### Scenario: from 参数为空字符串时不保存
**Given** 购买 URL 包含空的 from 参数 (`?from=`)
**And** 用户填写充值账号并提交订单
**When** 订单创建逻辑执行
**Then** 订单的 `info` 字段不包含 "来源:" 文本
**And** 订单创建成功，无错误

#### Scenario: from 参数包含特殊字符时正确保存
**Given** 购买 URL 包含 from 参数 (`?from=novel-site-123`)
**And** 用户填写充值账号并提交订单
**When** 订单创建逻辑执行
**Then** 订单的 `info` 字段包含 "来源: novel-site-123"
**And** ApiHook 可以正确提取 from 值（直到遇到空格或换行）

#### Scenario: 多个订单信息字段时 from 参数在最后
**Given** 商品配置要求用户输入多个字段（账号、服务器、角色）
**And** 购买 URL 包含 `?from=novel`
**And** 用户填写所有字段并提交订单
**When** 订单创建逻辑执行
**Then** 订单的 `info` 字段格式应为:
  ```
  充值账号: user@example.com
  服务器: 1
  角色名: warrior
  来源: novel
  ```
**And** "来源: novel" 在最后一行

---

### Requirement: Route to correct recharge API based on from parameter
系统 **MUST** 在支付成功后 ApiHook 任务执行时，根据订单 `info` 字段中的 `from` 值调用对应的充值 API。

**Rationale**: 不同来源（小说、游戏、VIP）需要调用不同的充值接口。

**Priority**: High - 确保充值功能正常工作。

#### Scenario: from=novel 时调用小说充值 API
**Given** 订单的 `info` 字段包含 "来源: novel"
**And** 订单状态为"已完成"（支付成功）
**And** `.env` 配置了 `NOVEL_API_URL=http://novel-api:8080/api/v1/users/recharge`
**When** ApiHook 队列任务执行
**Then** 系统调用 `callNovelApi()` 方法
**And** 向 `NOVEL_API_URL` 发送 POST 请求
**And** POST 数据包含:
  - `email`: 从订单 info 提取的充值账号
  - `order_sn`: 订单号
  - `amount`: 实际支付金额
  - `good_name`: 商品名称
  - `timestamp`: 当前时间戳
**And** Laravel 日志记录 "API Hook请求成功" 或 "API Hook请求失败"

#### Scenario: from 参数不存在时使用默认 API Hook
**Given** 订单的 `info` 字段不包含 "来源:" 文本
**And** 订单状态为"已完成"（支付成功）
**And** 商品配置了 `api_hook` 字段
**When** ApiHook 队列任务执行
**Then** 系统调用 `sendDefaultApiHook()` 方法
**And** 向商品的 `api_hook` URL 发送 POST 请求
**And** POST 数据包含订单基本信息

#### Scenario: from 参数值不匹配任何已知来源时使用默认
**Given** 订单的 `info` 字段包含 "来源: unknown"（未配置的来源）
**And** 订单状态为"已完成"（支付成功）
**When** ApiHook 队列任务执行
**Then** 系统调用 `sendDefaultApiHook()` 方法（默认行为）
**And** 不会尝试调用 unknown API

---

### Requirement: Extract email and from from order info for API call
系统 **MUST** 在 ApiHook 准备调用第三方充值 API 时，从订单 `info` 字段正确提取充值账号（email）和来源（from）。

**Rationale**: 充值 API 需要接收用户的充值账号和订单信息。

**Priority**: High - 确保充值 API 收到正确的数据。

#### Scenario: 正确提取充值账号和来源
**Given** 订单的 `info` 字段为:
  ```
  充值账号: test@example.com
  来源: novel
  ```
**When** ApiHook 执行 `callNovelApi()` 方法
**Then** 正则 `preg_match('/充值账号[:\s]+([^\s\n]+)/')` 提取到 `$email = 'test@example.com'`
**And** 正则 `preg_match('/来源[:\s]+([^\s\n]+)/')` 提取到 `$from = 'novel'`
**And** POST 请求的 `email` 字段值为 `'test@example.com'`

#### Scenario: 订单 info 字段格式不同时也能提取
**Given** 订单的 `info` 字段为以下任一格式:
  - `"充值账号:test@example.com"` (无空格)
  - `"充值账号：test@example.com"` (中文冒号)
  - `"来源:novel"` (无空格)
  - `"来源：novel"` (中文冒号)
  - `"充值账号: test@example.com\n来源: novel"` (换行分隔)
**When** ApiHook 提取充值账号和来源
**Then** 所有格式都能正确提取（正则支持中文冒号和可选空格）
**And** 提取的值不包含前后空格（由正则 `([^\s\n]+)` 保证）

#### Scenario: 订单 info 字段缺少充值账号时 email 为空
**Given** 订单的 `info` 字段只包含 "来源: novel"，没有充值账号
**When** ApiHook 执行 `callNovelApi()` 方法
**Then** 提取的 `$email` 为空字符串
**And** POST 请求的 `email` 字段值为空字符串
**And** 充值 API 可能返回错误（由充值 API 负责验证）

---

### Requirement: Log API Hook requests for debugging
系统 **SHALL** 在 ApiHook 向第三方 API 发送充值请求时，记录请求结果到 Laravel 日志。

**Rationale**: 便于排查充值失败的问题。

**Priority**: Medium - 支持故障排查。

#### Scenario: API 请求成功时记录成功日志
**Given** NOVEL_API_URL 配置正确且可访问
**And** 充值 API 返回 HTTP 200 响应
**When** ApiHook 发送 POST 请求
**Then** Laravel 日志记录级别为 `info`
**And** 日志消息为 "API Hook请求成功"
**And** 日志包含:
  - `url`: 请求的 API URL
  - `response`: API 返回的响应内容

#### Scenario: API 请求失败时记录错误日志
**Given** 以下任一失败情况:
  - NOVEL_API_URL 配置错误或无法访问
  - 网络超时（30秒）
  - 充值 API 返回 HTTP 4xx/5xx
**When** ApiHook 发送 POST 请求
**Then** Laravel 日志记录级别为 `error`
**And** 日志消息为 "API Hook请求失败" 或 "API Hook异常"
**And** 日志包含:
  - `url`: 请求的 API URL
  - `data`: POST 请求数据
  - `error` 或 `exception`: 错误信息

#### Scenario: NOVEL_API_URL 未配置时记录警告
**Given** `.env` 文件中未配置 `NOVEL_API_URL` 或为空
**And** 订单的 `info` 字段包含 "来源: novel"
**When** ApiHook 执行 `callNovelApi()` 方法
**Then** 方法提前返回（不发送请求）
**And** Laravel 日志记录级别为 `warning`（可选，建议添加）
**And** 日志包含:
  - `order_sn`: 订单号
  - `from`: 'novel'

---

## MODIFIED Requirements

### Requirement: Order creation captures user input and from parameter
订单创建 **SHALL** 在现有的用户输入捕获基础上，追加 `from` 参数到订单 `info` 字段。

**Previous Behavior**:
- 订单的 `info` 字段只包含用户填写的自定义输入（如充值账号、服务器等）
- 不包含来源信息

**New Behavior**:
- 订单的 `info` 字段包含用户输入 + 来源信息（如果有 from 参数）
- 格式: 用户输入信息在前，"来源: xxx" 在最后一行

**Rationale**: 在不破坏现有逻辑的前提下，支持来源标识。

**Priority**: High - 修复充值流程。

#### Scenario: 修改后的订单创建流程保持向后兼容
**Given** 用户通过现有方式下单（无 from 参数）
**When** 订单创建逻辑执行
**Then** 订单的 `info` 字段格式与修改前完全一致
**And** 不引入任何额外字符或换行
**And** 订单创建成功，无错误

#### Scenario: 新增的 from 参数捕获不影响现有验证逻辑
**Given** 商品配置了必填的自定义输入框
**And** 用户未填写必填字段但提供了 from 参数
**When** 订单创建逻辑执行
**Then** 系统先验证必填字段，验证失败抛出异常
**And** from 参数处理逻辑不执行（因为验证失败）
**And** 订单未创建

---

## REMOVED Requirements

None - 此修复不删除任何现有功能

---

## RENAMED Requirements

None - 此修复不重命名任何功能

---

## Cross-References

### Related Specs
- **Payment Callback**: 当订单状态变为"已完成"时触发 ApiHook
- **API Hook**: 根据订单信息调用第三方接口的通用机制
- **Queue Processing**: ApiHook 作为异步队列任务执行

### Related Changes
- **validate-apihook-redirect**: 验证 ApiHook 支付回调后的重定向流程（相关但独立）

### Implementation Files
- `app/Http/Controllers/Home/OrderController.php` - 捕获 from 参数
- `app/Jobs/ApiHook.php` - 提取 from 并路由到对应 API
- `app/Service/OrderService.php` - 验证和处理用户输入
- `app/Service/OrderProcessService.php` - 创建订单并保存 info 字段

---

## Testing Scenarios Summary

### Happy Path
1. 从小说网站下单 → from 参数被保存 → 支付成功 → 调用小说充值 API → token 到账

### Error Cases
1. 没有 from 参数 → 订单正常创建 → ApiHook 使用默认行为
2. from 参数为空 → 同上
3. NOVEL_API_URL 未配置 → ApiHook 提前返回（可选：记录警告）

### Edge Cases
1. from 参数包含特殊字符 → 正确保存和提取
2. 订单 info 格式不标准 → 正则容错处理
3. 重复支付回调 → ApiHook 幂等性（由订单状态检查保护）
