# Task: 修复支付token自动充值问题

**任务ID**: task_fix_payment_token_260102
**创建时间**: 2026-01-02
**状态**: 进行中
**目标**: 基于 task_investigate_payment_token_260102.md 的调查，修复三方接口自动支付token失败问题

## 最终目标
修复 Docker 网络导致的 bepusdt 通知失败问题，使三方接口自动充值正常工作

## 问题总结（来自调查报告）

**根本原因**: 在 Docker 网络中，Laravel 使用 `url()` 函数生成的 notify_url 包含 `localhost`，这在 bepusdt 容器内指向 bepusdt 自己，而不是 dujiaoka_app 容器

**问题表现**:
- bepusdt 尝试通知 `http://localhost:9595/pay/epusdt/notify_url`
- 但连接的是 bepusdt 容器内部，不是 dujiaoka_app 容器
- 导致 notify_state=0（通知失败，3次重试）
- ApiHook 任务未被触发
- 用户 token 未充值

## 修复过程

### 步骤1: 备份文件
✅ 已完成 - 备份 .env 文件到 `.env.backup_20260102_HHMMSS`

### 步骤2: 代码修复
✅ 已完成 - 修改 `app/Http/Controllers/Pay/EpusdtController.php`

**修改内容**:
```php
// 第 32-33 行（修改前）
'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),

// 第 32-34 行（修改后）
// ⭐ Docker内部网络：bepusdt通过服务名访问dujiaoka
'notify_url' => 'http://dujiaoka' . $this->payGateway->pay_handleroute . '/notify_url',
```

**原理**:
- `url()` 函数基于 APP_URL 生成完整 URL
- APP_URL 在 docker-compose.yml 中设置为 `http://127.0.0.1:9595`
- 在 Docker 内部，`127.0.0.1` 指向容器自己
- 改为 `http://dujiaoka`（Docker 服务名），be pusdt 可以通过内部网络访问

### 步骤3: 重启服务
✅ 已完成 - 重启 dujiaoka_app 容器
```bash
docker-compose restart dujiaoka
```

容器状态: `Up About a minute (healthy)`

### 步骤4: 测试验证
⏳ 等待用户测试

**测试步骤**:
1. 创建订单并支付（携带 `from=novel` 参数）
2. 检查 bepusdt 日志：`docker logs bepusdt | grep PCNU98GIVLJRVJOV`
   - 预期：`notify_state=1`（通知成功）
3. 检查 novel-api 日志：`docker logs novel-api | grep recharge`
   - 预期：看到 POST `/api/v1/users/recharge` 请求
4. 验证用户 token 充值成功

## 拆解步骤

### 1. 修复 APP_URL 配置
- [x] 备份当前 .env 文件
- [x] 修改 EpusdtController 使用 Docker 内部地址
- [x] 确保配置生效

### 2. 重启服务
- [x] 重启 dujiaoka 容器
- [x] 验证服务正常运行

### 3. 测试验证
- [ ] 创建测试订单
- [ ] 检查 bepusdt 日志确认通知成功
- [ ] 检查 novel-api 日志确认收到充值请求
- [ ] 验证用户 token 充值成功

### 4. 更新文档
- [x] 记录修复过程
- [ ] 更新 production.md
- [ ] 归档任务文档

## 当前进度

### 正在进行: 等待用户测试
代码修复已完成，等待用户进行支付测试验证

## 下一步行动
1. 用户进行支付测试
2. 收集测试结果和日志
3. 根据测试结果调整（如果需要）
