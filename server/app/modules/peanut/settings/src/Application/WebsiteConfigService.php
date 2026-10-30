<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Application;

use Closure;
use InvalidArgumentException;
use PeanutAdmin\Modules\Settings\Contract\WebsiteConfigStore;

/** Framework-neutral website configuration normalization and validation. */
final class WebsiteConfigService
{
    private const FIELDS = [
        'name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo',
        'pc_logo', 'pc_title', 'pc_ico', 'pc_desc', 'pc_keywords', 'h5_favicon',
        'slogan', 'copyright', 'official_url', 'github_url',
    ];

    private const IMAGE_FIELDS = [
        'web_favicon', 'web_logo', 'login_image', 'shop_logo', 'pc_logo', 'pc_ico', 'h5_favicon',
    ];

    private const MAX_LENGTHS = [
        'name' => 60, 'web_favicon' => 500, 'web_logo' => 500, 'login_image' => 500,
        'shop_name' => 60, 'shop_logo' => 500, 'pc_logo' => 500, 'pc_title' => 120,
        'pc_ico' => 500, 'pc_desc' => 500, 'pc_keywords' => 500, 'h5_favicon' => 500,
        'slogan' => 160, 'copyright' => 200, 'official_url' => 500, 'github_url' => 500,
    ];

    private const URL_FIELDS = ['official_url', 'github_url'];

    /** @var array<string, string> */
    private array $defaults;

    /** @param array<string, string> $defaults */
    public function __construct(
        private WebsiteConfigStore $store,
        private Closure $urlForRead,
        private Closure $urlForStorage,
        array $defaults,
    ) {
        if (array_keys($defaults) !== self::FIELDS) {
            throw new InvalidArgumentException('品牌默认字段与网站配置合同不一致');
        }
        $this->defaults = $defaults;
    }

    /** @return array<string, string> */
    public function get(): array
    {
        return $this->present($this->effectiveValues());
    }

    /** @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function save(array $params): array
    {
        if (array_diff(array_keys($params), self::FIELDS) !== []) {
            throw new InvalidArgumentException('网站配置包含未知字段');
        }
        $normalized = [];
        foreach ($this->defaults as $field => $default) {
            $raw = $params[$field] ?? $default;
            if (!is_string($raw)) {
                throw new InvalidArgumentException($this->label($field) . '格式错误');
            }
            $value = trim($raw);
            if (($field === 'name' || $field === 'shop_name') && $value === '') {
                throw new InvalidArgumentException($this->label($field) . '不能为空');
            }
            if (mb_strlen($value) > self::MAX_LENGTHS[$field]) {
                throw new InvalidArgumentException($this->label($field) . '长度超出限制');
            }
            if (in_array($field, self::URL_FIELDS, true) && !$this->validUrl($value)) {
                throw new InvalidArgumentException($this->label($field) . '必须是 HTTP(S) URL');
            }
            $normalized[$field] = $this->isImage($field) && $value !== $default
                ? (string) ($this->urlForStorage)($value)
                : $value;
        }

        $presentation = $this->present($this->effectiveValues($normalized));
        $this->store->replaceAtomically($normalized);

        return $presentation;
    }

    /** @param array<string, string> $values
     * @return array<string, string>
     */
    private function present(array $values): array
    {
        $result = [];
        foreach ($values as $field => $value) {
            $result[$field] = $this->isImage($field)
                ? ($value !== '' && $value === $this->defaults[$field]
                    ? '/' . ltrim($value, '/')
                    : (string) ($this->urlForRead)($value))
                : $value;
        }

        return $result;
    }

    /** @return array<string, string> Stable references and trusted defaults for the admin editor. */
    public function getForEdit(): array
    {
        return $this->effectiveValues();
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    private function isImage(string $field): bool
    {
        return in_array($field, self::IMAGE_FIELDS, true);
    }

    /** @param array<string, mixed>|null $source
     * @return array<string, string>
     */
    private function effectiveValues(?array $source = null): array
    {
        $source ??= $this->store->read();
        $result = [];
        foreach ($this->defaults as $field => $default) {
            $value = is_string($source[$field] ?? null) ? trim($source[$field]) : $default;
            if ($value === '' && $default !== '' && $field !== 'official_url') {
                $value = $default;
            }
            $result[$field] = $value;
        }

        return $result;
    }

    private function validUrl(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function label(string $field): string
    {
        return match ($field) {
            'name' => '网站名称',
            'shop_name' => '商城名称',
            'pc_title' => 'PC 页面标题',
            'official_url' => '官网地址',
            'github_url' => 'GitHub 地址',
            default => '网站配置字段 ' . $field,
        };
    }
}
