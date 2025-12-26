# MVC 完整数据流转 - buy 操作详解

## 一、核心问题

1. **buy 方法最早在哪里定义?**
2. **数据如何在 MVC 各层之间流转?**
3. **`$formatGoods->payways = $this->payService->pays($client)` 是什么时候执行的?**

---

## 二、完整流转过程 (按时间顺序)

### 阶段 1: 用户发起请求

```
用户在浏览器中访问:
http://domain.com/buy/1
```

**请求组成**:
- **URL**: `/buy/1`
- **HTTP 方法**: GET
- **参数**: `{id: 1}`

---

### 阶段 2: 路由匹配 (Routes)

**文件**: `routes/common/web.php:18`

```php
Route::get('buy/{id}', 'HomeController@buy');
```

**执行过程**:
1. Laravel 路由系统接收请求
2. 匹配路由规则 `buy/{id}`
3. 提取路径参数: `$id = 1`
4. 确定目标控制器: `HomeController@buy`
5. 应用中间件: `dujiaoka.boot`

**路由解析结果**:
```php
// 相当于调用
$controller = new HomeController();
$controller->buy(1);  // $id = 1
```

---

### 阶段 3: 中间件处理

**文件**: `routes/common/web.php:12`

```php
Route::group(['middleware' => ['dujiaoka.boot']], function () {
    Route::get('buy/{id}', 'HomeController@buy');
});
```

**中间件** (app/Http/Middleware/DujiaoBoot.php):
- 初始化系统配置
- 检查安装状态
- 其他全局初始化操作

---

### 阶段 4: 控制器执行 (Controller)

**文件**: `app/Http/Controllers/Home/HomeController.php:65-87`

```php
public function buy(int $id)
{
    try {
        // 4.1 从数据库获取商品详情
        $goods = $this->goodsService->detail($id);

        // 4.2 验证商品状态 (是否上架、是否存在)
        $this->goodsService->validatorGoodsStatus($goods);

        // 4.3 检查是否有优惠码
        if (count($goods->coupon)) {
            $goods->open_coupon = 1;
        }

        // 4.4 格式化商品数据 (批发价、自定义输入框等)
        $formatGoods = $this->goodsService->format($goods);

        // 4.5 判断客户端类型 (PC/移动端)
        $client = Pay::PAY_CLIENT_PC;  // 默认 PC
        if (app('Jenssegers\Agent')->isMobile()) {
            $client = Pay::PAY_CLIENT_MOBILE;  // 移动端
        }

        // 4.6 ⭐ 获取支付方式 (重点!)
        $formatGoods->payways = $this->payService->pays($client);

        // 4.7 渲染视图并返回 HTML
        return $this->render('static_pages/buy', $formatGoods, $formatGoods->gd_name);

    } catch (RuleValidationException $e) {
        // 异常处理
        return $this->err($e->getMessage());
    }
}
```

**关键点**:
- `$id` 参数由路由自动注入: `int $id = 1`
- 所有代码在**服务器端同步执行**
- 执行完成后返回 HTML 响应

---

### 阶段 5: Service 层业务逻辑 (重点!)

#### 5.1 GoodsService 获取商品

**文件**: `app/Service/GoodsService.php:65-73`

```php
public function detail(int $id)
{
    // ORM 查询数据库
    $goods = Goods::query()
        ->with(['coupon'])  // 关联查询优惠码
        ->withCount(['carmis' => function($query) {
            $query->where('status', Carmis::STATUS_UNSOLD);  // 统计未售出卡密
        }])
        ->where('id', $id)
        ->first();

    return $goods;  // 返回 Goods Model 对象
}
```

**执行过程**:
```
Controller 调用:
    $goods = $this->goodsService->detail(1);
        ↓
GoodsService 执行:
    Goods::where('id', 1)->first();
        ↓
生成 SQL:
    SELECT * FROM goods WHERE id = 1 LIMIT 1;
        ↓
查询数据库:
    goods 表
        ↓
返回结果:
    Goods Model 对象 {
        id: 1,
        gd_name: '商品A',
        actual_price: 100.00,
        in_stock: 50,
        ...
    }
```

#### 5.2 PayService 获取支付方式 ⭐⭐⭐

**文件**: `app/Service/PayService.php:28-35`

```php
public function pays(string $payClient = Pay::PAY_CLIENT_PC): ?array
{
    // 查询数据库
    $payGateway = Pay::query()
        ->whereIn('pay_client', [$payClient, Pay::PAY_CLIENT_ALL])
        ->where('is_open', Pay::STATUS_OPEN)
        ->get();

    // 转换为数组返回
    return $payGateway ? $payGateway->toArray() : null;
}
```

**Controller 中的调用** (HomeController.php:81):
```php
// ⭐ 这一行在页面加载时就立即执行了!
$formatGoods->payways = $this->payService->pays($client);
```

**执行时机**: ⭐⭐⭐ **立即执行! 不是点击后执行!**

**完整执行过程**:
```
Controller 执行到第 81 行:
    $formatGoods->payways = $this->payService->pays($client);
        ↓
PayService::pays(1) 执行:
    Pay::whereIn('pay_client', [1, 3])
        ->where('is_open', 1)
        ->get();
        ↓
生成 SQL:
    SELECT * FROM pays
    WHERE pay_client IN (1, 3)
    AND is_open = 1;
        ↓
查询数据库:
    pays 表
        ↓
返回结果:
    [
        {id: 1, pay_name: '支付宝', pay_check: 'alipay', ...},
        {id: 2, pay_name: '微信支付', pay_check: 'wechat', ...},
        {id: 3, pay_name: 'QQ支付', pay_check: 'qqpay', ...}
    ]
        ↓
赋值给对象属性:
    $formatGoods->payways = [上述数组];
```

**执行结果**:
```php
$formatGoods = {
    gd_name: '商品A',
    actual_price: 100.00,
    in_stock: 50,
    payways: [  // ← 这里已经包含所有支付方式数据!
        {pay_name: '支付宝', pay_check: 'alipay'},
        {pay_name: '微信支付', pay_check: 'wechat'},
        {pay_name: 'QQ支付', pay_check: 'qqpay'}
    ]
}
```

---

### 阶段 6: BaseController 渲染视图

**文件**: `app/Http/Controllers/BaseController.php:28-33`

```php
protected function render(string $tpl, $data = [], string $pageTitle = '')
{
    // 1. 获取当前主题 (从数据库配置)
    $layout = dujiaoka_config_get('template', 'unicorn');  // 假设是 'luna'

    // 2. 拼接视图路径
    $tplPath = $layout . '/' . $tpl;
    // 结果: 'luna/static_pages/buy'

    // 3. 调用 Laravel view 函数渲染
    return view($tplPath, $data)->with('page_title', $pageTitle);
}
```

**Controller 调用** (HomeController.php:82):
```php
return $this->render('static_pages/buy', $formatGoods, $formatGoods->gd_name);
//                                          ↑ 对象       ↑ 页面标题
```

**参数映射**:
```php
$tpl = 'static_pages/buy'
$data = $formatGoods  // 包含 payways 的完整对象
$pageTitle = '商品A'
```

---

### 阶段 7: Laravel View 渲染

```php
view('luna/static_pages/buy', $formatGoods)
```

**Laravel 自动解析**:
```
视图路径: resources/views/luna/static_pages/buy.blade.php
数据对象: $formatGoods (在视图中可用)
```

**数据传递方式**:
```php
// Laravel 内部处理
$view = new View($viewPath, $data);
// $data 会被转换为数组,对象属性保持不变
```

---

### 阶段 8: 视图模板渲染 (View)

**文件**: `resources/views/luna/static_pages/buy.blade.php`

```blade
@extends('luna.layouts.app')

@section('title', '商品购买')

@section('content')
<div class="buy-page">
    {{-- 商品基本信息 --}}
    <h1>{{ $formatGoods->gd_name }}</h1>
    <p>价格: ¥{{ $formatGoods->actual_price }}</p>
    <p>库存: {{ $formatGoods->in_stock }}</p>

    {{-- ⭐⭐⭐ 支付方式列表 (你选中的第 393 行附近) --}}
    <div class="payways">
        @foreach($formatGoods->payways as $payway)
            <button data-pay-check="{{ $payway->pay_check }}">
                {{ $payway->pay_name }}
            </button>
        @endforeach
    </div>

    {{-- 或者用 $data 变量访问 --}}
    @foreach($data->payways as $payway)
        <button>{{ $payway->pay_name }}</button>
    @endforeach
</div>
@endsection
```

**视图中访问数据**:
- `$formatGoods` - 完整的商品对象
- `$formatGoods->payways` - 支付方式数组 (已经在 Controller 中查询好了!)
- `$data` - 也是指向 `$formatGoods` 对象

**Blade 渲染输出**:
```html
<h1>商品A</h1>
<p>价格: ¥100.00</p>
<p>库存: 50</p>

<div class="payways">
    <button data-pay-check="alipay">支付宝</button>
    <button data-pay-check="wechat">微信支付</button>
    <button data-pay-check="qqpay">QQ支付</button>
</div>
```

---

### 阶段 9: 返回 HTML 响应

```
视图渲染完成
    ↓
生成完整的 HTML 文档
    ↓
HTTP 响应返回给浏览器
    ↓
用户看到完整的购买页面
```

---

## 三、执行时机详解 (关键!)

### ⭐⭐⭐ `$this->payService->pays($client)` 什么时候执行?

**答案**: 立即执行! 在页面加载时就执行了!

### 详细时间线

```
时间轴 (从页面加载到显示):

T0: 用户访问 /buy/1
    ↓
T1: 路由匹配, 提取参数 $id = 1
    ↓
T2: 中间件初始化
    ↓
T3: HomeController::buy(1) 开始执行
    ↓
T4: GoodsService::detail(1) 查询商品
    ↓  (数据库查询: SELECT * FROM goods WHERE id = 1)
    ↓
T5: GoodsService::validatorGoodsStatus() 验证商品
    ↓
T6: GoodsService::format() 格式化数据
    ↓
T7: 判断客户端类型 (PC/移动)
    ↓
T8: ⭐⭐⭐ PayService::pays($client) 立即执行!
    ↓  (数据库查询: SELECT * FROM pays WHERE is_open = 1)
    ↓
    返回支付方式数组:
    [
        {pay_name: '支付宝', pay_check: 'alipay'},
        {pay_name: '微信', pay_check: 'wechat'},
        ...
    ]
    ↓
    赋值给 $formatGoods->payways
    ↓
T9: BaseController::render() 渲染视图
    ↓
T10: Blade 模板解析, 生成 HTML
    ↓
T11: 返回完整 HTML 给浏览器
    ↓
T12: 用户看到页面 (包含所有支付方式按钮)
```

### 不是点击后执行!

**错误理解**:
```
❌ 用户看到页面
❌ 用户点击"支付宝"按钮
❌ 此时才执行 $this->payService->pays($client)
```

**正确理解**:
```
✅ 用户访问 /buy/1
✅ 服务端立即执行 $this->payService->pays($client)
✅ 查询数据库获取所有支付方式
✅ 将支付方式数据渲染到 HTML 中
✅ 返回包含支付方式按钮的完整页面
✅ 用户看到页面时,支付方式已经在 HTML 里了!
```

---

## 四、数据在各层之间的流转

### 流转图

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Browser (浏览器)                                          │
│    URL: http://domain.com/buy/1                             │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. Routes (路由层)                                          │
│    routes/common/web.php:18                                 │
│    Route::get('buy/{id}', 'HomeController@buy');            │
│    提取参数: $id = 1                                         │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. Middleware (中间件层)                                     │
│    dujiaoka.boot - 系统初始化                               │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. Controller (控制器层)                                     │
│    HomeController.php:65                                    │
│    public function buy(int $id)                             │
│    ┌────────────────────────────────────────────┐           │
│    │ $goods = $this->goodsService->detail($id); │           │
│    │ $formatGoods = $this->goodsService->format │           │
│    │ $formatGoods->payways = $this->payService  │ ← 调用    │
│    │   ->pays($client);  ⭐ 立即执行!            │           │
│    └────────────────────────────────────────────┘           │
└──────────┬──────────────────────────────────┬───────────────┘
           ↓                                  ↓
┌──────────────────────┐      ┌──────────────────────────────┐
│ 5. Service Layer     │      │ PayService                   │
│ (业务逻辑层)         │      │ app/Service/PayService.php   │
│                      │      │                              │
│ GoodsService::detail │      │ public function pays()       │
│ ├─ 查询商品          │      │ ├─ 查询 pays 表              │
│ ├─ 验证状态          │      │ ├─ WHERE is_open = 1         │
│ └─ 返回 Goods 对象   │      │ └─ 返回支付方式数组          │
└──────────┬───────────┘      └──────────┬───────────────────┘
           ↓                              ↓
┌─────────────────────────────────────────────────────────────┐
│ 6. Model (模型层)                                           │
│    Goods.php, Pay.php                                       │
│    ORM 查询数据库                                            │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 7. Database (数据库层)                                       │
│    goods 表, pays 表                                        │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 8. 返回数据组装                                              │
│    $formatGoods = {                                         │
│      gd_name: '商品A',                                       │
│      actual_price: 100,                                     │
│      payways: [                                             │
│        {pay_name: '支付宝'},                                │
│        {pay_name: '微信'}                                   │
│      ]                                                       │
│    }                                                         │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 9. BaseController::render()                                 │
│    拼接视图路径: luna/static_pages/buy                      │
│    调用 view($tplPath, $formatGoods)                        │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 10. View (视图层)                                           │
│     resources/views/luna/static_pages/buy.blade.php         │
│                                                              │
│     @foreach($formatGoods->payways as $payway)              │
│         <button>{{ $payway->pay_name }}</button>            │
│     @endforeach                                              │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 11. Blade 渲染引擎                                          │
│     将模板转换为 HTML                                        │
│     <button>支付宝</button>                                  │
│     <button>微信</button>                                    │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 12. HTTP Response                                           │
│     返回完整 HTML 给浏览器                                   │
└────────────────────┬────────────────────────────────────────┘
                     ↓
┌─────────────────────────────────────────────────────────────┐
│ 13. Browser Display                                          │
│     用户看到购买页面 (包含支付方式按钮)                       │
└─────────────────────────────────────────────────────────────┘
```

---

## 五、关键概念辨析

### 5.1 同步执行 vs 异步执行

**本项目中**: **同步执行**

```php
// 第 81 行执行完毕后,才会执行第 82 行
$formatGoods->payways = $this->payService->pays($client);  // ← 阻塞等待
return $this->render(...);  // ← 等上面执行完才执行
```

**不是 AJAX 异步请求**:
```php
// ❌ 这不是异步的!
// ❌ 不是点击后才查询支付方式!
```

### 5.2 服务端渲染 vs 客户端渲染

**本项目**: **服务端渲染 (SSR)**

```
服务端 (PHP):
    1. 查询数据库
    2. 渲染 HTML
    3. 返回完整页面

客户端 (浏览器):
    1. 接收 HTML
    2. 直接显示
```

**不是客户端渲染**:
```javascript
// ❌ 不是这样的!
// ❌ 不是浏览器用 JS 请求数据
fetch('/api/get-payways')
    .then(res => res.json())
    .then(data => renderButtons(data));
```

---

## 六、验证方式

### 6.1 查看数据库查询日志

```php
// 在 HomeController.php 中添加
DB::enableQueryLog();

public function buy(int $id)
{
    // ... 其他代码

    $formatGoods->payways = $this->payService->pays($client);

    // 打印查询日志
    dd(DB::getQueryLog());
}
```

**输出**:
```
[
    {
        query: "SELECT * FROM goods WHERE id = 1 LIMIT 1",
        bindings: [1],
        time: 1.23
    },
    {
        query: "SELECT * FROM pays WHERE pay_client IN (1, 3) AND is_open = 1",
        bindings: [1, 3, 1],
        time: 0.56
    }
]
```

### 6.2 查看浏览器开发者工具

**Network 标签**:
```
Request URL: http://domain.com/buy/1
Request Method: GET
Response: 完整的 HTML (包含支付方式按钮)
```

**没有额外的 AJAX 请求**!

---

## 七、总结

### 核心流程

```
URL 路由 → Controller → Service → Model → Database
                                  ↓
                            返回数据对象
                                  ↓
                            渲染视图
                                  ↓
                            返回 HTML
```

### `$this->payService->pays($client)` 执行时机

| 时间点 | 事件 |
|--------|------|
| **T0** | 用户访问 `/buy/1` |
| **T1** | 路由匹配 |
| **T2-T7** | Controller 执行业务逻辑 |
| **T8** | ⭐ **`pays($client)` 立即执行,查询数据库** |
| **T9-T11** | 渲染视图,返回 HTML |
| **T12** | 用户看到页面 |

**关键**: 支付方式在页面返回给用户之前就已经查询好了!

### 数据传递方式

```php
// Controller
$formatGoods->payways = $this->payService->pays($client);

// 传递给视图
return $this->render('static_pages/buy', $formatGoods, $title);

// 视图中访问
@foreach($formatGoods->payways as $payway)
    <button>{{ $payway->pay_name }}</button>
@endforeach
```

**对象流转**:
```
数据库查询结果 → Pay Model → 数组 → 对象属性 → 视图变量 → HTML
```

---

**文档版本**: v1.0
**最后更新**: 2025-01-01
**项目**: 独角兽卡网 (dujiaoka)
