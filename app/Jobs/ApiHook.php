<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApiHook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * @var Order
     */
    private $order;

    /**
     * 商品服务层.
     * @var \App\Service\PayService
     */
    private $goodsService;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->goodsService = app('Service\GoodsService');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \Log::info('API Hook任务开始执行', [
            'order_sn' => $this->order->order_sn,
            'goods_id' => $this->order->goods_id,
            'order_status' => $this->order->status
        ]);

        $goodInfo = $this->goodsService->detail($this->order->goods_id);

        // ⭐ 先提取from参数
        $from = '';
        if (!empty($this->order->info)) {
            if (preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
                $from = $matches[1];
            }
        }

        \Log::info('API Hook提取from参数', [
            'order_sn' => $this->order->order_sn,
            'from' => $from ?: '(空，将检查api_hook)',
            'order_info' => $this->order->info
        ]);

        // ⭐⭐⭐ 根据from参数进行不同的API请求
        $this->callApiByFrom($from, $goodInfo);
    }

    /**
     * ⭐ 根据from参数调用不同的API
     * @param string $from 来源标识
     * @param object $goodInfo 商品信息
     */
    private function callApiByFrom($from, $goodInfo)
    {
        // 如果from为空，检查是否配置了api_hook
        if (empty($from)) {
            \Log::info('API Hook路由：from参数为空，检查api_hook配置', [
                'order_sn' => $this->order->order_sn,
                'api_hook' => $goodInfo->api_hook ?? '(未配置)'
            ]);

            if (empty($goodInfo->api_hook)) {
                \Log::info('商品未配置API Hook，跳过', [
                    'order_sn' => $this->order->order_sn,
                    'goods_id' => $this->order->goods_id
                ]);
                return;
            }

            $this->sendDefaultApiHook($goodInfo);
            return;
        }

        // ⭐⭐⭐ 根据from调用不同的API（转换为小写，避免大小写问题）
        $fromLower = strtolower($from);

        \Log::info('API Hook路由：根据from参数选择API', [
            'order_sn' => $this->order->order_sn,
            'from_original' => $from,
            'from_lower' => $fromLower,
            'api_type' => $fromLower
        ]);

        switch ($fromLower) {
            case 'novel':
                // 小说网站充值API（不需要检查api_hook）
                $this->callNovelApi($goodInfo);
                break;

            // case 'game':
            //     // 游戏网站充值API
            //     $this->callGameApi($goodInfo);
            //     break;

            // case 'vip':
            //     // VIP会员充值API
            //     $this->callVipApi($goodInfo);
            //     break;

            // case 'app':
            //     // 移动应用充值API
            //     $this->callAppApi($goodInfo);
            //     break;

            default:
                // 其他情况，检查api_hook配置
                \Log::info('API Hook路由：未识别的from参数，检查api_hook配置', [
                    'order_sn' => $this->order->order_sn,
                    'from' => $from,
                    'api_hook' => $goodInfo->api_hook ?? '(未配置)'
                ]);

                if (empty($goodInfo->api_hook)) {
                    \Log::info('商品未配置API Hook，跳过', [
                        'order_sn' => $this->order->order_sn,
                        'goods_id' => $this->order->goods_id
                    ]);
                    return;
                }

                $this->sendDefaultApiHook($goodInfo);
                break;
        }
    }

    /**
     * ⭐ 调用小说充值API
     */
    private function callNovelApi($goodInfo)
    {
        \Log::info('调用小说充值API', [
            'order_sn' => $this->order->order_sn,
            'goods_id' => $goodInfo->id,
            'goods_name' => $goodInfo->gd_name
        ]);

        $apiUrl = env('NOVEL_API_URL', '');

        if (empty($apiUrl)) {
            \Log::warning('NOVEL_API_URL未配置，无法调用小说充值API', [
                'order_sn' => $this->order->order_sn,
                'goods_id' => $goodInfo->id
            ]);
            return;
        }

        // 从订单info中提取充值账号
        $email = '';
        if (!empty($this->order->info)) {
            if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
                $email = $matches[1];
                \Log::info('成功提取充值账号', [
                    'order_sn' => $this->order->order_sn,
                    'account' => $email,
                    'source' => 'order_info'
                ]);
            }
        }

        // ⭐ 如果提取失败，使用订单邮箱作为备用方案
        if (empty($email)) {
            \Log::info('未提取到充值账号，使用订单邮箱作为备用方案', [
                'order_sn' => $this->order->order_sn,
                'order_info' => $this->order->info,
                'order_email' => $this->order->email
            ]);
            $email = $this->order->email;
        }

        // 再次验证邮箱不为空
        if (empty($email)) {
            \Log::error('充值账号为空，无法调用充值API', [
                'order_sn' => $this->order->order_sn,
                'order_info' => $this->order->info
            ]);
            return;
        }

        $postdata = [
            'email' => $email,
            'order_sn' => $this->order->order_sn,
            'amount' => $this->order->actual_price,
            'good_name' => $goodInfo->gd_name,
            'timestamp' => time()
        ];

        \Log::info('准备发送小说充值API请求', [
            'order_sn' => $this->order->order_sn,
            'api_url' => $apiUrl,
            'request_data' => $postdata
        ]);

        $this->sendPostRequest($apiUrl, $postdata, 'novel');
    }

    /**
     * ⭐ 调用游戏充值API
     */
    // private function callGameApi($goodInfo)
    // {
    //     $apiUrl = env('GAME_API_URL', '');

    //     if (empty($apiUrl)) {
    //         return;
    //     }

    //     // 从订单info中提取游戏账号
    //     $gameAccount = '';
    //     if (!empty($this->order->info)) {
    //         if (preg_match('/游戏账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
    //             $gameAccount = $matches[1];
    //         }
    //     }

    //     $postdata = [
    //         'game_account' => $gameAccount,
    //         'order_sn' => $this->order->order_sn,
    //         'amount' => $this->order->actual_price,
    //         'good_name' => $goodInfo->gd_name,
    //         'timestamp' => time()
    //     ];

    //     $this->sendPostRequest($apiUrl, $postdata);
    // }


    /**
     * ⭐ 发送默认API回调（商品配置的api_hook）
     */
    private function sendDefaultApiHook($goodInfo)
    {
        if (empty($goodInfo->api_hook)) {
            return;
        }

        $postdata = [
            'title' => $this->order->title,
            'order_sn' => $this->order->order_sn,
            'email' => $this->order->email,
            'actual_price' => $this->order->actual_price,
            'order_info' => $this->order->info,
            'good_id' => $goodInfo->id,
            'gd_name' => $goodInfo->gd_name
        ];

        $this->sendPostRequest($goodInfo->api_hook, $postdata, 'default');
    }

    /**
     * ⭐ 发送POST请求的通用方法
     * @param string $url API地址
     * @param array $data POST数据
     * @param string $type API类型 (用于日志记录)
     */
    private function sendPostRequest($url, $data, $type = 'default')
    {
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-type: application/json',
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'timeout' => 30  // 30秒超时
            ]
        ];

        $context = stream_context_create($opts);

        try {
            $result = @file_get_contents($url, false, $context);

            // HTTP请求失败
            if ($result === false) {
                $error = error_get_last();
                \Log::error('API Hook HTTP请求失败', [
                    'type' => $type,
                    'url' => $url,
                    'order_sn' => $this->order->order_sn,
                    'error' => $error['message'] ?? 'Unknown error'
                ]);
                return;
            }

            // ⭐ 解析并验证响应的业务状态
            $response = json_decode($result, true);

            // 如果是第三方充值API，验证业务状态
            if ($type !== 'default') {
                if (!$response || !isset($response['success'])) {
                    \Log::error('API Hook返回格式错误', [
                        'type' => $type,
                        'url' => $url,
                        'order_sn' => $this->order->order_sn,
                        'response' => $result
                    ]);
                    return;
                }

                // 检查业务状态
                if (!$response['success']) {
                    \Log::error('API Hook业务失败', [
                        'type' => $type,
                        'url' => $url,
                        'order_sn' => $this->order->order_sn,
                        'response' => $response,
                        'message' => $response['message'] ?? 'Unknown business error'
                    ]);
                    return;
                }

                // ✅ 充值成功
                \Log::info('API Hook充值成功', [
                    'type' => $type,
                    'url' => $url,
                    'order_sn' => $this->order->order_sn,
                    'response' => $response
                ]);
            } else {
                // 默认API回调，只记录HTTP请求成功
                \Log::info('API Hook默认回调请求成功', [
                    'url' => $url,
                    'order_sn' => $this->order->order_sn,
                    'response' => $result
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('API Hook异常', [
                'type' => $type,
                'url' => $url,
                'order_sn' => $this->order->order_sn,
                'exception' => $e->getMessage()
            ]);
        }
    }
}
