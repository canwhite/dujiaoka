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
        $goodInfo = $this->goodsService->detail($this->order->goods_id);
        // 判断是否有配置支付回调
        if(empty($goodInfo->api_hook)){
            return;
        }

        // ⭐ 从订单info中提取from参数
        $from = '';
        if (!empty($this->order->info)) {
            if (preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
                $from = $matches[1];
            }
        }

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
        // 如果from为空，使用默认API地址
        if (empty($from)) {
            $this->sendDefaultApiHook($goodInfo);
            return;
        }

        // ⭐⭐⭐ 根据from调用不同的API
        switch ($from) {
            case 'novel':
                // 小说网站充值API
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
                // 其他情况，使用默认API回调
                $this->sendDefaultApiHook($goodInfo);
                break;
        }
    }

    /**
     * ⭐ 调用小说充值API
     */
    private function callNovelApi($goodInfo)
    {
        $apiUrl = env('NOVEL_API_URL', '');

        if (empty($apiUrl)) {
            return;
        }

        // 从订单info中提取邮箱
        $email = '';
        if (!empty($this->order->info)) {
            if (preg_match('/充值账号[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
                $email = $matches[1];
            }
        }

        $postdata = [
            'email' => $email,
            'order_sn' => $this->order->order_sn,
            'amount' => $this->order->actual_price,
            'good_name' => $goodInfo->gd_name,
            'timestamp' => time()
        ];

        $this->sendPostRequest($apiUrl, $postdata);
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

        $this->sendPostRequest($goodInfo->api_hook, $postdata);
    }

    /**
     * ⭐ 发送POST请求的通用方法
     * @param string $url API地址
     * @param array $data POST数据
     */
    private function sendPostRequest($url, $data)
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

            // 记录日志（可选）
            if ($result === false) {
                \Log::error('API Hook请求失败', [
                    'url' => $url,
                    'data' => $data,
                    'error' => error_get_last()
                ]);
            } else {
                \Log::info('API Hook请求成功', [
                    'url' => $url,
                    'response' => $result
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('API Hook异常', [
                'url' => $url,
                'data' => $data,
                'exception' => $e->getMessage()
            ]);
        }
    }
}
