<?php

declare(strict_types=1);

namespace app\common\infrastructure\notice\sms;

use app\common\contract\notice\sms\SmsDriver;
use app\common\value\http\OutboundHttpRequest;
use app\common\contract\http\OutboundHttpTransport;
use app\common\value\notice\sms\SmsDriverResult;

/**
 * 阿里云短信驱动（RPC 查询字符串签名 v1.0）
 * 文档：https://help.aliyun.com/document_detail/101414.html
 *
 * 配置由当前 Tenant 的 notice.sms external binding 提供：
 *   access_key_id, access_key_secret, sign_name
 */
final class AliyunSms implements SmsDriver
{
    private string $accessKeyId;
    private string $accessKeySecret;
    private string $signName;

    public function __construct(array $config, private readonly OutboundHttpTransport $transport)
    {
        $this->accessKeyId     = (string) ($config['access_key_id']     ?? '');
        $this->accessKeySecret = (string) ($config['access_key_secret'] ?? '');
        $this->signName        = (string) ($config['sign_name']         ?? '');
    }

    public function send(string $mobile, string $templateCode, array $vars): SmsDriverResult
    {
        $params = [
            'AccessKeyId'      => $this->accessKeyId,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => $mobile,
            'SignName'         => $this->signName,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => uniqid('', true),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $templateCode,
            'TemplateParam'    => json_encode($vars, JSON_UNESCAPED_UNICODE),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];

        ksort($params);

        $query = '';
        foreach ($params as $k => $v) {
            $query .= '&' . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $query = ltrim($query, '&');

        $stringToSign = 'POST&%2F&' . rawurlencode($query);
        $params['Signature'] = base64_encode(
            hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true),
        );

        $body = http_build_query($params);

        $response = $this->transport->send(new OutboundHttpRequest(
            'POST',
            'https://dysmsapi.aliyuncs.com',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $body,
            timeoutSeconds: 10,
        ));
        $resp = $response->body;

        $data = json_decode((string) $resp, true);
        $receipt = is_array($data) ? $data : ['raw' => (string) $resp];
        if (!is_array($data) || trim((string) ($data['Code'] ?? '')) === '') {
            return new SmsDriverResult(SmsDriverResult::OUTCOME_UNKNOWN, '短信服务商返回无法确认', $receipt);
        }
        if ($data['Code'] !== 'OK') {
            return new SmsDriverResult(
                SmsDriverResult::OUTCOME_FAILED,
                (string) ($data['Message'] ?? $data['Code']),
                $receipt,
            );
        }

        return new SmsDriverResult(SmsDriverResult::OUTCOME_SUCCEEDED, '', $receipt);
    }
}
