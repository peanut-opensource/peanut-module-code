<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginPackageException;
use think\console\Input;
use think\console\Output;

trait ModulePackageCommandSupport
{
    /** @return array{key_id:string,secret_key:string}|null */
    private function packageSigner(Input $input): ?array
    {
        $keyId = trim((string) $input->getOption('signing-key-id'));
        $path = trim((string) $input->getOption('signing-secret-key-file'));
        if ($keyId === '' && $path === '') {
            return null;
        }
        if ($keyId === '' || $path === '' || !is_file($path)) {
            throw new PluginPackageException('MODULE_PACKAGE_SIGNING_KEY_INVALID', 'Both signing key options are required.');
        }
        $encoded = trim((string) file_get_contents($path));
        $secret = base64_decode($encoded, true);
        if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new PluginPackageException('MODULE_PACKAGE_SIGNING_KEY_INVALID', 'Signing secret key file is invalid.');
        }
        return ['key_id' => $keyId, 'secret_key' => $secret];
    }

    private function packageOutput(string $key, string $version, string $requested): string
    {
        if ($requested !== '') {
            return str_starts_with($requested, DIRECTORY_SEPARATOR)
                ? $requested
                : dirname(__DIR__, 3) . '/' . ltrim($requested, '/');
        }
        $root = dirname(__DIR__, 3);
        $hostPath = $root . '/.local/toolchain/host.json';
        $release = 'release';
        if (is_file($hostPath)) {
            $host = json_decode((string) file_get_contents($hostPath), true, 512, JSON_THROW_ON_ERROR);
            $release = $host['paths']['release'] ?? $release;
            if (!is_string($release) || trim($release) === '') {
                throw new PluginPackageException('MODULE_PACKAGE_OUTPUT_INVALID', 'Configured release directory is invalid.');
            }
        }
        if (!str_starts_with($release, DIRECTORY_SEPARATOR)) {
            $release = $root . '/' . $release;
        }
        return rtrim($release, '/') . '/modules/' . $key . '-' . $version . '.tar';
    }

    /** @param callable():array<string,mixed> $operation */
    private function runPackageCommand(Output $output, callable $operation): int
    {
        try {
            $output->writeln((string) json_encode(
                $operation(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (PluginPackageException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode, 'reason' => $exception->getMessage(), 'details' => $exception->details],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1;
        } catch (\Throwable $exception) {
            $output->writeln((string) json_encode(['error' => 'MODULE_PACKAGE_FAILED', 'reason' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}
