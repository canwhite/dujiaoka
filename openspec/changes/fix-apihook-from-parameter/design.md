# Design: Fix ApiHook From Parameter Capture

## Overview
本文档详细说明如何修复订单创建时 `from` 参数丢失的问题，确保第三方充值接口能被正确调用。

## Current Architecture

### Data Flow (Broken)
```
┌─────────────────┐
│ Novel Website  │
│ (127.0.0.1:3000)│
└────────┬────────┘
         │ 1. User clicks buy (URL: ?from=novel)
         ▼
┌─────────────────┐
│ Dujiaoka        │
│ OrderController │
│ ::createOrder() │
└────────┬────────┘
         │ 2. Extract user input (account, etc.)
         │ ❌ MISSING: No from extraction
         ▼
┌─────────────────┐
│ OrderProcess    │
│ Service         │
│ ::setOtherIpt() │
└────────┬────────┘
         │ 3. Save only user input
         ▼
┌─────────────────┐
│ orders table    │
│ info column     │
│ "充值账号: xxx" │
│ ❌ No from      │
└────────┬────────┘
         │ 4. Payment success
         ▼
┌─────────────────┐
│ ApiHook Job     │
│ ::handle()      │
└────────┬────────┘
         │ 5. Try extract from: preg_match('/来源[:\s]+.../')
         │ Result: $from = '' (empty)
         ▼
┌─────────────────┐
│ sendDefault     │
│ ApiHook()       │
│ ❌ Wrong API    │
└─────────────────┘
```

### Problem Location

**File**: `app/Http/Controllers/Home/OrderController.php:57-92`

```php
public function createOrder(Request $request)
{
    DB::beginTransaction();
    try {
        // ... validation ...

        // ⚠️ ISSUE: from parameter exists in $request but not extracted
        $otherIpt = $this->orderService->validatorChargeInput($goods, $request);
        $this->orderProcessService->setOtherIpt($otherIpt); // ← Only user input

        // ... rest of creation ...
    }
}
```

## Proposed Solution

### Fixed Data Flow
```
┌─────────────────┐
│ Novel Website  │
│ (127.0.0.1:3000)│
└────────┬────────┘
         │ 1. User clicks buy (URL: ?from=novel)
         ▼
┌─────────────────┐
│ Dujiaoka        │
│ OrderController │
│ ::createOrder() │
└────────┬────────┘
         │ 2. Extract user input (account, etc.)
         │ ✅ NEW: Extract and append from parameter
         ▼
┌─────────────────┐
│ OrderProcess    │
│ Service         │
│ ::setOtherIpt() │
└────────┬────────┘
         │ 3. Save user input + from
         ▼
┌─────────────────┐
│ orders table    │
│ info column     │
│ "充值账号: xxx  │
│  来源: novel"   │
│ ✅ Complete     │
└────────┬────────┘
         │ 4. Payment success
         ▼
┌─────────────────┐
│ ApiHook Job     │
│ ::handle()      │
└────────┬────────┘
         │ 5. Extract from: preg_match('/来源[:\s]+.../')
         │ Result: $from = 'novel'
         ▼
┌─────────────────┐
│ callNovelApi()  │
│ ✅ Correct API  │
└────────┬────────┘
         │ 6. POST to NOVEL_API_URL
         ▼
┌─────────────────┐
│ Novel Website  │
│ API Endpoint    │
│ ✅ Token sent   │
└─────────────────┘
```

## Implementation Details

### Change 1: OrderController

**File**: `app/Http/Controllers/Home/OrderController.php`
**Method**: `createOrder()`
**Location**: Line 70 (after `validatorChargeInput()`)

**Before**:
```php
$otherIpt = $this->orderService->validatorChargeInput($goods, $request);
$this->orderProcessService->setOtherIpt($otherIpt);
```

**After**:
```php
$otherIpt = $this->orderService->validatorChargeInput($goods, $request);

// ⭐ 追加 from 参数到订单详情
if ($request->has('from') && !empty($request->input('from'))) {
    $from = $request->input('from');
    $otherIpt .= "\n来源: " . $from;
}

$this->orderProcessService->setOtherIpt($otherIpt);
```

**Rationale**:
1. **Minimal change**: Only 4 lines added, no existing logic modified
2. **Safe**: Checks if `from` exists and is not empty before appending
3. **Explicit**: Uses clear format "来源: {from}" that matches ApiHook's regex
4. **Backward compatible**: Orders without `from` continue to work as before

### Change 2: (Optional) OrderService Helper

**File**: `app/Service/OrderService.php`
**Location**: After `validatorChargeInput()` method

**New Method**:
```php
/**
 * 追加来源信息到订单详情
 *
 * @param string $otherIpt 订单详情
 * @param string|null $from 来源标识
 * @return string
 */
public function appendFromToOrderInfo(string $otherIpt, ?string $from): string
{
    if (empty($from)) {
        return $otherIpt;
    }
    return $otherIpt . "\n来源: " . trim($from);
}
```

**Usage in OrderController**:
```php
$otherIpt = $this->orderService->validatorChargeInput($goods, $request);
$otherIpt = $this->orderService->appendFromToOrderInfo(
    $otherIpt,
    $request->input('from')
);
$this->orderProcessService->setOtherIpt($otherIpt);
```

**Rationale**:
- Encapsulates the logic in a service method
- Easier to test
- More reusable if needed elsewhere
- Type hints and null safety

**Decision**: **Optional** - Direct implementation in OrderController is sufficient for current needs.

## Alternative Approaches

### Alternative 1: Store from in Separate Column
**Approach**: Add `from` column to `orders` table

**Pros**:
- Cleaner separation of concerns
- Easier to query by from
- Type-safe (enum or foreign key)

**Cons**:
- Requires database migration
- More invasive change
- Overkill for single use case

**Decision**: ❌ Rejected - Not worth the complexity

### Alternative 2: Pass from via Session
**Approach**: Store from in session when user arrives, retrieve on order creation

**Pros**:
- No URL parameter pollution
- Can validate from early

**Cons**:
- Session dependency
- More complex flow
- Doesn't work if session expires

**Decision**: ❌ Rejected - URL parameter is simpler

### Alternative 3: Validate from Against Whitelist
**Approach**: Only allow specific from values (novel, game, vip, etc.)

**Pros**:
- Security: prevents arbitrary from values
- Enforces contract

**Cons**:
- Requires configuration
- Less flexible
- Additional validation logic

**Decision**: ❌ Deferred - Can add later if needed

## Edge Cases and Validation

### Edge Case 1: No from Parameter
**Scenario**: User accesses order page directly without `?from=`

**Handling**:
```php
if ($request->has('from') && !empty($request->input('from'))) {
    // Only append if from exists
}
```

**Result**: Order created without from, ApiHook falls back to default behavior

### Edge Case 2: Empty from Parameter
**Scenario**: URL is `?from=` (empty string)

**Handling**:
```php
!empty($request->input('from')) // Check prevents appending
```

**Result**: Same as no from parameter

### Edge Case 3: Multiple from Values
**Scenario**: URL is `?from=novel&from=game` (PHP array behavior)

**Handling**: `$request->input('from')` returns last value

**Result**: Uses last from value (acceptable)

### Edge Case 4: Special Characters in from
**Scenario**: URL is `?from=novel-site` or `?from=no vel`

**Handling**:
```php
$otherIpt .= "\n来源: " . $from; // No sanitization
```

**ApiHook Extraction**:
```php
preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches);
// Extracts until first space or newline
```

**Result**: Works correctly for most cases

### Edge Case 5: Case Sensitivity
**Scenario**: `?from=Novel` vs `?from=novel`

**ApiHook Routing**:
```php
switch ($from) {
    case 'novel': // Strict match
```

**Result**: `Novel` would fall through to default behavior

**Potential Improvement**: Case-insensitive matching or normalize to lowercase

## Testing Strategy

### Unit Test: From Parameter Capture
```php
public function test_from_parameter_is_saved_to_order_info()
{
    $request = new Request([
        'gid' => 1,
        'email' => 'test@example.com',
        'payway' => 1,
        'by_amount' => 1,
        'from' => 'novel',
        'account' => 'user123'
    ]);

    $controller = new OrderController();
    $controller->createOrder($request);

    $order = Order::where('email', 'test@example.com')->first();
    $this->assertStringContainsString('来源: novel', $order->info);
}
```

### Integration Test: ApiHook Calls Correct API
```php
public function test_apihook_routes_to_novel_api_when_from_is_novel()
{
    $order = Order::factory()->create([
        'info' => "充值账号: test@example.com\n来源: novel",
        'status' => Order::STATUS_PENDING,
        'actual_price' => 10.00
    ]);

    Http::fake([
        env('NOVEL_API_URL') => Http::response(['success' => true], 200),
    ]);

    $job = new ApiHook($order);
    $job->handle();

    Http::assertSent(function ($request) {
        return $request->url() === env('NOVEL_API_URL') &&
               $request['email'] === 'test@example.com' &&
               $request['order_sn'] === $order->order_sn;
    });
}
```

### Manual Test: Complete Flow
See `tasks.md` T-2 through T-6

## Security Considerations

### Input Validation
**Current**: No validation on `from` parameter

**Risks**:
1. **SQL Injection**: ❌ Not possible - Laravel ORM protects
2. **XSS**: ❌ Not applicable - `from` goes to database and backend API only
3. **Log Injection**: ⚠️ Possible if from contains newlines (handled by trim)

**Recommendation**: Add whitelist validation if needed:
```php
$allowedFrom = ['novel', 'game', 'vip', 'app'];
if (in_array($from, $allowedFrom)) {
    $otherIpt .= "\n来源: " . $from;
}
```

### Access Control
**Current**: Anyone can add any `from` parameter

**Risks**:
- User could pretend to be from novel site to get novel redirect
- Low impact - only affects redirect URL and API called

**Recommendation**: Acceptable for current use case

## Performance Impact

### Additional Operations
- One string concatenation: `$otherIpt .= "\n来源: " . $from`
- One conditional check: `if ($request->has('from'))`

### Benchmark
```php
// Before
$otherIpt = "充值账号: user123"; // ~20 bytes

// After
$otherIpt = "充值账号: user123\n来源: novel"; // ~32 bytes
```

**Impact**: Negligible - adds ~12 bytes to order info

## Rollback Plan

### If Issue Detected
1. **Immediate**: Remove the 4 lines added to OrderController
2. **Validation**:
   ```php
   // Test orders no longer contain from
   $order = Order::latest()->first();
   strpos($order->info, '来源:') === false
   ```
3. **Data**: No cleanup needed - existing orders with from are harmless

### Rollback Command
```bash
git checkout HEAD~1 app/Http/Controllers/Home/OrderController.php
php artisan cache:clear
```

## Success Metrics

### Technical Metrics
- ✅ All automated tests pass
- ✅ No increase in error rate
- ✅ API response time unchanged (< 100ms)

### Business Metrics
- ✅ Token recharge success rate > 95%
- ✅ User complaints about missing tokens = 0
- ✅ Completion rate for novel site orders > 90%
