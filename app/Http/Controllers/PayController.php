<?php

namespace App\Http\Controllers;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Service\OrderProcessService;

class PayController extends BaseController
{

    /**
     * 支付网关
     * @var \App\Models\Pay
     */
    protected $payGateway;


    /**
     * 订单
     * @var \App\Models\Order
     */
    protected $order;

    /**
     * 订单服务层
     * @var \App\Service\OrderService
     */
    protected $orderService;

    /**
     * 支付服务层
     * @var \App\Service\PayService
     */
    protected $payService;

    /**
     * 订单处理层.
     * @var OrderProcessService
     */
    protected $orderProcessService;


    public function __construct()
    {
        $this->orderService = app('Service\OrderService');
        $this->payService = app('Service\PayService');
        $this->orderProcessService = app('Service\OrderProcessService');
    }

    /**
     * 订单检测
     *
     * @param string $orderSN
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function checkOrder(string $orderSN)
    {
        // 订单
        $this->order = $this->orderService->detailOrderSN($orderSN);
        if (!$this->order) {
            throw new RuleValidationException(__('dujiaoka.prompt.order_does_not_exist'));
        }
        // 订单过期
        if ($this->order->status == Order::STATUS_EXPIRED) {
            throw new RuleValidationException(__('dujiaoka.prompt.order_is_expired'));
        }
        // 已经支付了
        if ($this->order->status > Order::STATUS_WAIT_PAY) {
            throw new RuleValidationException(__('dujiaoka.prompt.order_already_paid'));
        }
    }

    /**
     * 加载支付网关
     *
     * @param string $orderSN 订单号
     * @param string $payCheck 支付标识
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function loadGateWay(string $orderSN, string $payCheck)
    {
        $this->checkOrder($orderSN);
        // 支付配置
        $this->payGateway = $this->payService->detailByCheck($payCheck);
        if (!$this->payGateway) {
            throw new RuleValidationException(__('dujiaoka.prompt.pay_gateway_does_not_exist'));
        }
        // 临时保存支付方式
        $this->order->pay_id = $this->payGateway->id;
        $this->order->save();
    }

    /**
     * 网关处理.
     *
     * @param string $handle 跳转方法
     * @param string $payway 支付标识
     * @param string $orderSN 订单.
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function redirectGateway(string $handle,string $payway, string $orderSN)
    {
        try {
            $this->checkOrder($orderSN);
            $bccomp = bccomp($this->order->actual_price, 0.00, 2);
            // 如果订单金额为0 代表无需支付，直接成功
            if ($bccomp == 0) {
                $this->orderProcessService->completedOrder($this->order->order_sn, 0.00);
                return redirect(url('detail-order-sn', ['orderSN' => $this->order->order_sn]));
            }
            return redirect(url(urldecode($handle), ['payway' => $payway, 'orderSN' => $orderSN]));
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }

    }

    /**
     * ⭐ 从订单信息中提取 from 参数
     *
     * @return string
     *
     * @author    Claude Code Assistant
     * @copyright 2024
     * 
     */
    protected function extractFromFromOrder(): string
    {
        // 从订单 info 字段中提取 from 参数
        if (empty($this->order->info)) {
            return '';
        }

        // 使用正则表达式提取"来源: xxx"
        if (preg_match('/来源[:\s]+([^\s\n]+)/', $this->order->info, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * ⭐ 重写 render 方法，自动为 qrpay 页面添加 from 和 redirect_urls
     *
     * @param string $tpl 模板名称
     * @param array $data 数据
     * @param string $pageTitle 页面标题
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     * @author    Claude Code Assistant
     * @copyright 2024
     */
    protected function render(string $tpl, $data = [], string $pageTitle = '')
    {
        // ⭐ 如果是 qrpay 页面，自动添加 from 和 redirect_urls
        if ($tpl === 'static_pages/qrpay') {
            $data['from'] = $this->extractFromFromOrder();
            $data['redirect_urls'] = [
                'novel' => env('NOVEL_REDIRECT_URL', 'http://127.0.0.1:3000'),
            ];
        }

        // 调用父类的 render 方法
        return parent::render($tpl, $data, $pageTitle);
    }

}
