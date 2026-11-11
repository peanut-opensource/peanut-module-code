<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use app\common\value\http\OutboundHttpRequest;
use app\common\contract\http\OutboundHttpTransport;
use PeanutAdmin\FileMedia\Storage\StorageHttpTransport;

/** 将 Core 七牛窄传输合同映射到应用统一出站 HTTP 观测与重试通道。 */
final readonly class QiniuStorageHttpTransport implements StorageHttpTransport
{
    public function __construct(private OutboundHttpTransport $transport) {}

    /**
     * 保留 Core 请求中的超时、幂等重试、sink、multipart 与 headers 语义；异常仍由应用传输层归一化。
     *
     * @param array{
     *     method:string,
     *     url:string,
     *     headers?:array<string,string>,
     *     body?:string,
     *     connectTimeout?:int,
     *     timeout?:int,
     *     retrySafe?:bool,
     *     sink?:string,
     *     multipart?:list<array{name:string,contents:mixed,filename?:string,headers?:array<string,string>}>
     * } $request
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function request(array $request): array
    {
        $response = $this->transport->send(new OutboundHttpRequest(
            method: $request['method'],
            url: $request['url'],
            headers: $request['headers'] ?? [],
            body: $request['body'] ?? '',
            connectTimeoutSeconds: $request['connectTimeout'] ?? 10,
            timeoutSeconds: $request['timeout'] ?? 20,
            retrySafe: $request['retrySafe'] ?? false,
            sink: $request['sink'] ?? null,
            multipart: $request['multipart'] ?? [],
        ));

        return ['status' => $response->status, 'body' => $response->body, 'headers' => $response->headers];
    }
}
