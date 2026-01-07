# Task: 作为supervisor为novel-api添加详细日志来诊断充值接口问题

**任务ID**: task_novelapi_logging_260105_181920
**创建时间**: 2026-01-05
**状态**: 进行中
**目标**: 作为supervisor为novel-api项目添加详细的日志记录，诊断充值接口返回400错误的具体原因

## 最终目标
1. 探索novel-api项目结构，找到充值接口实现代码
2. 在充值接口中添加详细的请求参数日志
3. 添加签名验证、时间戳验证的详细日志
4. 添加错误响应的详细日志
5. 重启novel-api容器，查看新增日志输出
6. 根据日志分析问题并提供解决方案

## 拆解步骤
### 1. 探索novel-api项目结构
- [x] 查看novel-api项目目录结构
- [x] 找到充值接口（/api/v1/users/recharge）的实现代码
- [x] 确认当前日志记录方式

### 2. 分析现有日志记录
- [x] 查看现有的日志输出格式
- [x] 确定需要添加日志的关键位置
- [x] 分析400错误返回的具体位置

### 3. 添加详细日志
- [x] 在请求解析处添加请求参数详细日志（server.go:579-584）
- [x] 在签名验证处添加签名计算和验证日志（server.go:629-638, user_service.go:551-585）
- [x] 在时间戳验证处添加时间戳检查日志（server.go:592-619, user_service.go:588-609）
- [x] 在错误返回处添加详细的错误原因日志（多个错误返回处）

### 4. 重启并验证
- [x] 重新构建novel-api镜像（已更新代码和环境变量）
- [x] 重启novel-api容器（20:37:46启动）
- [ ] 发起新的充值请求测试
- [ ] 查看新增的详细日志输出

### 5. 分析问题并提供解决方案
- [ ] 根据详细日志分析400错误具体原因
- [ ] 提出具体的修复方案
- [ ] 验证修复效果

## 当前进度
### 分析结果
1. **环境变量修复成功**：
   - novel-api容器已正确设置RECHARGE_SECRET_KEY=HELLOWORxiaobai123@_
   - 解决了签名密钥不匹配问题

2. **新请求结果**（20:42:00）：
   - ✅ ApiHook任务成功触发并处理
   - ❌ novel-api返回400错误
   - **错误信息**: `json: cannot unmarshal number into Go struct field .good_id of type string`
   - **问题分析**: good_id字段期望string类型，但收到了number类型

3. **代码更新问题**：
   - ❌ 未看到添加的详细调试日志（🕐、🔐、📥、📋等符号）
   - **可能原因**: 容器使用了缓存的旧镜像，未包含最新代码修改
   - **当前日志**: 只有原始的"❌ 请求参数错误:"日志，没有详细参数记录

### 当前执行状态（2026-01-05 21:00）
- **novel-api镜像存在**: `novel-resource-management-novel-api:latest` (18分钟前构建)
- **novel-api容器状态**: 未运行（需要启动容器）
- **检测到的镜像**:
  - `novel-resource-management-novel-api:latest` (90.1MB)
  - `pro_novel-app:latest` (3GB)
- **下一步**: 重新构建镜像（确保代码更新），启动容器，测试充值请求

### 问题诊断和解决方案

#### 1. good_id字段类型错误
**问题**: `json: cannot unmarshal number into Go struct field .good_id of type string`
**原因**: novel-api的`rechargeUserTokens`函数定义good_id为string类型，但dujiaoka发送的是数字
**定位**: `api/server.go:564` - `GoodID string \`json:"good_id"\``
**解决方案**:
- 选项A: 修改novel-api，将good_id类型改为int
- 选项B: 修改dujiaoka，将good_id转为字符串发送
- **推荐**: 选项B，保持API接口类型一致性

#### 2. 代码未更新问题
**现象**: 未看到详细的调试日志（🕐、🔐、📥、📋等符号）
**原因**: Docker构建缓存导致未包含最新代码
**解决方案**:
- 清除Docker构建缓存：`docker-compose build --no-cache novel-api`
- 强制重新构建并重启容器

#### 3. 预期详细日志内容
如果代码更新成功，应该看到以下日志：
1. **请求参数详细日志**: `📥 收到充值回调:`, `📋 完整请求参数:`
2. **时间戳验证日志**: `🕐 时间戳验证开始:`, `📊 时间戳分析:`, `✅ 时间戳验证通过:`
3. **签名验证日志**: `🔐 开始签名验证:`, `📋 验证参数:`, `📊 签名计算:`, `📊 签名对比:`
4. **内部函数日志**: `[ValidateHMACSignature]`, `[ComputeHMACSignature]`, `[ValidateTimestamp]`

## 已添加的详细日志位置
### api/server.go 中的新增日志:
- 第579-584行: 完整请求参数日志
- 第592-619行: 时间戳验证详细日志（包含时间差计算和阈值对比）
- 第629-638行: 签名验证开始日志和参数记录
- 第640-644行: 签名验证失败的详细错误信息

### service/user_service.go 中的新增日志:
- 第508-545行: `ComputeHMACSignature`函数内部详细计算日志
- 第551-585行: `ValidateHMACSignature`函数内部详细验证日志
- 第588-609行: `ValidateTimestamp`函数内部详细时间戳验证日志

## 下一步行动
1. 重新构建novel-api镜像
2. 重启novel-api容器
3. 发起新的充值请求测试
4. 查看新增的详细日志输出，分析400错误具体原因
5. 根据日志分析提供解决方案