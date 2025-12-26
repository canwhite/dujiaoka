# Laravel ORM (Eloquent) 语法与配置详解

## 目录
- [一、Laravel ORM 配置](#一laravel-orm-配置)
- [二、Model 定义](#二model-定义)
- [三、ORM 查询语法](#三orm-查询语法)
- [四、ORM 操作语法](#四orm-操作语法)
- [五、模型关联](#五模型关联)
- [六、查询作用域](#六查询作用域)
- [七、实战示例](#七实战示例)

---

## 一、Laravel ORM 配置

### 1.1 数据库配置文件位置

**主配置文件**: `config/database.php`
- 第 18 行定义默认连接类型

**环境变量配置**: `.env` 文件
```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dujiaoka
DB_USERNAME=root
DB_PASSWORD=
```

### 1.2 数据库连接配置结构

`config/database.php` (第 46-64 行)

```php
'mysql' => [
    'driver' => 'mysql',                      // 数据库驱动
    'host' => env('DB_HOST', '127.0.0.1'),    // 主机地址
    'port' => env('DB_PORT', '3306'),         // 端口
    'database' => env('DB_DATABASE', 'forge'), // 数据库名
    'username' => env('DB_USERNAME', 'forge'), // 用户名
    'password' => env('DB_PASSWORD', ''),      // 密码
    'charset' => 'utf8mb4',                   // 字符集
    'collation' => 'utf8mb4_unicode_ci',      // 排序规则
    'prefix' => '',                            // 表前缀
    'prefix_indexes' => true,                  // 是否给索引加前缀
    'strict' => true,                          // 严格模式
    'engine' => null,                          // 存储引擎
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
],
```

### 1.3 支持的数据库类型

Laravel 支持多种数据库:
- **MySQL** (最常用)
- **PostgreSQL**
- **SQLite**
- **SQL Server**

---

## 二、Model 定义

### 2.1 基础 Model 示例

以 `Pay` 模型为例 (`app/Models/Pay.php`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pay extends BaseModel
{
    // 指定对应的数据库表名
    protected $table = 'pays';

    // 定义常量 (魔术数字转为语义化常量)
    const METHOD_JUMP = 1;        // 跳转支付
    const METHOD_SCAN = 2;        // 扫码支付
    const PAY_CLIENT_PC = 1;      // PC端
    const PAY_CLIENT_MOBILE = 2;  // 移动端
    const PAY_CLIENT_ALL = 3;     // 通用

    // 可以定义映射关系
    public static function getMethodMap()
    {
        return [
            self::METHOD_JUMP => '跳转',
            self::METHOD_SCAN => '扫码',
        ];
    }
}
```

### 2.2 Model 常用属性详解

```php
class Pay extends Model
{
    protected $table = 'pays';           // 指定表名 (默认为类名复数形式)
    protected $primaryKey = 'id';        // 主键字段名 (默认是 id)
    public $incrementing = true;         // 主键是否自增 (默认 true)
    protected $keyType = 'int';          // 主键类型 (int/string)
    public $timestamps = true;           // 是否自动维护时间戳 (默认 true)
    const CREATED_AT = 'created_at';     // 创建时间字段名
    const UPDATED_AT = 'updated_at';     // 更新时间字段名

    // 批量赋值白名单 (安全机制)
    protected $fillable = ['pay_name', 'pay_check', 'is_open'];

    // 批量赋值黑名单 (不推荐使用)
    protected $guarded = ['id'];

    // 字段类型转换
    protected $casts = [
        'is_open' => 'boolean',
        'price' => 'decimal:2',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    // 隐藏字段 (JSON 序列化时不显示)
    protected $hidden = ['password', 'token'];

    // 可见字段 (只有这些字段会显示)
    protected $visible = ['name', 'email'];
}
```

### 2.3 软删除 (Soft Deletes)

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Pay extends Model
{
    use SoftDeletes;  // 引入软删除 trait

    protected $table = 'pays';
    // deleted_at 字段会自动维护
}

// 软删除不会真正删除数据,只是设置 deleted_at 时间戳
Pay::find(1)->delete();  // 软删除

// 查询包含已删除的记录
Pay::withTrashed()->get();

// 只查询已删除的记录
Pay::onlyTrashed()->get();

// 恢复已删除的记录
Pay::onlyTrashed()->first()->restore();

// 永久删除
Pay::onlyTrashed()->first()->forceDelete();
```

---

## 三、ORM 查询语法

### 3.1 基础查询

```php
// 查询所有数据
$pays = Pay::all();

// 查询单条数据
$pay = Pay::find(1);                    // 根据 ID 查找
$pay = Pay::find([1, 2, 3]);            // 根据 ID 数组查找
$pay = Pay::where('id', 1)->first();    // 根据条件查找第一条
$pay = Pay::where('pay_check', 'alipay')->firstOrFail();  // 找不到抛出异常
```

### 3.2 条件查询

#### 单个条件
```php
Pay::where('is_open', 1)->get();
Pay::where('price', '>', 100)->get();
Pay::where('pay_name', 'like', '%支付宝%')->get();
```

#### 多个条件 (AND)
```php
Pay::where('is_open', 1)
    ->where('pay_client', 1)
    ->get();
```

#### OR 条件
```php
Pay::where('pay_client', 1)
    ->orWhere('pay_client', 3)
    ->get();

// 使用 whereIn (更简洁)
Pay::whereIn('pay_client', [1, 3])->get();
// 等价于 SQL: WHERE pay_client IN (1, 3)
```

### 3.3 实战示例解析

来自 `app/Service/PayService.php:30-34`

```php
$payGateway = Pay::query()
    ->whereIn('pay_client', [$payClient, Pay::PAY_CLIENT_ALL])
    ->where('is_open', Pay::STATUS_OPEN)
    ->get();

return $payGateway ? $payGateway->toArray() : null;
```

**等价 SQL**:
```sql
SELECT * FROM pays
WHERE pay_client IN (1, 3)
AND is_open = 1
```

**解析**:
1. `Pay::query()` - 开始查询
2. `whereIn('pay_client', [$payClient, Pay::PAY_CLIENT_ALL])` - 支持 PC 端或通用
3. `where('is_open', Pay::STATUS_OPEN)` - 必须是开启状态
4. `get()` - 获取结果集
5. `toArray()` - 转换为数组

### 3.4 查询方法对比表

| 方法 | 返回结果 | 使用场景 |
|------|---------|---------|
| `get()` | `Collection` 集合 | 获取多条记录 |
| `first()` | Model 对象或 null | 获取第一条记录 |
| `find($id)` | Model 对象或 null | 根据主键查找 |
| `findOrFail($id)` | Model 对象或异常 | 根据主键查找,找不到抛出 404 |
| `firstOrFail()` | Model 对象或异常 | 获取第一条,找不到抛出 404 |
| `value('column')` | 单个值 | 获取单个字段值 |
| `pluck('column')` | Collection | 获取一列数据 |
| `count()` | int | 统计记录数 |
| `exists()` | bool | 判断记录是否存在 |
| `max('price')` | mixed | 获取最大值 |
| `min('price')` | mixed | 获取最小值 |
| `avg('price')` | float | 获取平均值 |
| `sum('price')` | float | 获取总和 |

### 3.5 更多查询示例

```php
// 排序
Pay::orderBy('id', 'desc')->get();      // 按 ID 倒序
Pay::orderBy('price', 'asc')->get();    // 按价格升序
Pay::latest()->get();                   // 按创建时间倒序
Pay::oldest()->get();                   // 按创建时间正序

// 限制数量
Pay::take(10)->get();                   // 前10条
Pay::skip(10)->take(5)->get();          // 跳过10条,取5条
Pay::limit(5)->offset(10)->get();       // 同上 (SQL 风格)

// 分页
Pay::paginate(15);                      // 每页15条 (返回分页器对象)
Pay::simplePaginate(15);                // 简单分页 (不显示总数)

// 范围查询
Pay::whereBetween('price', [10, 100])->get();           // 价格在 10-100 之间
Pay::whereNotBetween('price', [10, 100])->get();        // 价格不在 10-100 之间
Pay::whereIn('id', [1, 2, 3])->get();                   // ID 在数组中
Pay::whereNotIn('id', [1, 2, 3])->get();                // ID 不在数组中
Pay::whereNull('deleted_at')->get();                    // 字段为 null
Pay::whereNotNull('deleted_at')->get();                 // 字段不为 null

// 日期查询
Pay::whereDate('created_at', '2025-01-01')->get();              // 特定日期
Pay::whereMonth('created_at', '01')->get();                     // 特定月份
Pay::whereDay('created_at', '01')->get();                       // 特定日
Pay::whereYear('created_at', '2025')->get();                    // 特定年
Pay::whereTime('created_at', '>=', '09:00')->get();             // 特定时间
Pay::whereBetween('created_at', ['2025-01-01', '2025-12-31'])->get();  // 日期范围

// 原始查询 (谨慎使用,防止 SQL 注入)
Pay::whereRaw('price > discount_price')->get();
Pay::selectRaw('price * ? as price_with_tax', [1.1])->get();
```

### 3.6 聚合查询

```php
$count = Pay::count();                    // 统计记录数
$count = Pay::where('is_open', 1)->count();

$sum = Pay::sum('price');                 // 总和
$avg = Pay::avg('price');                 // 平均值
$max = Pay::max('price');                 // 最大值
$min = Pay::min('price');                 // 最小值

// 分组聚合
Pay::select('pay_client', Pay::raw('COUNT(*) as count'))
    ->groupBy('pay_client')
    ->get();
```

### 3.7 高级查询技巧

```php
// 条件分组 (复杂 OR 条件)
Pay::where(function ($query) {
    $query->where('price', '>', 100)
          ->orWhere('discount', '>', 50);
})->get();

// 动态条件
Pay::when($status, function ($query) use ($status) {
    return $query->where('status', $status);
})->get();

// 子查询
Pay::whereIn('id', function ($query) {
    $query->select('pay_id')
          ->from('orders')
          ->where('status', 'paid');
})->get();
```

---

## 四、ORM 操作语法

### 4.1 插入数据

```php
// 方式1: 创建模型实例 (需要手动调用 save)
$pay = new Pay();
$pay->pay_name = '支付宝';
$pay->pay_check = 'alipay';
$pay->pay_client = 1;
$pay->is_open = 1;
$pay->save();  // 保存到数据库

// 方式2: 使用 create 方法 (需要配置 $fillable)
Pay::create([
    'pay_name' => '微信支付',
    'pay_check' => 'wechat',
    'pay_client' => 1,
    'is_open' => 1,
]);

// 方式3: firstOrCreate (不存在则创建,存在则返回现有记录)
$pay = Pay::firstOrCreate(
    ['pay_check' => 'alipay'],           // 查找条件
    ['pay_name' => '支付宝', 'is_open' => 1]  // 创建时的值
);

// 方式4: updateOrCreate (存在则更新,不存在则创建)
$pay = Pay::updateOrCreate(
    ['pay_check' => 'alipay'],           // 查找条件
    ['pay_name' => '支付宝扫码', 'is_open' => 1]  // 更新的值
);
```

### 4.2 更新数据

```php
// 方式1: 先查后改
$pay = Pay::find(1);
$pay->pay_name = '支付宝扫码';
$pay->is_open = 0;
$pay->save();  // 保存修改

// 方式2: 批量更新
Pay::where('is_open', 0)->update(['is_open' => 1]);
Pay::where('pay_client', 1)->update(['is_open' => 1, 'updated_at' => now()]);

// 方式3: updateOrCreate
$pay = Pay::updateOrCreate(
    ['pay_check' => 'alipay'],           // 查找条件
    ['pay_name' => '支付宝', 'is_open' => 1]  // 更新的值
);

// 方式4: 自增/自减
Pay::find(1)->increment('count');           // count + 1
Pay::find(1)->increment('count', 5);        // count + 5
Pay::find(1)->decrement('stock');           // stock - 1
Pay::find(1)->decrement('stock', 2);        // stock - 2
```

### 4.3 删除数据

```php
// 方式1: 先查后删
$pay = Pay::find(1);
$pay->delete();

// 方式2: 根据主键删除
Pay::destroy(1);           // 删除 ID 为 1 的记录
Pay::destroy([1, 2, 3]);   // 批量删除
Pay::destroy(1, 2, 3);     // 同上

// 方式3: 条件删除
Pay::where('is_open', 0)->delete();
Pay::where('created_at', '<', '2024-01-01')->delete();

// 方式4: 软删除 (需要 use SoftDeletes)
$pay->delete();                    // 软删除
Pay::withTrashed()->get();         // 包含已删除的记录
Pay::onlyTrashed()->get();         // 只获取已删除的记录
$pay->restore();                  // 恢复删除
$pay->forceDelete();              // 永久删除
```

### 4.4 批量操作

```php
// 批量插入 (跳过事件监听,性能更好)
Pay::insert([
    ['pay_name' => '支付宝', 'pay_check' => 'alipay', 'is_open' => 1],
    ['pay_name' => '微信', 'pay_check' => 'wechat', 'is_open' => 1],
]);

// 批量更新
Pay::whereIn('id', [1, 2, 3])->update(['is_open' => 1]);
```

---

## 五、模型关联

### 5.1 一对一 (One-to-One)

```php
class User extends Model
{
    // 用户有一个个人资料
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }
}

class Profile extends Model
{
    // 个人资料属于一个用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

// 使用
$user = User::find(1);
$profile = $user->profile;  // 获取个人资料

$profile = Profile::find(1);
$user = $profile->user;  // 获取用户
```

### 5.2 一对多 (One-to-Many)

```php
class Order extends Model
{
    // 一个订单有多个订单项
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }
}

class OrderItem extends Model
{
    // 订单项属于一个订单
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}

// 使用
$order = Order::find(1);
$items = $order->items;              // 获取所有订单项
$firstItem = $order->items()->first();  // 使用查询方法

$item = OrderItem::find(1);
$order = $item->order;  // 获取所属订单

// 关联创建
$order = Order::find(1);
$order->items()->create([
    'goods_id' => 1,
    'quantity' => 2,
    'price' => 100,
]);
```

### 5.3 多对多 (Many-to-Many)

```php
class Order extends Model
{
    // 订单可以有多个标签
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'order_tags', 'order_id', 'tag_id')
                    ->withPivot('color')  // 访问中间表的额外字段
                    ->withTimestamps();   // 中间表包含时间戳
    }
}

class Tag extends Model
{
    // 标签可以有多个订单
    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_tags', 'tag_id', 'order_id');
    }
}
---
PS： 关于belongsToMany的一些用法简介：
belongsToMany($relatedModel, $pivotTable, $foreignPivotKey, $relatedPivotKey)

$relatedModel - 关联的模型类
$pivotTable - 中间表（数据透视表）名称
$foreignPivotKey - 当前模型在中间表中的外键字段名
$relatedPivotKey - 关联模型在中间表中的外键字段名
---
// 使用
$order = Order::find(1);
$tags = $order->tags;  // 获取所有标签

// 附加关联
$order->tags()->attach([1, 2, 3]);  // 附加标签 ID 为 1, 2, 3
$order->tags()->attach(1, ['color' => 'red']);  // 附加并设置中间表字段

// 分离关联
$order->tags()->detach([1, 2]);  // 移除标签
$order->tags()->sync([1, 2, 3]);  // 同步 (只保留这些标签)

// 切换关联
$order->tags()->toggle([1, 2]);  // 存在则移除,不存在则添加
```

### 5.4 远程一对多 (Has Many Through)

```php
class Country extends Model
{
    // 国家 -> 省份 -> 用户 (通过省份获取国家的所有用户)
    public function users()
    {
        return $this->hasManyThrough(User::class, Province::class, 'country_id', 'province_id');
    }
}

// 使用
$country = Country::find(1);
$users = $country->users;  // 获取该国家的所有用户
```

### 5.5 关联查询优化

```php
// 预加载 (解决 N+1 查询问题)
$orders = Order::with('goods')->get();  // 预加载商品关联

foreach ($orders as $order) {
    echo $order->goods->gd_name;  // 不会额外查询数据库
}

// 嵌套预加载
$orders = Order::with('goods.coupons')->get();

// 条件预加载
$orders = Order::with(['goods' => function ($query) {
    $query->where('is_open', 1);
}])->get();

// 延迟预加载
$orders = Order::all();
$orders->load('goods');  // 后续才预加载

// 查询是否存在关联
$order = Order::find(1);
if ($order->items()->exists()) {
    // 订单有订单项
}

// 统计关联数量
$orders = Order::withCount('items')->get();
foreach ($orders as $order) {
    echo $order->items_count;  // 订单项数量
}
```

---

## 六、查询作用域

### 6.1 全局作用域

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;

class OpenScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $builder->where('is_open', 1);
    }
}

class Pay extends Model
{
    protected static function booted()
    {
        // 全局作用域 - 所有查询自动应用
        static::addGlobalScope('open', function (Builder $builder) {
            $builder->where('is_open', 1);
        });
    }
}

// 使用
Pay::all();  // 自动添加 WHERE is_open = 1

// 移除全局作用域
Pay::withoutGlobalScope('open')->get();
Pay::withoutGlobalScopes()->get();  // 移除所有全局作用域
```

### 6.2 局部作用域

```php
class Pay extends Model
{
    // 局部作用域命名约定: scope + 驼峰命名
    public function scopeOpen($query)
    {
        return $query->where('is_open', 1);
    }

    public function scopeOfClient($query, $client)
    {
        return $query->where('pay_client', $client);
    }

    public function scopePriceAbove($query, $price)
    {
        return $query->where('price', '>', $price);
    }
}

// 使用
$openPays = Pay::open()->get();           // 获取所有开启的支付方式
$pcPays = Pay::ofClient(1)->get();        // 获取PC端支付方式
$expensivePays = Pay::priceAbove(100)->get();  // 价格大于 100

// 链式调用
$mobileOpenPays = Pay::open()->ofClient(2)->get();  // 开启的移动端支付方式
```

### 6.3 动态作用域

```php
class Pay extends Model
{
    public function scopeOfClient($query, $client)
    {
        if (is_array($client)) {
            return $query->whereIn('pay_client', $client);
        }
        return $query->where('pay_client', $client);
    }
}

// 使用
$pcOrAll = Pay::ofClient([Pay::PAY_CLIENT_PC, Pay::PAY_CLIENT_ALL])->get();
```

---

## 七、实战示例

### 7.1 完整的查询示例

```php
// 获取开启的PC端支付方式,按价格降序,前10条
$pays = Pay::query()
    ->where('is_open', Pay::STATUS_OPEN)
    ->whereIn('pay_client', [Pay::PAY_CLIENT_PC, Pay::PAY_CLIENT_ALL])
    ->orderBy('price', 'desc')
    ->take(10)
    ->get();

// 分页查询
$orders = Order::query()
    ->with('goods')  // 预加载商品
    ->where('status', 'paid')
    ->orderBy('created_at', 'desc')
    ->paginate(15);

// 统计查询
$stats = Order::query()
    ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(amount) as total')
    ->where('status', 'paid')
    ->whereBetween('created_at', ['2025-01-01', '2025-12-31'])
    ->groupBy('date')
    ->get();
```

### 7.2 事务操作

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $order = Order::create([
        'goods_id' => 1,
        'amount' => 100,
        'status' => 'paid',
    ]);

    $order->items()->create([
        'goods_id' => 1,
        'quantity' => 1,
        'price' => 100,
    ]);

    // 扣减库存
    Goods::find(1)->decrement('stock');
});

// 手动事务
DB::beginTransaction();
try {
    // 数据库操作
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### 7.3 查询构建器 vs ORM

```php
// 使用查询构建器 (返回 Collection 或 stdClass)
$data = DB::table('pays')
    ->where('is_open', 1)
    ->get();

// 使用 ORM (返回 Model 对象)
$data = Pay::where('is_open', 1)->get();

// ORM 的优势
$pay = Pay::find(1);
echo $pay->pay_name;  // 直接访问属性
$pay->pay_name = '新名称';
$pay->save();  // 方便的更新操作
```

---

## 八、最佳实践

### 8.1 安全性

```php
// 使用参数绑定,防止 SQL 注入
Pay::whereRaw('price > ?', [100])->get();

// 使用批量赋值保护
protected $fillable = ['pay_name', 'pay_check'];  // 白名单
protected $guarded = ['id', 'is_open'];           // 黑名单 (不推荐)
```

### 8.2 性能优化

```php
// 1. 使用预加载解决 N+1 问题
Order::with('goods')->get();  // ✅ 2 条查询
Order::all();                 // ❌ N+1 条查询

// 2. 只查询需要的字段
Order::select('id', 'order_no', 'amount')->get();

// 3. 使用 chunk 处理大数据
Pay::chunk(1000, function ($pays) {
    foreach ($pays as $pay) {
        // 处理逻辑
    }
});

// 4. 使用游标 (内存友好)
foreach (Pay::cursor() as $pay) {
    // 处理逻辑
}
```

### 8.3 代码可读性

```php
// 使用查询作用域替代重复代码
Pay::open()->ofClient(1)->get();  // ✅ 清晰
Pay::where('is_open', 1)->where('pay_client', 1)->get();  // ❌ 冗长

// 使用常量替代魔术数字
const PAY_CLIENT_PC = 1;
Pay::where('pay_client', Pay::PAY_CLIENT_PC)->get();  // ✅ 语义化
Pay::where('pay_client', 1)->get();  // ❌ 魔术数字
```

---

## 九、总结对比

| 特性 | 原生 SQL | ORM (Eloquent) |
|------|---------|----------------|
| 可读性 | 低 | 高 ✅ |
| 类型安全 | 无 | 有 ✅ |
| 可维护性 | 差 | 好 ✅ |
| 防止 SQL 注入 | 手动处理 | 自动处理 ✅ |
| 关联查询 | 复杂 JOIN | 简洁方法 ✅ |
| 跨数据库兼容性 | 差 | 好 ✅ |
| 学习成本 | 低 | 中 |
| 性能 | 高 | 略低 (可优化) |

**Laravel ORM 的核心理念**: 用面向对象的方式操作数据库,让开发者专注于业务逻辑而不是 SQL 语法!

---

## 十、相关资源

- [Laravel 官方文档 - Eloquent ORM](https://laravel.com/docs/eloquent)
- [Laravel 官方文档 - 查询构建器](https://laravel.com/docs/queries)
- [Laravel 官方文档 - 数据库配置](https://laravel.com/docs/database#configuration)

---

**文档版本**: v1.0
**最后更新**: 2025-01-01
**作者**: Claude Code
**项目**: 独角兽卡网 (dujiaoka)
