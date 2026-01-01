# Tasks: ApiHook Payment Callback and Redirect Validation

## Overview
本文档列出了验证和改进 ApiHook 支付回调与重定向流程的任务清单。

## Validation Tasks (Completed)

- [x] **V-1**: Review ApiHook payment callback implementation
  - Location: `app/Jobs/ApiHook.php`
  - Status: ✅ Correct implementation
  - Notes: Queue-based async processing, proper error handling

- [x] **V-2**: Review OrderProcessService integration
  - Location: `app/Service/OrderProcessService.php:432`
  - Status: ✅ Correct integration
  - Notes: ApiHook dispatched after DB commit

- [x] **V-3**: Review PayController render override
  - Location: `app/Http/Controllers/PayController.php:165-177`
  - Status: ✅ Correct implementation
  - Notes: Properly extracts and passes from/redirect_urls

- [x] **V-4**: Review frontend polling logic (Unicorn theme)
  - Location: `resources/views/unicorn/static_pages/qrpay.blade.php`
  - Status: ✅ Correct implementation
  - Notes: 5s polling interval, 3s redirect delay

- [x] **V-5**: Review frontend polling logic (Luna theme)
  - Location: `resources/views/luna/static_pages/qrpay.blade.php`
  - Status: ✅ Correct implementation
  - Notes: Enhanced user feedback with layer.alert

- [x] **V-6**: Trace complete data flow
  - Status: ✅ Flow validated
  - Notes: From order creation → payment → API call → redirect

## Configuration Tasks (Required)

- [ ] **C-1**: Configure NOVEL_API_URL environment variable
  - Priority: **CRITICAL**
  - File: `.env`
  - Action: Add `NOVEL_API_URL=http://127.0.0.1:3000/api/recharge`
  - Validation: Check logs for "NOVEL_API_URL not configured" warning
  - Impact: Without this, API calls will be silently skipped

- [ ] **C-2**: Configure NOVEL_REDIRECT_URL (optional but recommended)
  - Priority: **HIGH**
  - Current: Hardcoded in `PayController.php:171`
  - Recommended: Move to environment variable
  - Action Steps:
    1. Add to `.env`: `NOVEL_REDIRECT_URL=http://127.0.0.1:3000`
    2. Modify `PayController.php:171`:
       ```php
       $data['redirect_urls'] = [
           'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
       ];
       ```

## Testing Tasks

### Manual Testing

- [ ] **T-1**: Test normal payment flow
  - Precondition: Novel website running on 127.0.0.1:3000
  - Precondition: NOVEL_API_URL configured
  - Steps:
    1. Create order from novel website (with "来源: novel")
    2. Complete payment
    3. Verify API called successfully (check Laravel logs)
    4. Verify redirect to 127.0.0.1:3000 after 3 seconds
  - Expected: API called, user redirected
  - Actual: ___________

- [ ] **T-2**: Test missing from parameter
  - Steps:
    1. Create order without "来源: novel" in info
    2. Complete payment
    3. Observe redirect behavior
  - Expected: Redirects to order detail page (default)
  - Actual: ___________

- [ ] **T-3**: Test API URL not configured
  - Steps:
    1. Remove or comment out NOVEL_API_URL
    2. Create order with "来源: novel"
    3. Complete payment
    4. Check logs
  - Expected: Warning "NOVEL_API_URL not configured"
  - Actual: ___________

- [ ] **T-4**: Test API timeout
  - Steps:
    1. Configure NOVEL_API_URL to unreachable endpoint
    2. Complete payment
    3. Wait for queue processing
    4. Check queue job attempts
  - Expected: 2 attempts (initial + 1 retry), error logged
  - Actual: ___________

- [ ] **T-5**: Test idempotency (duplicate payment callback)
  - Steps:
    1. Complete payment (order status = success)
    2. Manually trigger payment callback again
    3. Check order status and API calls
  - Expected: Second callback rejected with "order already completed"
  - Actual: ___________

- [ ] **T-6**: Test different order info formats
  - Test cases:
    - "充值账号: test@example.com\n来源: novel" (normal)
    - "来源: novel\n充值账号: test@example.com" (reversed)
    - "来源:novel" (no space)
    - "来源：novel" (Chinese colon)
  - Expected: All variations should work
  - Actual: ___________

### Automated Testing

- [ ] **T-7**: Write integration test for ApiHook
  - File: `tests/Feature/ApiHookTest.php`
  - Content:
    ```php
    <?php

    namespace Tests\Feature;

    use App\Models\Order;
    use App\Jobs\ApiHook;
    use Illuminate\Support\Facades\Http;
    use Tests\TestCase;

    class ApiHookTest extends TestCase
    {
        public function test_novel_api_hook_with_valid_order()
        {
            // Arrange
            $order = Order::factory()->create([
                'info' => "充值账号: test@example.com\n来源: novel",
                'status' => Order::STATUS_PENDING,
                'actual_price' => 10.00,
            ]);

            Http::fake([
                env('NOVEL_API_URL') => Http::response(['success' => true], 200),
            ]);

            // Act
            $job = new ApiHook($order);
            $job->handle();

            // Assert
            Http::assertSent(function ($request) {
                return $request->url() === env('NOVEL_API_URL') &&
                       $request['email'] === 'test@example.com' &&
                       isset($request['order_sn']) &&
                       isset($request['timestamp']);
            });

            $this->assertDatabaseHas('logs', [
                'level' => 'info',
                'message' => 'API Hook请求成功',
            ]);
        }

        public function test_novel_api_hook_without_api_url()
        {
            // Arrange
            config(['app.env' => 'testing']);
            $order = Order::factory()->create([
                'info' => "充值账号: test@example.com\n来源: novel",
            ]);

            // Act
            $job = new ApiHook($order);
            $job->handle();

            // Assert - should not throw exception
            $this->assertTrue(true);
        }

        public function test_extract_from_parameter()
        {
            $order = Order::factory()->create([
                'info' => "充值账号: test@example.com\n来源: novel",
            ]);

            $job = new ApiHook($order);
            $job->handle();

            // Verify from parameter is extracted correctly
            $this->assertNotNull($order->info);
        }
    }
    ```
  - Run: `php artisan test --filter ApiHookTest`

- [ ] **T-8**: Write integration test for redirect logic
  - File: `tests/Feature/PaymentRedirectTest.php`
  - Content:
    ```php
    <?php

    namespace Tests\Feature;

    use App\Models\Order;
    use Tests\TestCase;

    class PaymentRedirectTest extends TestCase
    {
        public function test_qrpay_page_includes_from_parameter()
        {
            $order = Order::factory()->create([
                'info' => "来源: novel",
            ]);

            $response = $this->get("/pay/qrpay/{$order->order_sn}");

            $response->assertStatus(200);
            $response->assertViewHas('from', 'novel');
            $response->assertViewHas('redirect_urls');
        }

        public function test_qrpay_page_without_from_parameter()
        {
            $order = Order::factory()->create([
                'info' => "Some other info",
            ]);

            $response = $this->get("/pay/qrpay/{$order->order_sn}");

            $response->assertStatus(200);
            $response->assertViewHas('from', '');
        }
    }
    ```
  - Run: `php artisan test --filter PaymentRedirectTest`

## Improvement Tasks (Optional)

### High Priority

- [ ] **I-1**: Add API signature authentication
  - Priority: HIGH (security)
  - Location: `app/Jobs/ApiHook.php`
  - Implementation:
    ```php
    private function callNovelApi($goodInfo)
    {
        // ... existing code ...

        $secret = env('NOVEL_API_SECRET');
        $timestamp = time();
        $sign = md5($email . $orderSN . $timestamp . $secret);

        $postdata = [
            'email' => $email,
            'order_sn' => $this->order->order_sn,
            'amount' => $this->order->actual_price,
            'timestamp' => $timestamp,
            'sign' => $sign,
        ];

        $this->sendPostRequest($apiUrl, $postdata);
    }
    ```
  - Novel website needs to verify signature

- [ ] **I-2**: Add failure alerting
  - Priority: HIGH (monitoring)
  - Implementation:
    ```php
    // In ApiHook::sendPostRequest()
    if ($result === false) {
        \Log::error('API Hook请求失败', [...]);

        // Send alert
        if (dujiaoka_config_get('is_open_apihook_alert', 0) == 1) {
            MailSend::dispatch(
                dujiaoka_config_get('manage_email'),
                'API Hook 失败告警',
                "订单 {$this->order->order_sn} API 调用失败"
            );
        }
    }
    ```

### Medium Priority

- [ ] **I-3**: Replace file_get_contents with HTTP client
  - Priority: MEDIUM (code quality)
  - Location: `app/Jobs/ApiHook.php:208`
  - Implementation:
    ```php
    use Illuminate\Support\Facades\Http;

    private function sendPostRequest($url, $data)
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $data);

            if ($response->successful()) {
                \Log::info('API Hook请求成功', [
                    'response' => $response->body()
                ]);
            } else {
                \Log::error('API Hook返回错误', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('API Hook异常', [
                'exception' => $e->getMessage()
            ]);
            throw $e; // Trigger queue retry
        }
    }
    ```

- [ ] **I-4**: Add configurable polling interval
  - Priority: MEDIUM (flexibility)
  - Location: Frontend templates
  - Implementation:
    ```php
    // PayController.php
    $data['polling_interval'] = dujiaoka_config_get('payment_polling_interval', 5000);

    // qrpay.blade.php
    var timer = window.setInterval(function(){
        $.ajax(getting)
    }, {{ $polling_interval }});
    ```

- [ ] **I-5**: Add redirect URL parameter support
  - Priority: MEDIUM (UX)
  - Location: Frontend templates
  - Implementation:
    ```javascript
    // Pass order info back to novel website
    if (from && redirectUrls[from]) {
        redirectUrl = redirectUrls[from] + '?order=' + orderSN + '&status=success';
    }
    ```

### Low Priority

- [ ] **I-6**: Consider WebSocket for real-time updates
  - Priority: LOW (optimization)
  - Research: Laravel Echo + Pusher/Soketi
  - Benefits: Instant notification, no polling overhead

- [ ] **I-7**: Add API response validation
  - Priority: LOW (robustness)
  - Implementation:
    ```php
    $response = json_decode($result, true);
    if (!isset($response['success']) || !$response['success']) {
        throw new \Exception('API返回失败状态');
    }
    ```

## Documentation Tasks

- [ ] **D-1**: Create API documentation
  - File: `docs/novel-api-integration.md`
  - Content:
    - API endpoint specification
    - Request/response format
    - Authentication method
    - Error codes
    - Integration examples

- [ ] **D-2**: Update project README
  - Section: "第三方平台集成"
  - Content: Novel website integration guide

- [ ] **D-3**: Create troubleshooting guide
  - File: `docs/apihook-troubleshooting.md`
  - Common issues:
    - API URL not configured
    - API timeout failures
    - Redirect not working
    - Queue not processing

## Deployment Checklist

- [ ] **Deploy-1**: Backup current code
  ```bash
  git add .
  git commit -m "backup before apihook validation"
  ```

- [ ] **Deploy-2**: Configure production environment
  ```bash
  # .env on production
  NOVEL_API_URL=https://novel-website.com/api/recharge
  NOVEL_REDIRECT_URL=https://novel-website.com
  NOVEL_API_SECRET=your-secret-key
  ```

- [ ] **Deploy-3**: Clear cache
  ```bash
  php artisan config:clear
  php artisan cache:clear
  php artisan view:clear
  ```

- [ ] **Deploy-4**: Restart queue workers
  ```bash
  php artisan queue:restart
  supervisorctl restart dujiaoka-queue:*
  ```

- [ ] **Deploy-5**: Test in production
  - Create test order
  - Verify API call
  - Verify redirect
  - Check logs

## Monitoring Setup

- [ ] **Monitor-1**: Add Laravel logging monitoring
  - Tool: Laravel Log Viewer or external service
  - Alert on: "API Hook请求失败" errors

- [ ] **Monitor-2**: Add queue monitoring
  - Command: `php artisan queue:monitor`
  - Alert on: Queue depth > 100

- [ ] **Monitor-3**: Add uptime monitoring
  - Tool: UptimeRobot or similar
  - Monitor: NOVEL_API_URL availability

- [ ] **Monitor-4**: Add business metrics
  - Metrics:
    - Daily API call volume
    - Success/failure rate
    - Average response time
    - Redirect success rate

## Sign-off

- [ ] **Validation Complete**: All validation tasks (V-1 to V-6) passed
- [ ] **Configuration Complete**: All required configuration (C-1, C-2) done
- [ ] **Testing Complete**: All manual tests (T-1 to T-6) passed
- [ ] **Deployment Complete**: Production deployment successful
- [ ] **Monitoring Setup**: Monitoring tools configured

**Approved By**: ___________
**Date**: ___________
**Environment**: [ ] Development [ ] Staging [ ] Production
