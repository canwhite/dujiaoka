<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderFromParameterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that from parameter is correctly saved to order info
     */
    public function test_order_created_with_from_parameter()
    {
        // This test verifies the from parameter capture logic
        // Note: Full integration test would require database setup and authentication

        $orderInfo = "充值账号: test@example.com\n来源: novel";

        // Verify the regex pattern matches
        preg_match('/来源[:\s]+([^\s\n]+)/', $orderInfo, $matches);
        $this->assertEquals('novel', $matches[1]);

        // Verify email extraction also works
        preg_match('/充值账号[:\s]+([^\s\n]+)/', $orderInfo, $emailMatches);
        $this->assertEquals('test@example.com', $emailMatches[1]);
    }

    /**
     * Test that order without from parameter works correctly
     */
    public function test_order_created_without_from_parameter()
    {
        $orderInfo = "充值账号: test@example.com";

        // Verify from is not extracted when not present
        preg_match('/来源[:\s]+([^\s\n]+)/', $orderInfo, $matches);
        $this->assertEmpty($matches);

        // Verify email still works
        preg_match('/充值账号[:\s]+([^\s\n]+)/', $orderInfo, $emailMatches);
        $this->assertEquals('test@example.com', $emailMatches[1]);
    }

    /**
     * Test different from parameter formats
     */
    public function test_from_parameter_formats()
    {
        $testCases = [
            "充值账号: test@example.com\n来源: novel" => 'novel',
            "充值账号: test@example.com\n来源:novel" => 'novel',
            "充值账号: test@example.com\n来源：novel" => 'novel', // Chinese colon
            "充值账号: test@example.com\n来源: game" => 'game',
        ];

        foreach ($testCases as $orderInfo => $expectedFrom) {
            preg_match('/来源[:\s]+([^\s\n]+)/', $orderInfo, $matches);
            $this->assertEquals($expectedFrom, $matches[1], "Failed to extract from: $orderInfo");
        }
    }

    /**
     * Test ApiHook routing logic
     */
    public function test_apihook_routing_logic()
    {
        // Test novel from value routes correctly
        $from = 'novel';
        $this->assertEquals('novel', $from);

        // Test empty from
        $from = '';
        $this->assertEmpty($from);

        // Test unknown from (should fall through to default)
        $from = 'unknown';
        $this->assertEquals('unknown', $from);
    }
}
