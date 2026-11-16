<?php

declare(strict_types=1);

namespace app\common\services;

use app\common\services\HtmlSanitizerService;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Stable media references and ordinary external hyperlinks have separate contracts. */
final readonly class RichTextResourceService
{
    public function __construct(private FileReferences $files) {}

    public function forStorage(
        string $html,
        AuthenticatedMemberContext|TenantContext|TenantSystemContext|null $context = null,
    ): string {
        return $this->transform(HtmlSanitizerService::sanitize($html), false, $context);
    }

    public function forRead(string $html): string
    {
        return $this->transform(HtmlSanitizerService::sanitize($html), true);
    }

    private function transform(
        string $html,
        bool $forRead,
        AuthenticatedMemberContext|TenantContext|TenantSystemContext|null $context = null,
    ): string {
        if ($html === '') {
            return '';
        }

        $convert = function (string $url) use ($forRead, $context): string {
            $url = trim($url);
            if ($url === '' || preg_match('/^(?:mailto:|tel:|#)/i', $url)) {
                return $url;
            }
            if ($forRead) {
                return $this->files->getFileUrl($url);
            }
            return $context === null
                ? throw new \DomainException('FILE_REFERENCE_TRUSTED_CONTEXT_REQUIRED')
                : $this->files->setTenantFileUrl($context, $url);
        };

        $html = preg_replace_callback(
            '~(\b(?:src|href|poster)\s*=\s*)(["\'])(.*?)\2~is',
            static function (array $match) use ($convert): string {
                // A normal hyperlink does not acquire a File grant. Media src/poster always do.
                $value = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with(strtolower(trim($match[1])), 'href')
                    && filter_var($value, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                    && !str_contains((string) parse_url($value, PHP_URL_PATH), '/storage/')
                    && !str_contains((string) parse_url($value, PHP_URL_PATH), '/api/storage/delivery')) {
                    return $match[0];
                }
                return $match[1] . $match[2] . htmlspecialchars($convert($value), ENT_QUOTES | ENT_HTML5, 'UTF-8') . $match[2];
            },
            $html,
        ) ?? $html;
        return $html;
    }
}
