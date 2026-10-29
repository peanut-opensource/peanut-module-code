<?php

declare(strict_types=1);

// The legacy RichTextDocument endpoints own this projection independently of Document.
$ref = static fn(string $name): array => ['$ref' => '#/components/schemas/RichText' . $name];
$object = static fn(array $properties, array $required = []): array => [
    'type' => 'object', 'additionalProperties' => false,
] + ($properties === [] ? [] : ['properties' => $properties]) + ($required === [] ? [] : ['required' => $required]);
$positive = ['type' => 'integer', 'minimum' => 1];
$null = ['type' => 'string', 'nullable' => true, 'enum' => [null]];
$align = ['type' => 'string', 'nullable' => true, 'enum' => ['left', 'right', 'center', 'justify', null]];
$media = $object([
    'fileKey' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
    'kind' => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'file']],
    'variantKey' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[a-z][a-z0-9-]{0,31}$'],
    'alt' => ['type' => 'string', 'nullable' => true, 'description' => 'At most 1000 UTF-8 bytes when non-null.'],
    'title' => ['type' => 'string', 'nullable' => true, 'description' => 'At most 1000 UTF-8 bytes when non-null.'],
], ['fileKey', 'kind']);
$cell = $object([
    'colspan' => $positive + ['maximum' => 100], 'rowspan' => $positive + ['maximum' => 100],
    'colwidth' => ['type' => 'array', 'nullable' => true, 'maxItems' => 100, 'items' => $positive + ['maximum' => 10000]],
]);
$inline = ['text', 'hardBreak'];
$blocks = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'codeBlock', 'horizontalRule', 'table', 'image', 'video', 'audio', 'file', 'media'];
$nodes = [];
foreach (['doc', ...$blocks, 'text', 'hardBreak', 'listItem', 'tableRow', 'tableCell', 'tableHeader'] as $type) {
    $attrs = match ($type) {
        'heading' => $object(['level' => $positive + ['maximum' => 6], 'textAlign' => $align]),
        'paragraph' => $object(['textAlign' => $align]),
        'orderedList' => $object(['start' => $positive + ['maximum' => 1000000]]),
        'codeBlock' => $object(['language' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[a-zA-Z0-9_+.-]{0,64}$']]),
        'tableCell', 'tableHeader' => $cell,
        'image', 'video', 'audio', 'file', 'media' => $media,
        default => $object([]),
    };
    $children = match ($type) {
        'doc', 'blockquote', 'listItem', 'tableCell', 'tableHeader' => $blocks,
        'paragraph', 'heading' => $inline, 'codeBlock' => ['text'],
        'bulletList', 'orderedList' => ['listItem'], 'table' => ['tableRow'],
        'tableRow' => ['tableCell', 'tableHeader'], default => [],
    };
    $content = ['type' => 'array', 'maxItems' => 50000,
        'items' => $children === [] ? ['type' => 'string'] : ['oneOf' => array_map(static fn(string $child): array => $ref('Node' . ucfirst($child)), $children)]];
    if ($children === []) {
        $content['maxItems'] = 0;
        $content['nullable'] = true;
    }
    $required = ['type'];
    if (in_array($type, ['doc', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'table', 'tableRow', 'tableCell', 'tableHeader'], true)) {
        $content['minItems'] = 1;
        $required[] = 'content';
    } else {
        $content['nullable'] = true;
    }
    if ($type === 'listItem') {
        $content['description'] = 'First child must be a paragraph.';
    }
    $properties = ['type' => ['type' => 'string', 'enum' => [$type]],
        'attrs' => ['oneOf' => [$attrs, ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']], $null]],
        'content' => $content, 'marks' => ['type' => 'array', 'nullable' => true, 'items' => $ref('Mark')]];
    if (in_array($type, ['image', 'video', 'audio', 'file', 'media'], true)) {
        $properties['attrs'] = $attrs;
        $required[] = 'attrs';
    }
    if (!in_array($type, $inline, true)) {
        $properties['marks']['maxItems'] = 0;
    }
    if ($type === 'text') {
        $properties['text'] = ['type' => 'string', 'minLength' => 1];
        $required[] = 'text';
    } else {
        $properties['text'] = $null;
    }
    $nodes['RichTextNode' . ucfirst($type)] = $object($properties, $required);
}
$markBranches = [];
foreach (['bold', 'italic', 'strike', 'underline', 'code', 'link', 'textStyle'] as $type) {
    $attrs = match ($type) {
        'link' => $object([
            'href' => ['type' => 'string', 'pattern' => '^([hH][tT][tT][pP][sS]?://|[mM][aA][iI][lL][tT][oO]:)[^\x00-\x20]+$',
                'description' => 'At most 2048 bytes; http(s) needs a host; /storage/delivery is forbidden.'],
            'target' => ['type' => 'string', 'nullable' => true, 'enum' => ['_blank', '_self', null]],
            'rel' => ['type' => 'string', 'nullable' => true, 'enum' => ['noopener noreferrer', 'noopener noreferrer nofollow', null]],
            'class' => $null,
        ], ['href']),
        'textStyle' => $object(['color' => ['type' => 'string', 'nullable' => true, 'pattern' => '^#[0-9a-fA-F]{3,8}$']]),
        default => $object([]),
    };
    $markBranches[] = $object(['type' => ['type' => 'string', 'enum' => [$type]],
        'attrs' => $type === 'link' ? $attrs : ['oneOf' => [$attrs, ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']], $null]]],
        $type === 'link' ? ['type', 'attrs'] : ['type']);
}
$structured = $object([
    'schemaVersion' => ['type' => 'string', 'enum' => ['peanut.richtext/1']],
    'editorModel' => ['type' => 'string', 'enum' => ['tiptap-prosemirror']],
    'content' => $ref('NodeDoc'), 'annotations' => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']],
], ['schemaVersion', 'editorModel', 'content', 'annotations']);
$structured['description'] = 'RichTextProjection whitelist: at most 50000 total nodes, nesting <=60, <=1000 distinct file keys. Validator JSON encoding depth 64 and <=1 MiB apply to requests. Code-block text children must have no marks. No Yjs state is synthesized from this JSON.';
$bigId = ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'];
$time = ['type' => 'string', 'description' => 'Model timestamp formatted with database.datetime_format (Y-m-d H:i:s).'];
$row = ['id' => $bigId, 'title' => ['type' => 'string', 'maxLength' => 200], 'revision' => $positive,
    'created_by_member_id' => $bigId, 'updated_by_member_id' => $bigId, 'create_time' => $time, 'update_time' => $time];
$state = ['type' => 'string', 'nullable' => true, 'format' => 'byte', 'description' => 'Strict base64; decoded state <=8 MiB. Empty or null means no state.'];
$write = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'document' => $ref('StructuredContent'), 'collaboration_state' => $state];
$envelope = static fn(array $data): array => $object(['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $data], ['code', 'msg', 'data']);
$response = static fn(array $data): array => ['description' => '成功', 'content' => ['application/json' => ['schema' => $envelope($data)]]];
$error = ['$ref' => '#/components/responses/ErrorResponse'];
$errors = ['400' => $error, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error, '500' => $error];
$paths = [];
foreach (['list', 'detail', 'collaboration', 'add', 'edit', 'delete'] as $action) {
    $operation = ['tags' => ['RichText'], 'operationId' => 'richTextDocument' . ucfirst($action),
        'description' => 'Current-tenant admin session and route permission required; soft-deleted documents are excluded.',
        'responses' => ['200' => $response(match ($action) {
            'list' => $object(['lists' => ['type' => 'array', 'maxItems' => 100, 'items' => $ref('Record')],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => $positive, 'pageSize' => $positive + ['maximum' => 100]], ['lists', 'count', 'pageNo', 'pageSize']),
            'detail' => $ref('Detail'), 'collaboration' => $ref('Collaboration'),
            default => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']],
        })] + $errors];
    if (in_array($action, ['detail', 'collaboration'], true)) {
        $operation['parameters'] = [['in' => 'query', 'name' => 'id', 'required' => true, 'schema' => $positive]];
    } elseif ($action === 'list') {
        $operation['parameters'] = [
            ['in' => 'query', 'name' => 'title', 'schema' => ['type' => 'string', 'maxLength' => 200]],
            ['in' => 'query', 'name' => 'page_no', 'schema' => $positive + ['default' => 1]],
            ['in' => 'query', 'name' => 'page_size', 'schema' => $positive + ['maximum' => 100, 'default' => 15]],
            ['in' => 'query', 'name' => 'page', 'description' => 'Existing PaginationInput alias, used only when page_no is absent.', 'schema' => $positive],
            ['in' => 'query', 'name' => 'limit', 'description' => 'Existing PaginationInput alias, used only when page_size is absent; PageRequest still bounds the page.', 'schema' => $positive + ['maximum' => 100]],
        ];
    } else {
        $body = match ($action) {
            'add' => $object($write, ['title', 'document']),
            'edit' => $object(['id' => $positive, 'revision' => $positive] + $write, ['id', 'revision', 'title', 'document']),
            default => $object(['id' => $positive], ['id']),
        };
        $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $body]]];
        $operation['x-peanut-errors'] = $action === 'delete' ? ['RICH_TEXT_DOCUMENT_NOT_FOUND']
            : ['RICH_TEXT_PROJECTION_INVALID', 'RICH_TEXT_COLLABORATION_STATE_INVALID'];
        if ($action === 'edit') {
            $operation['x-peanut-errors'][] = 'RICH_TEXT_DOCUMENT_NOT_FOUND';
            $operation['x-peanut-errors'][] = 'RICH_TEXT_DOCUMENT_REVISION_CONFLICT';
            $operation['description'] .= ' Revision is a compare-and-swap input; successful edit increments it by one.';
        }
    }
    $paths['/adminapi/peanut.rich-text.document.' . $action][in_array($action, ['list', 'detail', 'collaboration'], true) ? 'get' : 'post'] = $operation;
}

return ['paths' => $paths, 'components' => ['schemas' => $nodes + [
    'RichTextMark' => ['oneOf' => $markBranches], 'RichTextStructuredContent' => $structured,
    'RichTextRecord' => $object($row, array_keys($row)),
    'RichTextDetail' => $object($row + ['document' => $ref('StructuredContent'), 'collaboration_state' => ['type' => 'string', 'format' => 'byte']], [...array_keys($row), 'document', 'collaboration_state']),
    'RichTextCollaboration' => ['oneOf' => [
        $object(['enabled' => ['type' => 'boolean', 'enum' => [false]], 'url' => $null,
            'document_name' => ['type' => 'string', 'pattern' => '^tenant:[1-9][0-9]*/rich-text:[1-9][0-9]*$'],
            'token' => $null, 'expires_at' => $null], ['enabled', 'url', 'document_name', 'token', 'expires_at']),
        $object(['enabled' => ['type' => 'boolean', 'enum' => [true]], 'url' => ['type' => 'string', 'pattern' => '^wss?://'],
            'document_name' => ['type' => 'string', 'pattern' => '^tenant:[1-9][0-9]*/rich-text:[1-9][0-9]*$'],
            'token' => ['type' => 'string', 'writeOnly' => false, 'description' => 'Short-lived payload.signature ticket; expires in 300 seconds.'],
            'expires_at' => $positive], ['enabled', 'url', 'document_name', 'token', 'expires_at']),
    ]],
]]];
