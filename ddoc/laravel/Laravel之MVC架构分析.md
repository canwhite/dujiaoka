# 独角数卡 (Dujiaoka) MVC 架构完整流程分析

> 这是一个基于 **Laravel 6.x** 框架的独角数卡项目，本文档详细讲解 MVC 是如何串联的。

---

## 📋 完整流程示例：用户购买商品页面

当用户访问 `buy/123` (购买ID为123的商品) 时，整个MVC的协作流程如下：

```
用户请求 → 路由 → 中间件 → 控制器 → 服务层 → 模型 → 数据库
                                   ↓
                              视图渲染 → 返回HTML
```

---

## 1️⃣ 路由层 (Route)

**文件位置**: `routes/common/web.php`

```php
// routes/common/web.php 第 36 行
Route::get('buy/{id}', 'HomeController@buy');
```

**工作原理**：
- 当用户访问 `http://your-site.com/buy/123` 时
- `{id}` 是路由参数，会捕获 `123`
- `'HomeController@buy'` 指向 `HomeController` 的 `buy` 方法
- 路由配置文件入口：`routes/web.php`

---

## 2️⃣ 控制器层 (Controller)

**文件位置**: `app/Http/Controllers/Home/HomeController.php`

```php
// app/Http/Controllers/Home/HomeController.php 第 45 行
public function buy(int $id)
{
    try {
        // ① 通过服务层获取商品数据
        $goods = $this->goodsService->detail($id);

        // ② 验证商品状态
        $this->goodsService->validatorGoodsStatus($goods);

        // ③ 格式化商品数据
        $formatGoods = $this->goodsService->format($goods);

        // ④ 获取支付方式
        $client = Pay::PAY_CLIENT_PC;
        if (app('Jenssegers\Agent')->isMobile()) {
            $client = Pay::PAY_CLIENT_MOBILE;
        }
        $formatGoods->payways = $this->payService->pays($client);

        // ⑤ 渲染视图并返回
        return $this->render('static_pages/buy', $formatGoods, $formatGoods->gd_name);

    } catch (RuleValidationException $e) {
        return $this->err($e->getMessage());
    }
}
```

**控制器的职责**：
- 接收请求参数 (`$id = 123`)
- 协调各个服务层（商品服务、支付服务）
- 将数据传递给视图
- 返回响应给用户

**基类控制器**: `app/Http/Controllers/BaseController.php`
- 提供公共的渲染方法 `render()`
- 统一的错误处理方法 `err()`

---

## 3️⃣ 服务层 (Service)

这个项目使用了**服务层模式**，在控制器和模型之间增加了一层来处理业务逻辑。

**文件位置**: `app/Service/GoodsService.php`

```php
// app/Service/GoodsService.php 第 67 行
public function detail($id)
{
    // 使用 Eloquent ORM 查询商品
    return Goods::with(['group', 'coupon'])->findOrFail($id);
}
```

**这行代码做了什么**：
- `Goods::findOrFail($id)` - 查询数据库，如果找不到商品会抛出404异常
- `with(['group', 'coupon'])` - 预加载关联数据（商品分组、优惠券）
- 返回一个 `Goods` 模型实例

**相关服务文件**：
- `app/Service/GoodsService.php` - 商品业务逻辑
- `app/Service/OrderService.php` - 订单业务逻辑
- `app/Service/PayService.php` - 支付业务逻辑

---

## 4️⃣ 模型层 (Model)

**文件位置**: `app/Models/Goods.php`

```php
// app/Models/Goods.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Goods extends BaseModel
{
    use SoftDeletes;

    protected $table = 'goods';

    // 商品属于一个分组
    public function group()
    {
        return $this->belongsTo(GoodsGroup::class, 'group_id');
    }

    // 商品可以有多个优惠券
    public function coupon()
    {
        return $this->belongsToMany(Coupon::class, 'coupons_goods', 'goods_id', 'coupons_id');
    }
}
```

**模型的职责**：
- 定义与数据库表 `goods` 的映射
- 定义模型之间的关联关系（belongsTo, belongsToMany）
- 提供数据访问接口

**相关模型文件**：
- `app/Models/BaseModel.php` - 基础模型类
- `app/Models/Goods.php` - 商品模型
- `app/Models/Order.php` - 订单模型
- `app/Models/Pay.php` - 支付方式模型
- `app/Models/Coupon.php` - 优惠券模型
- `app/Models/GoodsGroup.php` - 商品分组模型
- `app/Models/Carmis.php` - 卡密模型

**对应的数据表结构** (部分字段):
```sql
CREATE TABLE goods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    gd_name VARCHAR(255) COMMENT '商品名称',
    gd_desc TEXT COMMENT '商品描述',
    gd_price DECIMAL(10,2) COMMENT '价格',
    group_id INT COMMENT '分组ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
);
```

---

## 5️⃣ 视图层 (View)

**文件位置**: `resources/views/luna/static_pages/buy.blade.php`

```blade
{{-- resources/views/luna/static_pages/buy.blade.php --}}
@extends('luna.layouts.default')

@section('title', $gd_name ?? '商品详情')

@section('content')
<div class="container">
    <div class="product-card">
        {{-- 商品标题 --}}
        <h1>{{ $gd_name }}</h1>

        {{-- 商品描述 --}}
        <div class="description">
            {!! $gd_desc !!}
        </div>

        {{-- 商品价格 --}}
        <div class="price">
            <span class="label">价格:</span>
            <span class="amount">¥{{ $gd_price }}</span>
        </div>

        {{-- 支付方式选择 --}}
        <div class="payment-methods">
            <h3>选择支付方式</h3>
            <div class="payway-list">
                @foreach($payways as $payway)
                    <div class="payway-item">
                        <input type="radio" name="payway" value="{{ $payway->id }}">
                        <span>{{ $payway->pay_name }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 购买按钮 --}}
        <button class="btn-buy" onclick="submitOrder()">立即购买</button>
    </div>
</div>

<script>
function submitOrder() {
    {{-- 提交订单到后端 --}}
}
</script>
@endsection
```

**视图的职责**：
- 接收控制器传递的数据 (`$formatGoods`)
- 使用 Blade 模板引擎渲染 HTML
- 展示数据给用户

**相关视图目录**：
- `resources/views/luna/` - Luna主题视图
- `resources/views/unicorn/` - Unicorn主题视图
- `resources/views/hyper/` - Hyper主题视图
- `resources/views/common/` - 公共组件
- `resources/views/admin/` - 管理后台视图
- `resources/views/email/` - 邮件模板

---

## 🔄 数据流转过程

```
1. 用户请求: GET /buy/123
   ↓
2. 路由匹配: routes/common/web.php
   Route::get('buy/{id}', 'HomeController@buy')
   ↓
3. 中间件处理: dujiaoka.boot (初始化应用配置)
   ↓
4. 控制器接收: app/Http/Controllers/Home/HomeController.php
   HomeController::buy(123)
   ↓
5. 服务层查询: app/Service/GoodsService.php
   GoodsService::detail(123)
   ↓
6. 模型查询数据库: app/Models/Goods.php
   Goods::findOrFail(123) → 查询 goods 表
   ↓
7. 返回数据: 商品对象 + 关联的分组/优惠券
   ↓
8. 控制器处理: 格式化数据 + 获取支付方式
   $formatGoods = 商品数据数组
   ↓
9. 视图渲染: resources/views/luna/static_pages/buy.blade.php
   Blade模板引擎渲染HTML
   ↓
10. 返回HTML: 完整的商品详情页面给用户
```

---

## 📊 各层职责总结

| 层级 | 文件位置 | 主要职责 | 示例代码 |
|------|---------|---------|---------|
| **Route (路由)** | `routes/web.php`<br>`routes/common/web.php` | URL到控制器的映射 | `Route::get('buy/{id}', 'HomeController@buy')` |
| **Controller (控制器)** | `app/Http/Controllers/` | 接收请求、协调服务、返回响应 | `public function buy(int $id)` |
| **Service (服务层)** | `app/Service/` | 业务逻辑处理、数据封装 | `public function detail($id)` |
| **Model (模型)** | `app/Models/` | 数据访问、ORM映射、关联关系 | `Goods::findOrFail($id)` |
| **View (视图)** | `resources/views/` | 页面展示、数据渲染、用户交互 | `@foreach($payways as $payway)` |

---

## 🎯 关键点总结

### 1. 分层清晰
每一层只做自己的事，不越界：
- **路由**：只负责URL映射
- **控制器**：只负责协调和响应
- **服务层**：只负责业务逻辑
- **模型**：只负责数据访问
- **视图**：只负责页面展示

### 2. 服务层模式
- 控制器不直接操作模型，通过服务层封装业务逻辑
- 优点：代码复用、易于测试、逻辑集中

### 3. 依赖注入
- 控制器通过 `app('Service\GoodsService')` 获取服务实例
- Laravel的服务容器自动管理依赖

### 4. ORM映射
- Eloquent ORM 自动将数据库记录转换为模型对象
- 提供简洁的查询API：`Goods::with(['group'])->findOrFail(123)`

### 5. Blade模板引擎
- 视图使用简洁的语法渲染数据
- `{{ }}` 输出转义后的HTML
- `{!! !!}` 输出原始HTML
- `@foreach/@if/@section` 等指令

### 6. 中间件机制
- 在路由层应用中间件：`Route::group(['middleware' => ['dujiaoka.boot']])`
- 中间件文件位置：`app/Http/Middleware/`

---

## 📁 项目目录结构

```
dujiaoka/
├── app/
│   ├── Http/
│   │   ├── Controllers/          # 控制器目录
│   │   │   ├── BaseController.php
│   │   │   ├── Home/
│   │   │   │   └── HomeController.php
│   │   │   └── Pay/              # 支付控制器
│   │   └── Middleware/           # 中间件
│   ├── Models/                   # 模型目录
│   │   ├── BaseModel.php
│   │   ├── Goods.php
│   │   ├── Order.php
│   │   └── Pay.php
│   └── Service/                  # 服务层目录
│       ├── GoodsService.php
│       ├── OrderService.php
│       └── PayService.php
├── resources/
│   └── views/                    # 视图目录
│       ├── luna/                 # Luna主题
│       │   ├── layouts/
│       │   └── static_pages/
│       │       └── buy.blade.php
│       ├── unicorn/              # Unicorn主题
│       └── admin/                # 管理后台
└── routes/                       # 路由目录
    ├── web.php                   # 主路由文件
    ├── api.php                   # API路由
    └── common/
        ├── web.php               # 公共Web路由
        └── pay.php               # 支付路由
```

---

## 🔗 相关文件路径索引

### 核心文件
- **路由配置**: `routes/common/web.php:36`
- **控制器**: `app/Http/Controllers/Home/HomeController.php:45`
- **服务层**: `app/Service/GoodsService.php:67`
- **模型**: `app/Models/Goods.php:1`
- **视图**: `resources/views/luna/static_pages/buy.blade.php:1`
- **基础控制器**: `app/Http/Controllers/BaseController.php:1`
- **基础模型**: `app/Models/BaseModel.php:1`

### 配置文件
- **应用配置**: `config/app.php`
- **数据库配置**: `config/database.php`
- **路由服务**: `app/Providers/RouteServiceProvider.php`

### 其他关键文件
- **Composer依赖**: `composer.json`
- **入口文件**: `public/index.php`
- **.env环境配置**: `.env`

---

这个就是 Laravel 框架中标准的 MVC + Service Layer 架构模式，清晰、可维护、易扩展！
