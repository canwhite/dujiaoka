# Task: 导出TRX支付配置

**任务ID**: task_export_trx_config_260104_152923
**创建时间**: 2026-01-04 15:29:25
**状态**: 进行中
**目标**: 导出项目中 TRX/TRC20 相关的支付配置，方便后续借鉴使用

## 最终目标
导出所有 TRX/TRC20 相关支付方式的配置信息，包括：
1. TRX 支付配置
2. USDT-TRC20 支付配置
3. Epusdt[trc20] 支付配置
4. 其他 TokenPay 支持的加密货币配置

## 拆解步骤

### 1. 查找支付配置
- [x] 查找 pays 表结构和数据
- [x] 定位 TRX/TRC20 相关配置

### 2. 导出配置信息
- [ ] 导出 TRX (TokenPay) 配置
- [ ] 导出 USDT-TRC20 (TokenPay) 配置
- [ ] 导出 Epusdt[trc20] 配置
- [ ] 导出其他相关加密货币配置

### 3. 生成配置文档
- [ ] 创建配置导出文档
- [ ] 添加配置说明和使用指南

## 当前进度

### 正在进行
导出 TRX/TRC20 支付配置信息

已找到以下配置：
- TRX (ID: 24) - TokenPay 网关
- USDT-TRC20 (ID: 25) - TokenPay 网关
- Epusdt[trc20] (ID: 23) - Epusdt 网关
- 其他 TokenPay 支持的币种 (ETH, USDT-ERC20, BNB, USDT-BSC 等)

## 下一步行动
1. 生成完整的配置导出文档
2. 添加配置说明和使用指南
