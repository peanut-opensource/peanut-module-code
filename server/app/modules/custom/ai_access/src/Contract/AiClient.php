<?php

declare(strict_types=1);

namespace Custom\Modules\AiAccess\Contract;

interface AiClient
{
    /** @return array{text:string, request:array} */
    public function generateText(
        string $prompt,
        string $requestKey,
        ?string $model = null,
        ?string $provider = null,
        ?string $credential = null,
    ): array;

    /** @return array{image:string, mime_type:string, request:array} */
    public function generateImage(
        string $prompt,
        string $requestKey,
        ?string $model = null,
        ?string $provider = null,
        ?string $credential = null,
    ): array;

    public function requestStatus(string $requestKey): array;
}
