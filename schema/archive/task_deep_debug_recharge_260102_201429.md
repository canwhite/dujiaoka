# Task: 深度调试token充值失败问题

**任务ID**: task_deep_debug_recharge_260102_201429
**创建时间**: 2026-01-02 20:14:29
**状态**: 进行中
**目标**: 深度分析Docker日志和代码逻辑，找到并修复token充值失败的根本原因

## 最终目标
彻底解决三方平台token充值失败问题，确保支付后能成功调用novel-api充值接口

## 拆解步骤

### 1. 重新收集Docker日志
- [ ] 获取dujiaoka_app最新日志（重点关注queue和ApiHook）
- [ ] 获取bepusdt最新日志（确认notify_url和notify_state）
- [ ] 获取novel-api最新日志（确认是否收到充值请求）
- [ ] 记录最近一次支付的订单号

### 2. 分析支付回调流程
- [ ] 确认bepusdt是否成功通知dujiaoka
- [ ] 确认EpusdtController::notifyUrl()是否被调用
- [ ] 确认OrderProcessService::completedOrder()是否执行
- [ ] 确认ApiHook::dispatch()是否被触发

### 3. 分析ApiHook队列执行
- [ ] 检查Laravel队列是否正常运行
- [ ] 检查ApiHook任务是否被消费
- [ ] 分析ApiHook.php中的充值逻辑
- [ ] 检查充值账号提取逻辑
- [ ] 检查HTTP请求发送逻辑

### 4. 分析novel-api接收情况
- [ ] 确认novel-api是否收到HTTP请求
- [ ] 如果收到，检查请求参数是否正确
- [ ] 如果没收到，确认网络连通性
- [ ] 检查novel-api的响应

### 5. 定位问题并提出解决方案
- [ ] 确定问题发生在哪个环节
- [ ] 分析是代码问题、配置问题还是网络问题
- [ ] 实施修复
- [ ] 验证修复效果

## 当前进度
### 正在进行: 等待用户测试
已完成代码修复和容器重启，等待用户测试支付流程

## 问题分析结果

### 关键发现

通过Laravel日志文件分析发现：

```
[2026-01-02 20:13:08] production.INFO: 商品未配置API Hook，跳过
{"order_sn":"HPFTAIQGOA6VPFAL","goods_id":1}
```

**根本原因**：ApiHook代码逻辑错误

### 代码逻辑分析

**错误逻辑（修复前）**：
```php
// 第68行：先检查api_hook
if(empty($goodInfo->api_hook)){
    return;  // ❌ 直接跳过
}

// 即使from=novel，也不会执行到这里
$from = extractFrom();
$this->callApiByFrom($from, $goodInfo);
```

**问题**：
- ApiHook任务被触发了
- 但因为商品没有配置api_hook字段
- 导致直接return，novel充值逻辑永远不会执行

### 修复方案

**正确逻辑（修复后）**：
```php
// 1. 先提取from参数
$from = extractFrom();

// 2. 根据from参数决定执行路径
if ($from === 'novel') {
    $this->callNovelApi($goodInfo);  // ✅ 不检查api_hook
} elseif (empty($from)) {
    // 检查api_hook配置
    if (empty($goodInfo->api_hook)) {
        return;
    }
    $this->sendDefaultApiHook($goodInfo);
}
```

**修复要点**：
1. ✅ 先提取from参数，再决定执行路径
2. ✅ from=novel时，直接调用novel-api，不检查api_hook
3. ✅ from为空时，才检查api_hook配置

## 修复记录
- [x] 分析Laravel日志，找到根本原因
- [x] 修复ApiHook.php逻辑错误
- [x] 重新build dujiaoka镜像
- [x] 重启dujiaoka_app容器
- [ ] 等待用户测试

## 下一步行动
1. 用户创建新订单并支付（携带from=novel参数）
2. 检查Laravel日志确认callNovelApi被调用
3. 检查novel-api日志确认收到充值请求
4. 验证用户token充值成功
