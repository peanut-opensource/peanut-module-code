import type { PaymentResult } from '@/modules/peanut-payment/api/recharge';

function stringField(payload: Record<string, unknown>, key: string): string {
  const value = payload[key];
  if (typeof value !== 'string' || value === '') {
    throw new Error('PAYMENT_FIELD_INVALID: ' + key);
  }
  return value;
}

/** Adapt the existing business payment result; only the backend confirms settlement. */
export async function requestNativePayment(
  payment: PaymentResult
): Promise<void> {
  const { payload } = payment;
  if (
    payload === null ||
    typeof payload !== 'object' ||
    Array.isArray(payload)
  ) {
    throw new Error('PAYMENT_PAYLOAD_INVALID');
  }
  const scene = payment.scene.toUpperCase();
  if (scene !== 'JSAPI' && scene !== 'APP') {
    throw new Error('当前支付场景暂不支持');
  }

  let options: UniNamespace.RequestPaymentOptions & { appId?: string };
  if (payment.channel === 'wechat') {
    // The business channel is wechat; DCloud's native provider identifier is wxpay.
    options = { provider: 'wxpay' };
    if (scene === 'JSAPI') {
      options.appId = stringField(payload, 'appId');
      options.timeStamp = stringField(payload, 'timeStamp');
      options.nonceStr = stringField(payload, 'nonceStr');
      options.package = stringField(payload, 'package');
      options.signType = stringField(payload, 'signType');
      options.paySign = stringField(payload, 'paySign');
    } else {
      options.orderInfo = payload;
    }
  } else if (payment.channel === 'alipay') {
    options = {
      provider: 'alipay',
      orderInfo: stringField(payload, 'order_string'),
    };
  } else {
    throw new Error('支付渠道暂不支持');
  }

  return new Promise<void>((resolve, reject) => {
    uni.requestPayment({
      ...options,
      success: () => resolve(),
      fail: (error: unknown) =>
        reject(error instanceof Error ? error : new Error('支付未完成')),
    });
  });
}
