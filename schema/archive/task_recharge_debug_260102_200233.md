# Task: 调试三方token充值失败问题

**任务ID**: task_recharge_debug_260102_200233
**创建时间**: 2026-01-02 20:02:33
**状态**: 进行中
**目标**: 通过Docker日志分析为什么修复后调用三方接口自动支付token仍然失败

## 最终目标
找到三方token充值失败的最新原因，并解决问题

## 拆解步骤

### 1. 收集Docker日志
- [ ] 查看所有容器状态
- [ ] 读取dujiaoka容器日志
- [ ] 读取bepusdt容器日志
- [ ] 读取novel-api容器日志
- [ ] 重点关注最近的支付相关日志

### 2. 分析日志
- [ ] 搜索API Hook相关日志
- [ ] 搜索支付回调相关日志
- [ ] 检查notify_url是否正确
- [ ] 检查ApiHook是否被触发
- [ ] 检查充值API是否被调用

### 3. 问题定位
- [ ] 确定问题发生在哪个环节
- [ ] 分析是代码问题还是配置问题
- [ ] 检查之前的修复是否生效

### 4. 提出解决方案
- [ ] 给出具体修复方案
- [ ] 实施修复
- [ ] 验证修复效果

## 当前进度
### 正在进行: 实施修复
已找到问题根因并修复代码

## 问题分析

### 关键发现

从bepusdt日志中发现notify_url错误：
```
`notify_url`="http://dujiaokapay/epusdt/notify_url"
```

**问题**：URL拼接缺少斜杠
- 错误：`http://dujiaokapay/epusdt/notify_url` ❌
- 正确：`http://dujiaoka/pay/epusdt/notify_url` ✅

### 根本原因

代码（第33行）：
```php
'notify_url' => 'http://dujiaoka' . $this->payGateway->pay_handleroute . '/notify_url',
```

拼接逻辑：
- `$this->payGateway->pay_handleroute` = `pay/epusdt`（无前导斜杠）
- 结果：`http://dujiaoka` + `pay/epusdt` + `/notify_url`
- = `http://dujiaokapay/epusdt/notify_url` ❌

### 修复方案

修改第33行，添加斜杠并trim：
```php
'notify_url' => 'http://dujiaoka/' . trim($this->payGateway->pay_handleroute, '/') . '/notify_url',
```

**修复效果**：
- 结果：`http://dujiaoka/` + `pay/epusdt` + `/notify_url`
- = `http://dujiaoka/pay/epusdt/notify_url` ✅

## 修复记录
- [x] 定位问题：notify_url拼接错误
- [x] 修改代码：添加斜杠和trim
- [x] 重启容器：dujiaoka_app已重启
- [ ] 测试验证：等待用户测试

## 下一步行动
1. 用户创建新订单并支付
2. 检查bepusdt日志确认notify_url正确
3. 检查novel-api日志确认收到充值请求
4. 验证用户token充值成功
