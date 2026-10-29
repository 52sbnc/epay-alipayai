<?php

declare(strict_types=1);

namespace plugins\payment\alipayai;

use app\common\PaymentContext;
use app\common\BasePayment;

/**
 * 支付宝 AI付（Agent支付 / Vibe Pay 基础收款）
 *
 * 对接官方「AI网页应用收款」能力，调用 alipay.trade.page.pay，
 * 支持支付宝个人账号收款，并复用官方收单查询、退款、关闭接口。
 */
class AlipayaiPlugin extends BasePayment
{
    private array $alipayConfig;

    public function __construct(array $channel)
    {
        parent::__construct($channel);
        $this->alipayConfig = $this->getConfig();
    }

    private function getConfig(): array
    {
        $config = [
            'app_id' => $this->channel['appid'],
            'alipay_public_key' => $this->channel['appkey'],
            'app_private_key' => $this->channel['appsecret'],
            'sign_type' => 'RSA2',
            'charset' => 'UTF-8',
            'gateway_url' => !empty($this->channel['gateway']) ? $this->channel['gateway'] : 'https://openapi.alipay.com/gateway.do',
            'cert_mode' => ($this->channel['sign_type'] ?? '0') == '1' ? 1 : 0,
        ];
        if ($config['cert_mode'] == 1) {
            $config['app_cert_path'] = getCertFilePath($this->channel['app_cert_path']);
            $config['alipay_cert_path'] = getCertFilePath($this->channel['alipay_cert_path']);
            $config['root_cert_path'] = getCertFilePath($this->channel['root_cert_path']);
        }
        return $config;
    }

    /**
     * 统一下单业务参数（AI网页应用收款，产品码固定 FAST_INSTANT_TRADE_PAY）
     */
    private function getBizContent(PaymentContext $ctx): array
    {
        $bizContent = [
            'out_trade_no' => $ctx->order['trade_no'],
            'total_amount' => $ctx->order['realmoney'],
            'subject' => $ctx->ordername,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'time_expire' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        if (!empty($this->channel['appmchid'])) {
            $bizContent['seller_id'] = $this->channel['appmchid'];
        }
        $bizContent['business_params'] = ['mc_create_trade_ip' => request()->clientip];

        if (!empty($ctx->order['cert_no']) || !empty($ctx->order['cert_name']) || isset($ctx->order['min_age'])) {
            $ext = ['need_check_info' => 'T'];
            if (!empty($ctx->order['cert_no'])) {
                $ext['cert_type'] = 'IDENTITY_CARD';
                $ext['cert_no'] = $ctx->order['cert_no'];
            }
            if (!empty($ctx->order['cert_name'])) {
                $ext['name'] = $ctx->order['cert_name'];
            }
            if (isset($ctx->order['min_age'])) {
                $ext['min_age'] = $ctx->order['min_age'];
            }
            $bizContent['ext_user_info'] = $ext;
        }

        return $bizContent;
    }

    private function getConfigWithUrl(PaymentContext $ctx, bool $getUrl = false): array
    {
        $config = $this->alipayConfig;
        $config['notify_url'] = config_get('localurl') . 'pay/notify/' . $ctx->order['trade_no'] . '/';
        $config['return_url'] = request()->siteurl . 'pay/return/' . $ctx->order['trade_no'] . '/';
        if ($getUrl) {
            $config['pageMethod'] = '2';
        }
        return $config;
    }

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $ctx->order['trade_no'] . '/'];
    }

    public function mapi(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => request()->siteurl . 'pay/qrcode/' . $ctx->order['trade_no'] . '/'];
    }

    /**
     * 扫码收款：生成支付链接并提取二维码，支付宝内直接跳转
     */
    public function qrcode(PaymentContext $ctx): array
    {
        $config = $this->getConfigWithUrl($ctx, true);
        $bizContent = $this->getBizContent($ctx);
        $bizContent['qr_pay_mode'] = '4';
        $bizContent['qrcode_width'] = '200';

        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $url = $aop->pagePay($bizContent);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }

        if ($ctx->mdevice === 'alipay') {
            return ['type' => 'jump', 'url' => $url];
        }

        try {
            $html = get_curl($url, 0, 0, 0, 0, 0, 0, 0, 1);
            $html = mb_convert_encoding($html, 'utf-8', 'gbk');
        } catch (\Throwable $e) {
            return ['type' => 'jump', 'url' => $url];
        }

        if (preg_match('!<input name="qrCode" type="hidden" value="(.*?)"!i', $html, $match)) {
            return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => $match[1]];
        }

        return ['type' => 'jump', 'url' => $url];
    }

    /**
     * 登录支付：直接输出支付宝收银台表单
     */
    public function pagepay(PaymentContext $ctx): array
    {
        $config = $this->getConfigWithUrl($ctx);
        $bizContent = $this->getBizContent($ctx);

        try {
            $aop = new \Alipay\AlipayTradeService($config);
            $html = $aop->pagePay($bizContent);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'msg' => '支付宝下单失败！' . $e->getMessage()];
        }

        return ['type' => 'html', 'data' => $html];
    }

    /**
     * 异步回调
     */
    public function notify(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
        $verify_result = $aop->check(request()->post());

        if ($verify_result) {
            $app_id = request()->post('app_id');
            if (!empty($app_id) && (string) $app_id !== (string) $this->channel['appid']) {
                return ['type' => 'html', 'data' => 'fail'];
            }

            $out_trade_no = request()->post('out_trade_no');
            $trade_no = request()->post('trade_no');
            $buyer_id = request()->post('buyer_id') ?: request()->post('buyer_open_id');
            $total_amount = (float) request()->post('total_amount', 0);
            $trade_status = request()->post('trade_status');
            $end_time = request()->post('gmt_payment');
            $order = $ctx->order;

            if ($trade_status === 'TRADE_SUCCESS') {
                if ($out_trade_no === $order['trade_no'] && round($total_amount, 2) == round((float) $order['realmoney'], 2)) {
                    $this->processNotify($order, $trade_no, $buyer_id, null, null, $end_time);
                }
            }
            return ['type' => 'html', 'data' => 'success'];
        }

        return ['type' => 'html', 'data' => 'fail'];
    }

    /**
     * 同步回调
     */
    public function return(PaymentContext $ctx): array
    {
        $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
        $verify_result = $aop->check(request()->get());

        if ($verify_result) {
            $out_trade_no = request()->get('out_trade_no');
            $trade_no = request()->get('trade_no');
            $total_amount = (float) request()->get('total_amount', 0);
            $order = $ctx->order;

            if ($out_trade_no === $order['trade_no'] && round($total_amount, 2) == round((float) $order['realmoney'], 2)) {
                return $this->processReturn($order, $trade_no);
            }
            return ['type' => 'error', 'msg' => '订单信息校验失败'];
        }

        return ['type' => 'error', 'msg' => '支付宝返回验证失败'];
    }

    /**
     * 订单查询
     */
    public function query(array $order): array
    {
        $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
        $result = $aop->query(null, $order['trade_no']);
        return [
            'api_trade_no' => $result['trade_no'],
            'status' => $result['trade_status'] == 'TRADE_SUCCESS' || $result['trade_status'] == 'TRADE_FINISHED' ? 1 : 0,
            'money' => $result['total_amount'],
            'buyer' => $result['buyer_user_id'] ?? $result['buyer_open_id'] ?? '',
            'endtime' => $result['send_pay_date'] ?? '',
        ];
    }

    /**
     * 退款
     */
    public function refund(array $order): array
    {
        $bizContent = [
            'trade_no' => $order['api_trade_no'],
            'refund_amount' => $order['refundmoney'],
            'out_request_no' => $order['refund_no'],
        ];
        try {
            $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
            $result = $aop->refund($bizContent);
            return [
                'code' => 0,
                'trade_no' => $result['trade_no'] ?? '',
                'refund_fee' => $result['refund_fee'] ?? '',
            ];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 关闭订单
     */
    public function close(array $order): array
    {
        $bizContent = ['out_trade_no' => $order['trade_no']];
        try {
            $aop = new \Alipay\AlipayTradeService($this->alipayConfig);
            $aop->close($bizContent);
            return ['code' => 0];
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }
}
