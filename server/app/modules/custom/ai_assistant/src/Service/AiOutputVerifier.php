<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;

final readonly class AiOutputVerifier
{
    public function verify(string $path, string $type): array
    {
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > D::OUTPUT_LIMIT || is_link($path)) {
            throw new \RuntimeException('AI_OUTPUT_SIZE_INVALID');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = [];
        if (in_array($type, ['text','transcript'], true)) {
            AiProtocol::normalizeText(file_get_contents($path));
            $mime = 'text/plain';
        } elseif ($type === 'image') {
            $info = getimagesize($path);
            if (!is_array($info) || ($info['mime'] ?? null) !== 'image/png' || $mime !== 'image/png' || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 16777216) {
                throw new \RuntimeException('AI_IMAGE_BYTES_INVALID');
            }$info = ['width' => $info[0],'height' => $info[1]];
        } else {
            if (!in_array($mime, $type === 'video' ? ['video/mp4'] : ['audio/wav','audio/x-wav'], true)) {
                throw new \RuntimeException('AI_MEDIA_MIME_INVALID');
            }
            $pipes = [];
            $p = proc_open([(string) env('AI_FFPROBE_BINARY', 'ffprobe'),'-v','error','-max_alloc','67108864','-threads','2','-protocol_whitelist','file','-format_whitelist','mov,wav','-show_entries','format=duration:stream=codec_type,width,height,channels,sample_rate','-of','json',$path], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, dirname($path), null, ['bypass_shell' => true]);
            if (!is_resource($p)) {
                throw new \RuntimeException('AI_OUTPUT_INSPECTOR_UNAVAILABLE');
            }fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $start = hrtime(true);
            $json = '';
            $exit = -1;
            try {
                while (true) {
                    $json .= stream_get_contents($pipes[1]);
                    stream_get_contents($pipes[2]);
                    if (strlen($json) > 65536 || (hrtime(true) - $start) > 10e9) {
                        throw new \RuntimeException('AI_OUTPUT_PROBE_LIMIT');
                    }$state = proc_get_status($p);
                    if (!$state['running']) {
                        $exit = $state['exitcode'];
                        break;
                    }usleep(50000);
                }$json .= stream_get_contents($pipes[1]);
                if ($exit !== 0) {
                    throw new \RuntimeException('AI_OUTPUT_PROBE_FAILED');
                }
            } finally {
                $state = proc_get_status($p);
                if ($state['running']) {
                    proc_terminate($p, 9);
                }fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
            }
            $info = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $duration = $info['format']['duration'] ?? null;
            if (!is_numeric($duration) || !is_finite((float) $duration) || (float) $duration <= 0 || (float) $duration > 300 || count($info['streams'] ?? []) > 8) {
                throw new \RuntimeException('AI_MEDIA_DURATION_INVALID');
            }
            $wanted = $type === 'video' ? 'video' : 'audio';
            if (!array_filter($info['streams'], static fn(array $s): bool => ($s['codec_type'] ?? '') === $wanted)) {
                throw new \RuntimeException('AI_MEDIA_STREAM_MISSING');
            }
            foreach ($info['streams'] as $s) {
                if (($s['width'] ?? 0) > 4096 || ($s['height'] ?? 0) > 2160 || ($s['channels'] ?? 0) > 8) {
                    throw new \RuntimeException('AI_MEDIA_DIMENSION_LIMIT');
                }
            }
        }
        return ['sha256' => hash_file('sha256', $path),'size_bytes' => $size,'verified_mime_type' => $mime,'result_type' => $type,'media_info' => $info];
    }
}
