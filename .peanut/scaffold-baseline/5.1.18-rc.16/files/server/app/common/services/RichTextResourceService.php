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

        $pattern = '~(\b(?:src|href|poster)\s*=\s*)(["\'])(.*?)\2~is';
        $isExternalHref = static function (string $attr, string $value): bool {
            return str_starts_with(strtolower(trim($attr)), 'href')
                && filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                && !str_contains((string) parse_url($value, PHP_URL_PATH), '/storage/')
                && !str_contains((string) parse_url($value, PHP_URL_PATH), '/api/storage/delivery');
        };

        $toResolve = [];
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $value = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($isExternalHref($match[1], $value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '' || preg_match('/^(?:mailto:|tel:|#)/i', $trimmed)) {
                    continue;
                }
                $toResolve[$trimmed] = true;
            }
        }
        if ($toResolve === []) {
            return $html;
        }

        $keys = array_keys($toResolve);
        if ($forRead) {
            $resolved = $this->files->getFileUrls($keys);
        } else {
            if ($context === null) {
                throw new \DomainException('FILE_REFERENCE_TRUSTED_CONTEXT_REQUIRED');
            }
            $resolved = $this->files->setTenantFileUrls($context, $keys);
        }

        return preg_replace_callback(
            $pattern,
            static function (array $match) use ($isExternalHref, $resolved): string {
                $value = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($isExternalHref($match[1], $value)) {
                    return $match[0];
                }
                $trimmed = trim($value);
                $mapped = ($trimmed === '' || preg_match('/^(?:mailto:|tel:|#)/i', $trimmed))
                    ? $trimmed
                    : ($resolved[$trimmed] ?? '');
                return $match[1] . $match[2] . htmlspecialchars($mapped, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $match[2];
            },
            $html,
        ) ?? $html;
    }
}
