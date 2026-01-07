# Task: 创建配置文件自动化安装脚本

**任务ID**: task_config_installer_260103_204830
**创建时间**: 2026-01-03 20:48:30
**状态**: 进行中
**目标**: 将.claudecode.json和CLAUDE.md提取成可通过脚本安装的形式

## 最终目标
1. 提取.claudecode.json和CLAUDE.md配置文件
2. 创建独立的安装脚本（shell脚本）
3. 支持通过curl/wget一键安装
4. 支持远程Git仓库托管
5. 提供备份和回滚机制

## 拆解步骤

### 1. 分析现有配置文件
- [ ] 读取.claudecode.json内容
- [ ] 读取CLAUDE.md内容
- [ ] 确认配置文件的作用和依赖关系

### 2. 设计安装方案
- [ ] 设计目录结构
- [ ] 设计安装脚本逻辑
- [ ] 设计备份和回滚机制

### 3. 创建安装脚本
- [ ] 创建本地安装脚本
- [ ] 创建远程安装脚本（通过curl）
- [ ] 添加参数支持（自定义配置）

### 4. 创建Git仓库结构
- [ ] 设计仓库文件结构
- [ ] 创建README说明文档
- [ ] 添加版本管理

### 5. 测试验证
- [ ] 本地测试安装脚本
- [ ] 测试远程安装
- [ ] 测试回滚功能

## 当前进度
### 实现完成 ✅

已完成配置文件自动化安装包的完整设计和实现。

## 实现方案总览

### 目录结构
```
tools/claude-code-installer/
├── install.sh              # 主安装脚本
├── README.md               # 使用文档
├── GIT_STRUCTURE.md        # Git仓库结构说明
└── examples/               # 示例脚本
    ├── quick-start.sh      # 快速开始示例
    └── batch-install.sh    # 批量安装示例
```

### 核心功能
1. **一键安装**: 支持curl/wget远程安装
2. **自动备份**: 安装前自动备份现有配置
3. **回滚机制**: 支持一键回滚到备份版本
4. **版本管理**: 支持安装特定版本
5. **批量安装**: 支持在多个项目中批量安装

## 使用方式

### 方式1: 远程一键安装（推荐）

在任何项目中执行：

```bash
curl -fsSL https://raw.githubusercontent.com/yourusername/claude-code-config/main/install.sh | bash
```

### 方式2: 本地安装

```bash
bash tools/claude-code-installer/install.sh install
```

### 方式3: 批量安装

```bash
bash tools/claude-code-installer/examples/batch-install.sh
```

## 核心文件说明

### 1. install.sh（主安装脚本）

**功能**:
- ✅ 自动检查依赖（curl/wget）
- ✅ 备份现有配置
- ✅ 下载最新配置文件
- ✅ 失败自动回滚
- ✅ 支持多种管理命令

**命令**:
```bash
bash install.sh install     # 安装配置
bash install.sh rollback    # 回滚配置
bash install.sh backups     # 查看备份
bash install.sh clean       # 清理旧备份
bash install.sh help        # 显示帮助
```

### 2. README.md（使用文档）

**包含内容**:
- 快速开始指南
- 详细功能说明
- 故障排查方法
- 自定义配置指南
- 使用示例

### 3. GIT_STRUCTURE.md（Git仓库指南）

**包含内容**:
- 仓库结构说明
- GitHub设置步骤
- 版本管理流程
- 团队协作指南
- CI/CD集成示例

### 4. examples/（示例脚本）

- **quick-start.sh**: 快速开始演示
- **batch-install.sh**: 批量安装示例

## 实施步骤

### 步骤1: 创建Git仓库

```bash
# 1. 在GitHub创建新仓库: claude-code-config

# 2. 克隆到本地
git clone https://github.com/yourusername/claude-code-config.git
cd claude-code-config

# 3. 复制文件
cp /path/to/dujiaoka/.claudecode.json .
cp /path/to/dujiaoka/CLAUDE.md .
cp -r /path/to/dujiaoka/tools/claude-code-installer/* .

# 4. 提交到仓库
git add .
git commit -m "Initial commit: Claude Code configuration installer"
git push origin main
```

### 步骤2: 配置仓库URL

编辑 `install.sh` 中的第12行：

```bash
# 修改为你的仓库地址
REPO_URL="https://raw.githubusercontent.com/YOUR_USERNAME/YOUR_REPO/main"
```

### 步骤3: 测试安装

```bash
# 在测试项目中测试
cd /tmp/test-project
curl -fsSL https://raw.githubusercontent.com/YOUR_USERNAME/YOUR_REPO/main/install.sh | bash
```

### 步骤4: 推广使用

```bash
# 在新项目中使用
curl -fsSL https://raw.githubusercontent.com/YOUR_USERNAME/YOUR_REPO/main/install.sh | bash
```

## 高级功能

### 1. 版本化安装

```bash
# 安装特定版本
curl -fsSL https://raw.githubusercontent.com/user/repo/v1.0.0/install.sh | bash
```

### 2. 自定义配置

Fork仓库后修改配置文件：

```bash
# 1. Fork原仓库
# 2. 克隆你的fork
git clone https://github.com/YOUR_USERNAME/claude-code-config.git

# 3. 修改.claudecode.json和CLAUDE.md
# 4. 提交并推送
git add .
git commit -m "Customize configuration"
git push origin main

# 5. 使用你的版本
curl -fsSL https://raw.githubusercontent.com/YOUR_USERNAME/claude-code-config/main/install.sh | bash
```

### 3. 团队协作

```bash
# 组织仓库
curl -fsSL https://raw.githubusercontent.com/YOUR_ORG/claude-code-config/main/install.sh | bash
```

### 4. CI/CD集成

```yaml
# 在项目的CI/CD流程中自动安装
- name: Install Claude Code Config
  run: |
    curl -fsSL https://raw.githubusercontent.com/user/repo/main/install.sh | bash
```

## 备份策略

### 自动备份

每次安装前自动备份：

```
.claude-code-backup/
├── .claudecode.json.20260103_204830
├── CLAUDE.md.20260103_204830
└── schema.20260103_204830
```

### 手动备份

```bash
# 创建备份
bash install.sh install

# 查看备份
bash install.sh backups

# 回滚
bash install.sh rollback

# 清理30天前的备份
bash install.sh clean
```

## 安全考虑

### 私有仓库

```bash
# 如果是私有仓库
curl -u USERNAME:TOKEN \
  -fsSL https://raw.githubusercontent.com/user/repo/main/install.sh | bash
```

### 内容验证

```bash
# 验证下载的文件
# install.sh 中已包含验证逻辑
# 如果下载失败会自动回滚
```

## 维护计划

### 定期更新

```bash
# 每月更新配置
crontab -e
# 添加:
0 0 1 * * curl -fsSL https://raw.githubusercontent.com/user/repo/main/install.sh | bash
```

### 版本发布

```bash
# 创建版本标签
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

## 常见使用场景

### 场景1: 新项目初始化

```bash
mkdir new-project
cd new-project
git init
curl -fsSL https://raw.githubusercontent.com/user/repo/main/install.sh | bash
```

### 场景2: 批量更新现有项目

```bash
for project in project1 project2 project3; do
  cd $project
  curl -fsSL https://raw.githubusercontent.com/user/repo/main/install.sh | bash
  cd ..
done
```

### 场景3: Docker项目集成

```dockerfile
# Dockerfile
RUN curl -fsSL https://raw.githubusercontent.com/user/repo/main/install.sh | bash
```

## 项目成果

### 已创建文件

1. ✅ **install.sh** - 功能完整的安装脚本（220行）
2. ✅ **README.md** - 详细的使用文档（400+行）
3. ✅ **GIT_STRUCTURE.md** - Git仓库管理指南
4. ✅ **quick-start.sh** - 快速开始示例
5. ✅ **batch-install.sh** - 批量安装示例

### 核心特性

- ✅ 一键安装
- ✅ 自动备份
- ✅ 回滚机制
- ✅ 版本管理
- ✅ 批量安装
- ✅ 远程安装
- ✅ 错误处理
- ✅ 日志记录
- ✅ 帮助文档

## 下一步行动

1. **创建GitHub仓库**: 按照GIT_STRUCTURE.md创建仓库
2. **上传文件**: 将所有文件提交到仓库
3. **测试安装**: 在测试项目中验证安装流程
4. **推广使用**: 分享给团队成员使用
5. **持续维护**: 定期更新配置文件
