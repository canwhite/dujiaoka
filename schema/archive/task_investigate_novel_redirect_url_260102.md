# Task: 调查 NOVEL_REDIRECT_URL 配置正确性

**任务ID**: task_novel_url_260102
**创建时间**: 2026-01-02 19:51:38
**状态**: 进行中
**目标**: 确认 NOVEL_REDIRECT_URL 的正确配置，确保 Docker 容器能正确回调主机端口 3000

## 最终目标
验证 `NOVEL_REDIRECT_URL=http://host.docker.internal:3000` 是否写得对，如果不正确则给出正确的配置方案

## 拆解步骤

### 1. 代码搜索与理解
- [ ] 搜索 NOVEL_REDIRECT_URL 在代码中的所有使用位置
- [ ] 分析这个 URL 的使用场景（何时、何处、如何调用）
- [ ] 理解调用者是容器内还是容器外

### 2. Docker 网络分析
- [ ] 确认项目的 Docker 部署架构
- [ ] 理解 host.docker.internal 的含义和适用场景
- [ ] 理解 Docker 容器访问主机服务的正确方式

### 3. 对比分析
- [ ] 对比 production.md 中的配置（127.0.0.1:3000）
- [ ] 分析在 Docker 环境中哪个配置是正确的
- [ ] 给出明确的配置建议

### 4. 结论与建议
- [ ] 总结调查结果
- [ ] 给出正确的配置值
- [ ] 说明为什么这样配置

## 当前进度
### 已完成
- ✅ 搜索代码中 NOVEL_REDIRECT_URL 的所有使用位置
- ✅ 分析使用场景（浏览器端跳转）
- ✅ 理解 Docker 网络架构
- ✅ 得出结论并给出配置建议

## 下一步行动
完成任务，更新 .env 文件配置

---

## 📊 调查结果

### 1. 代码使用位置分析

**主要使用文件**：
- `app/Http/Controllers/PayController.php:171` - 后端配置
- `resources/views/luna/static_pages/qrpay.blade.php:97-116` - 前端跳转逻辑

**使用流程**：
```php
// 后端 (PayController.php:171)
$data['redirect_urls'] = [
    'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
];
```

```javascript
// 前端 (qrpay.blade.php:116)
window.location.href = redirectUrl;  // 浏览器端跳转
```

### 2. 关键发现

**⚠️ 重要：这是浏览器端跳转，不是服务器端！**

- 跳转是通过 `window.location.href` 在**用户的浏览器**中执行的
- 用户浏览器运行在**主机**上，不在 Docker 容器内
- 浏览器无法解析 Docker 特殊域名 `host.docker.internal`

### 3. Docker 部署架构

```yaml
# docker-compose.yml
services:
  dujiaoka:
    ports:
      - "9595:80"  # 主机访问：http://127.0.0.1:9595
```

**网络拓扑**：
```
用户浏览器 (主机)
    ↓ 访问
http://127.0.0.1:9595 (dujiaoka)
    ↓ 支付成功
window.location.href 跳转
    ↓ 应该跳转
http://127.0.0.1:3000 (小说网站)
```

---

## ✅ 结论和建议

### ❌ 当前配置是错误的

```bash
# 错误配置 ❌
NOVEL_REDIRECT_URL=http://host.docker.internal:3000
```

**错误原因**：
1. `host.docker.internal` 是 Docker 容器内访问主机的特殊 DNS
2. 但这个 URL 是在**用户浏览器**中使用的，不是容器内
3. 用户浏览器无法解析 `host.docker.internal`，会导致跳转失败

### ✅ 正确配置

```bash
# 正确配置 ✅
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
```

**配置说明**：
- `127.0.0.1:3000` 是主机视角的地址
- 用户浏览器可以正确访问
- 与 production.md 中的推荐配置一致

### 📝 其他可选配置

**场景1: 小说网站在主机本地开发**
```bash
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
```

**场景2: 小说网站有域名（生产环境）**
```bash
NOVEL_REDIRECT_URL=https://novel.example.com
```

**场景3: 小说网站在其他端口**
```bash
NOVEL_REDIRECT_URL=http://127.0.0.1:8080
```

---

## 🔧 修复步骤

1. 编辑 `.env` 文件：
```bash
vim .env
# 修改第 50 行
NOVEL_REDIRECT_URL=http://127.0.0.1:3000
```

2. 重启 dujiaoka 容器：
```bash
docker-compose restart dujiaoka
```

3. 测试验证：
   - 访问 dujiaoka 支付页面
   - 支付成功后确认能正确跳转到 `http://127.0.0.1:3000`

---

## 📚 参考文档

- `ddoc/支付成功重定向URL配置指南.md` - 详细的配置说明
- `openspec/changes/archive/2026-01-01-configure-redirect-urls/` - 功能实现文档
- `production.md:220` - 生产环境推荐配置
