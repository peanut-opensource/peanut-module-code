<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Definition;

final class AiDefinitions
{
    public const KINDS = ['connection','model','route','template'];
    public const CAPABILITIES = ['text','image','speech','transcription','video'];
    public const RESULT_TYPES = ['text' => 'text','image' => 'image','speech' => 'audio','transcription' => 'transcript','video' => 'video'];
    public const PROTOCOLS = ['openai_text','anthropic_messages','openai_image','openai_speech','openai_transcription','gemini_video'];
    public const OPERATIONS = ['view','configure','use','review','manage'];
    public const FIELDS = [
        'connection' => ['protocol' => 'enum','origin' => 'text','api_prefix' => 'text','responsibility' => 'enum','responsibility_note' => 'text','daily_request_limit' => 'integer'],
        'model' => ['connection_id' => 'id','provider_model_id' => 'text','capability' => 'enum','input_limit_chars' => 'integer','max_output_tokens' => 'integer','voice' => 'text?','valid_until' => 'time?','supports_background' => 'bool'],
        'route' => ['capability' => 'enum','model_id' => 'id','template_id' => 'id','consumer_module' => 'text','scope_note' => 'text'],
        'template' => ['capability' => 'enum','instructions' => 'text','scope_note' => 'text'],
    ];
    public const PROTOCOL_CAPABILITY = ['openai_text' => 'text','anthropic_messages' => 'text','openai_image' => 'image','openai_speech' => 'speech','openai_transcription' => 'transcription','gemini_video' => 'video'];
    public const PROTOCOL_PATH = ['openai_text' => '/chat/completions','anthropic_messages' => '/messages','openai_image' => '/images/generations','openai_speech' => '/audio/speech','openai_transcription' => '/audio/transcriptions','gemini_video' => '/interactions'];
    public const INPUT_FILE_LIMIT = 16777216;
    public const RESPONSE_LIMIT = 67108864;
    public const OUTPUT_LIMIT = 33554432;
    public const SPEECH_DEPRECATIONS = ['tts-1','tts-1-hd','gpt-4o-mini-tts','gpt-4o-mini-tts-2025-12-15'];
}
