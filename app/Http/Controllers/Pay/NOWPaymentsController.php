<?php

namespace App\Http\Controllers\Pay;


use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

class NOWPaymentsController extends PayController
{
  public function gateway(string $payway, string $orderSN)
  {
    try {
      // 加载网关
      $this->loadGateWay($orderSN, $payway);

      //构造要请求的参数数组，无需改动
      $parameter = [
        "price_amount" => (float)$this->order->actual_price, //原价
        "price_currency" => $this->payGateway->pay_check,
        "order_id" => $this->order->order_sn, //可以是用户ID,站内商户订单号,用户名
        'ipn_callback_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
        'success_url' => route('NOWPayments-return', ['order_id' => $this->order->order_sn]),
      ];

      $parameter['ipn_callback_url'] .= '?signature=' . $this->NOWPaymentsSign($parameter, $this->payGateway->merchant_id);
      $client = new Client([
        'headers' => ['Content-Type' => 'application/json', 'x-api-key' => $this->payGateway->merchant_pem]
      ]);
      $response = $client->post('https://api.nowpayments.io/v1/invoice', ['body' => json_encode($parameter)]);
      $body = json_decode($response->getBody()->getContents(), true);
      if (!isset($body['created_at']) && $body['statusCode'] != 200) {
        return $this->err(__('dujiaoka.prompt.abnormal_payment_channel') . $body['message']);
      }
      return redirect()->away($body['invoice_url']);
    } catch (RuleValidationException $exception) {
    } catch (GuzzleException $exception) {
      return $this->err($exception->getMessage());
    }
  }


  private function NOWPaymentsSign(array $parameter, string $signKey)
  {
    ksort($parameter);
    reset($parameter); //内部指针指向数组中的第一个元素
    $sign = '';
    $urls = '';
    foreach ($parameter as $key => $val) {
      if ($val == '') continue;
      if ($key != 'signature') {
        if ($sign != '') {
          $sign .= "&";
          $urls .= "&";
        }
        $sign .= "$key=$val"; //拼接为url参数形式
        $urls .= "$key=" . urlencode($val); //拼接为url参数形式
      }
    }
    $sign = md5($sign . $signKey); //密码追加进入开始MD5签名
    return $sign;
  }

  public function notifyUrl(Request $request)
  {
    $data = $request->all();
    $order = $this->orderService->detailOrderSN($data['order_id']);
    if (!$order) {
      return 'fail';
    }
    $payGateway = $this->payService->detail($order->pay_id);
    if (!$payGateway) {
      return 'fail';
    }
    $signature = $this->NOWPaymentsSign($data, $payGateway->merchant_id);
    if ($data['signature'] != $signature) { //不合法的数据
      return 'fail';  //返回失败 继续补单
    } else {
      //合法的数据
      //业务处理
      $this->orderProcessService->completedOrder($data['order_id'], $data['pay_amount'], $data['purchase_id']);
      return 'ok';
    }
  }

  public function returnUrl(Request $request)
  {
    $oid = $request->get('order_id');
    // 异步通知还没到就跳转了，所以这里休眠2秒
    sleep(2);
    return redirect(url('detail-order-sn', ['orderSN' => $oid]));
  }
}
