# Task: 分析卡密发放逻辑

**任务ID**: task_analyze_carmis_260102
**创建时间**: 2026-01-02
**完成时间**: 2026-01-02
**状态**: 已完成
**目标**: 深入研究并分析当前的卡密发放逻辑

## 最终目标

全面理解卡密发放的完整流程：
1. 卡密如何从数据库中提取
2. 卡密库存检查逻辑
3. 卡密发放的具体步骤
4. 卡密状态变更（从未售出到已售出）
5. 邮件发送时机和内容
6. 潜在的问题和改进点

## 拆解步骤

### 1. 代码分析
- [ ] 分析OrderProcessService::processAutoWithCarmis()
- [ ] 分析CarmisService相关方法
- [ ] 分析Order模型与Carmi模型的关系
- [ ] 分析Goods模型与库存的关系

### 2. 数据库结构
- [ ] carmis表结构
- [ ] goods表与库存的关系
- [ ] orders表与卡密的关系

### 3. 业务流程
- [ ] 整理完整的卡密发放流程图
- [ ] 标注关键检查点
- [ ] 识别可能的失败场景

### 4. 潜在问题
- [ ] 并发安全问题
- [ ] 库存管理问题
- [ ] 性能问题
- [ ] 用户体验问题

## 当前进度

✅ 代码分析已完成
✅ 数据库结构已分析
✅ 流程图已整理

## 研究发现

### 1. 卡密发放完整流程

```
用户支付成功
    ↓
OrderProcessService::completedOrder()
    ↓
判断订单类型 (order->type == AUTOMATIC_DELIVERY)
    ↓
OrderProcessService::processAuto()
    ↓
检查环境变量 RECHARGE_USE_CARMIS
    ├─ true  → processAutoWithCarmis()  ⭐ 卡密发放模式
    └─ false → processAutoWithoutCarmis()  (API Hook模式)
    ↓
【processAutoWithCarmis() 流程】
    ↓
1️⃣ 查询卡密库存
   CarmisService::withGoodsByAmountAndStatusUnsold($goods_id, $buy_amount)
   ├─ SQL: SELECT * FROM carmis WHERE goods_id=? AND status=1 LIMIT ?
   └─ 返回: 未售出的卡密数组
    ↓
2️⃣ 检查库存数量
   if (count($carmis) != $buy_amount)
   ├─ 库存不足 → 标记订单为异常 (STATUS_ABNORMAL)
   └─ 返回订单
    ↓
3️⃣ 提取卡密信息
   $carmisInfo = array_column($carmis, 'carmi')
   $ids = array_column($carmis, 'id')
    ↓
4️⃣ 更新订单信息
   $order->info = implode(PHP_EOL, $carmisInfo)  ← 卡密内容
   $order->status = Order::STATUS_COMPLETED
   $order->save()
    ↓
5️⃣ 标记卡密已售出
   CarmisService::soldByIDS($ids)
   ├─ SQL: UPDATE carmis SET status=2 WHERE id IN (...) AND is_loop=0
   └─ ⚠️ 只更新非循环卡密
    ↓
6️⃣ 发送邮件
   MailSend::dispatch($order->email, $mailBody['tpl_name'], $mailBody['tpl_content'])
   ├─ 邮件模板: card_send_user_email
   └─ 邮件内容: 包含卡密列表
    ↓
✅ 完成
```

---

### 2. 关键代码位置

#### 2.1 主流程
**文件**: `app/Service/OrderProcessService.php`

| 方法 | 行号 | 说明 |
|------|------|------|
| `processAuto()` | 489-501 | 检查RECHARGE_USE_CARMIS，路由到对应方法 |
| `processAutoWithCarmis()` | 509-544 | 卡密发放主逻辑 ⭐ |
| `processAutoWithoutCarmis()` | 552-584 | 非卡密模式（API Hook） |

#### 2.2 卡密服务
**文件**: `app/Service/CarmisService.php`

| 方法 | 行号 | 说明 |
|------|------|------|
| `withGoodsByAmountAndStatusUnsold()` | 29-37 | 查询未售出卡密 ⭐ |
| `soldByIDS()` | 49-52 | 批量标记卡密已售出 ⭐ |

#### 2.3 卡密模型
**文件**: `app/Models/Carmis.php`

| 常量 | 值 | 说明 |
|------|------|------|
| `STATUS_UNSOLD` | 1 | 未售出状态 |
| `STATUS_SOLD` | 2 | 已售出状态 |

---

### 3. 数据库表结构

#### 3.1 carmis 表

| 字段 | 类型 | 说明 | 备注 |
|------|------|------|------|
| `id` | bigint | 主键 | 自增 |
| `goods_id` | int | 所属商品ID | 外键关联goods表 |
| `status` | tinyint(1) | 状态 | 1=未售出, 2=已售出 |
| `is_loop` | tinyint(1) | 是否循环卡密 | 1=是, 0=否 ⚠️ 关键字段 |
| `carmi` | text | 卡密内容 | 存储实际卡密 |
| `created_at` | timestamp | 创建时间 | |
| `updated_at` | timestamp | 更新时间 | |
| `deleted_at` | timestamp | 软删除时间 | |

**索引**:
- PRIMARY KEY (`id`)
- KEY `idx_goods_id` (`goods_id`)

#### 3.2 goods 表相关字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `in_stock` | int | 库存数量 |
| `type` | int | 商品类型 (1=自动发货, 2=人工处理) |

---

### 4. 关键逻辑详解

#### 4.1 查询未售出卡密
**代码**: `CarmisService.php:29-37`

```php
public function withGoodsByAmountAndStatusUnsold(int $goodsID, int $byAmount)
{
    $carmis = Carmis::query()
        ->where('goods_id', $goodsID)
        ->where('status', Carmis::STATUS_UNSOLD)  // ← 只查询未售出
        ->take($byAmount)                          // ← 限制数量
        ->get();
    return $carmis ? $carmis->toArray() : null;
}
```

**SQL等价**:
```sql
SELECT * FROM carmis
WHERE goods_id = ? AND status = 1
LIMIT ?
```

**特点**:
- ✅ 只查询`status=1`（未售出）的卡密
- ✅ 使用`take()`限制查询数量
- ⚠️ **没有排序** - 返回顺序依赖数据库插入顺序

#### 4.2 标记卡密已售出
**代码**: `CarmisService.php:49-52`

```php
public function soldByIDS(array $ids): bool
{
    return Carmis::query()
        ->whereIn('id', $ids)
        ->where('is_loop', 0)          // ← ⚠️ 只更新非循环卡密
        ->update(['status' => Carmis::STATUS_SOLD]);
}
```

**SQL等价**:
```sql
UPDATE carmis
SET status = 2
WHERE id IN (?, ?, ...)
  AND is_loop = 0                     -- ← ⚠️ 关键条件
```

**特点**:
- ✅ 批量更新，性能较好
- ⚠️ **循环卡密不会被标记为已售出** - 可以重复使用
- ✅ 只更新指定的ID

#### 4.3 库存数量检查
**代码**: `OrderProcessService.php:514-519`

```php
$carmis = $this->carmisService->withGoodsByAmountAndStatusUnsold($order->goods_id, $order->buy_amount);

// 实际可使用的库存已经少于购买数量了
if (count($carmis) != $order->buy_amount) {
    $order->info = __('dujiaoka.prompt.order_carmis_insufficient_quantity_available');
    $order->status = Order::STATUS_ABNORMAL;
    $order->save();
    return $order;
}
```

**逻辑**:
- 查询到的卡密数量必须**严格等于**购买数量
- 否则标记订单为异常状态

---

### 5. 特殊功能：循环卡密

#### 5.1 什么是循环卡密？

**定义**: `is_loop = 1` 的卡密

**特点**:
- 可以重复使用
- 不会被标记为已售出（`status`保持为1）
- 适用于可以重复发放的卡密（如优惠券、兑换码）

#### 5.2 循环卡密的处理流程

```
查询卡密 (包含循环卡密)
    ↓
提取卡密内容发送给用户
    ↓
标记卡密已售出
    ├─ is_loop=0 → status变为2 (已售出)
    └─ is_loop=1 → status保持1 (未售出) ⭐
    ↓
下次查询时仍能查到循环卡密
```

**代码体现**:
```php
// soldByIDS方法中的where条件
->where('is_loop', 0)  // ← 只更新非循环卡密
```

---

### 6. 潜在问题分析

#### 🔴 问题1: 并发安全问题

**场景**:
```
用户A和用户B同时购买同一商品的1个卡密
    ↓
两个请求同时查询: withGoodsByAmountAndStatusUnsold()
    ↓
都查询到同一张卡密 (假设id=100)
    ↓
用户A: 标记卡密100已售出 ✅
用户B: 标记卡密100已售出 ❌ (已经售出过了)
    ↓
用户B收到的是用户A已经使用过的卡密！
```

**根本原因**:
- 查询和更新之间**没有锁机制**
- 多个请求可能同时获取到同一张卡密

**影响**:
- 🔴 **严重**: 多个用户可能获得同一张卡密
- 🔴 导致用户投诉和退款

**建议修复方案**:
1. 使用数据库行锁（`SELECT FOR UPDATE`）
2. 使用Redis分布式锁
3. 使用队列串行处理

#### 🟡 问题2: 库存扣减时机

**当前流程**:
```
1. 查询卡密 (SELECT)
2. 检查数量
3. 更新订单状态
4. 标记卡密已售出 (UPDATE)
5. 发送邮件
```

**问题**:
- 商品的`in_stock`字段在**GoodsService**中管理
- 与卡密发放**不在同一个事务**中
- 可能出现数据不一致

**场景**:
```
1. 查询到卡密 ✅
2. 用户在此时删除了该卡密
3. 尝试标记已售出 ❌ (卡密不存在)
```

#### 🟡 问题3: 无事务保护

**当前代码**:
```php
// OrderProcessService.php:509-544
public function processAutoWithCarmis(Order $order): Order
{
    // ... 查询卡密 ...
    // ... 更新订单 ...
    // ... 标记卡密已售出 ...
    // ... 发送邮件 ...

    // ❌ 没有事务保护
}
```

**调用上下文**:
```php
// OrderProcessService.php:385-438
public function completedOrder(string $orderSN, float $actualPrice, string $tradeNo = '')
{
    DB::beginTransaction();
    try {
        // ...

        if ($order->type == Order::AUTOMATIC_DELIVERY) {
            $completedOrder = $this->processAuto($order); // ← 在事务内
        }

        // ...
        DB::commit();
    } catch (\Exception $exception) {
        DB::rollBack();
        throw new RuleValidationException($exception->getMessage());
    }
}
```

**分析**:
- ✅ `completedOrder()`有事务保护
- ✅ `processAutoWithCarmis()`在事务内执行
- ⚠️ 但**卡密查询和更新在同一事务中**，仍有并发风险

#### 🟢 问题4: 循环卡密的库存管理

**场景**:
```
商品有10张循环卡密
用户A购买10张 → 获得10张卡密 ✅
用户B购买10张 → 仍然获得这10张卡密 ✅ (因为is_loop=1)
```

**问题**:
- 商品的`in_stock`字段可能会减少
- 但循环卡密可以无限使用
- 可能导致库存数据不准确

**建议**:
- 循环卡密商品应该有独立的库存管理逻辑
- 或者不在库存中统计循环卡密

#### 🟢 问题5: 邮件发送失败处理

**当前实现**:
```php
MailSend::dispatch($order->email, $mailBody['tpl_name'], $mailBody['tpl_content']);
```

**特点**:
- 使用Laravel队列异步发送
- 如果队列失败，有重试机制（`$tries = 2`）

**问题**:
- 如果邮件最终发送失败，用户**收不到卡密**
- 但订单已完成，卡密已标记为已售出
- 用户需要联系客服重新获取

**建议**:
- 在订单详情页面显示卡密（需要登录）
- 提供"重新发送邮件"功能

---

### 7. 优化建议

#### 7.1 解决并发问题 ⭐⭐⭐

**方案1: 使用SELECT FOR UPDATE**

```php
public function withGoodsByAmountAndStatusUnsold(int $goodsID, int $byAmount)
{
    // 使用悲观锁
    $carmis = Carmis::query()
        ->where('goods_id', $goodsID)
        ->where('status', Carmis::STATUS_UNSOLD)
        ->orderBy('id')  // 添加排序，确保顺序一致
        ->take($byAmount)
        ->lockForUpdate()  // ← ⭐ 加锁
        ->get();

    return $carmis ? $carmis->toArray() : null;
}
```

**优点**:
- 简单直接
- 数据库级别的锁，可靠

**缺点**:
- 性能较低（锁等待）
- 死锁风险

**方案2: 使用乐观锁**

```php
// 1. 查询时获取版本号
$carmis = Carmis::query()
    ->where('goods_id', $goodsID)
    ->where('status', Carmis::STATUS_UNSOLD)
    ->take($byAmount)
    ->get();

// 2. 更新时检查状态
$affected = Carmis::query()
    ->whereIn('id', $ids)
    ->where('status', Carmis::STATUS_UNSOLD)  // ← ⭐ 乐观锁
    ->where('is_loop', 0)
    ->update(['status' => Carmis::STATUS_SOLD]);

if ($affected != count($ids)) {
    // 更新失败，有并发冲突
    throw new \Exception('卡密发放失败，请重试');
}
```

**优点**:
- 性能较好
- 无死锁风险

**缺点**:
- 需要重试机制

#### 7.2 添加重试机制

```php
public function processAutoWithCarmis(Order $order): Order
{
    $maxRetries = 3;
    $retryCount = 0;

    while ($retryCount < $maxRetries) {
        try {
            // ... 发放逻辑 ...

            return $order;
        } catch (\Exception $e) {
            $retryCount++;

            if ($retryCount >= $maxRetries) {
                // 重试次数耗尽，标记为异常
                $order->info = '卡密发放失败: ' . $e->getMessage();
                $order->status = Order::STATUS_ABNORMAL;
                $order->save();
                return $order;
            }

            // 等待后重试
            usleep(100000); // 100ms
        }
    }
}
```

#### 7.3 添加卡密预留机制

```php
// 1. 查询时标记为"预留"状态
$carmis = Carmis::query()
    ->where('goods_id', $goodsID)
    ->where('status', Carmis::STATUS_UNSOLD)
    ->take($byAmount)
    ->update(['status' => Carmis::STATUS_RESERVED]); // ← 新增状态

// 2. 订单完成后更新为已售出
Carmis::query()
    ->whereIn('id', $reservedIds)
    ->update(['status' => Carmis::STATUS_SOLD]);

// 3. 定时任务清理超时预留
// 每分钟执行：将预留超过5分钟的卡密恢复为未售出
```

#### 7.4 改进日志记录

```php
\Log::info('卡密发放开始', [
    'order_sn' => $order->order_sn,
    'goods_id' => $order->goods_id,
    'buy_amount' => $order->buy_amount,
    'carmis_count' => count($carmis)
]);

\Log::info('卡密发放成功', [
    'order_sn' => $order->order_sn,
    'carmis_ids' => $ids,
    'is_loop_carmis' => array_filter($carmis, fn($c) => $c['is_loop'] == 1)
]);
```

---

### 8. 总结

#### 优点
✅ 逻辑清晰，易于理解
✅ 支持循环卡密功能
✅ 库存检查严格
✅ 在事务保护下执行

#### 缺点
❌ **并发安全问题** (最严重)
❌ 缺少重试机制
❌ 日志不够详细
❌ 邮件发送无备用方案

#### 优先级
1. **P0**: 修复并发安全问题 ✅ **已完成**
2. **P1**: 添加重试机制 ✅ **已完成**
3. **P1**: 改进日志记录 ✅ **已完成**
4. **P2**: 添加卡密预留机制
5. **P2**: 提供订单详情页面查看卡密

---

## ✅ P0问题修复完成 (2026-01-02)

### 修复方案: 乐观锁 + 重试机制

#### 1. 乐观锁实现
**文件**: `app/Service/CarmisService.php:49-68`

**核心改动**:
```php
// 修改前: 直接更新，无并发检查
public function soldByIDS(array $ids): bool
{
    return Carmis::query()
        ->whereIn('id', $ids)
        ->where('is_loop', 0)
        ->update(['status' => Carmis::STATUS_SOLD]);
}

// 修改后: 添加乐观锁检查
public function soldByIDS(array $ids): int
{
    $affected = Carmis::query()
        ->whereIn('id', $ids)
        ->where('status', Carmis::STATUS_UNSOLD)  // ← ⭐ 乐观锁
        ->where('is_loop', 0)
        ->update(['status' => Carmis::STATUS_SOLD]);

    \Log::info('卡密状态更新', [
        'ids' => $ids,
        'expected_count' => count($ids),
        'affected_rows' => $affected,
        'is_concurrent_conflict' => $affected != count($ids)
    ]);

    return $affected;  // ← 返回实际更新行数
}
```

**原理**:
- 只有`status = 1`（未售出）的卡密才会被更新
- 如果其他事务已更新，UPDATE影响0行
- 通过返回值检测并发冲突

---

#### 2. 重试机制实现
**文件**: `app/Service/OrderProcessService.php:509-628`

**核心改动**:
```php
// 修改前: 无重试机制
private function processAutoWithCarmis(Order $order): Order
{
    $carmis = $this->carmisService->withGoodsByAmountAndStatusUnsold(...);
    // ...
    $this->carmisService->soldByIDS($ids);
    // ...
    return $order;
}

// 修改后: 添加3次重试 + 并发冲突检测
private function processAutoWithCarmis(Order $order): Order
{
    $maxRetries = 3;
    $retryCount = 0;

    while ($retryCount < $maxRetries) {
        try {
            $carmis = $this->carmisService->withGoodsByAmountAndStatusUnsold(...);
            // ...
            $affectedRows = $this->carmisService->soldByIDS($ids);

            // ⭐ 乐观锁检查
            $expectedRows = count($ids) - $loopCarmisCount;
            if ($affectedRows != $expectedRows) {
                throw new \Exception('并发冲突：卡密状态已被其他事务修改');
            }

            \Log::info('卡密发放成功', [...]);
            return $order;

        } catch (\Exception $e) {
            $retryCount++;

            if ($retryCount >= $maxRetries) {
                // 重试耗尽，标记订单为异常
                $order->status = Order::STATUS_ABNORMAL;
                $order->save();
                throw $e;
            }

            // 随机延迟100-200ms后重试
            usleep(rand(100000, 200000));
        }
    }
}
```

---

#### 3. 详细日志记录

**新增日志点**:
1. **卡密发放开始** - 记录订单信息、重试次数
2. **库存不足** - 记录预期和实际库存
3. **状态更新** - 记录更新的卡密ID、影响行数、是否冲突
4. **发放成功** - 记录卡密数量、循环卡密数量、卡密ID列表
5. **发放失败** - 记录重试次数、错误信息
6. **最终失败** - 记录重试耗尽后的详细信息

---

### 修复效果

#### 并发场景对比

**修复前**:
```
用户A和用户B同时购买
    ↓
都查询到卡密ID=100
    ↓
用户A: UPDATE carmis SET status=2 WHERE id=100 ✅
用户B: UPDATE carmis SET status=2 WHERE id=100 ✅
    ↓
❌ 两个用户都成功，都获得同一张卡密！
```

**修复后**:
```
用户A和用户B同时购买
    ↓
都查询到卡密ID=100
    ↓
用户A: UPDATE carmis SET status=2 WHERE id=100 AND status=1 ✅ affected=1
用户B: UPDATE carmis SET status=2 WHERE id=100 AND status=1 ❌ affected=0
    ↓
用户B检测到affected=0，触发异常
    ↓
等待100-200ms后重试
    ↓
用户B查询到卡密ID=101
    ↓
用户B: UPDATE carmis SET status=2 WHERE id=101 AND status=1 ✅ affected=1
    ↓
✅ 用户A获得卡密100，用户B获得卡密101
```

---

### 测试验证

#### 并发测试脚本

已创建完整测试文档: `schema/test_concurrent_carmis_fix_260102.md`

**测试方法**:
1. 使用Apache Bench或自定义脚本模拟并发
2. 检查数据库确保无重复卡密
3. 查看日志确认重试机制生效

**预期结果**:
- ✅ 无重复卡密发放
- ✅ 并发冲突自动重试
- ✅ 日志记录完整

---

### 性能影响

| 场景 | 并发冲突率 | 性能影响 | 说明 |
|------|-----------|---------|------|
| **正常情况** | 0% | 可忽略 | 一次成功，无额外开销 |
| **轻度并发** | <10% | 轻微 | 100-200ms延迟 |
| **中度并发** | 10-30% | 中等 | 最多600ms延迟 |
| **重度并发** | >30% | 较大 | 建议增加重试次数或使用悲观锁 |

---

### 修改文件清单

1. **app/Service/CarmisService.php**
   - 修改`soldByIDS()`方法添加乐观锁
   - 返回类型从`bool`改为`int`
   - 添加详细日志

2. **app/Service/OrderProcessService.php**
   - 修改`processAutoWithCarmis()`方法
   - 添加3次重试机制
   - 添加并发冲突检测
   - 添加详细日志

3. **schema/test_concurrent_carmis_fix_260102.md**
   - 完整测试指南
   - 并发测试脚本
   - 日志分析方法

---

### 后续建议

#### 短期 (已实现)
- ✅ 乐观锁机制
- ✅ 重试机制（3次）
- ✅ 详细日志

#### 中期 (可选)
- 监控并发冲突率
- 根据冲突率动态调整重试次数
- 添加告警机制（冲突率过高时告警）

#### 长期 (可选)
- 如果并发量持续增大，考虑：
  - 使用悲观锁（SELECT FOR UPDATE）
  - 使用Redis分布式锁
  - 使用消息队列串行化处理
