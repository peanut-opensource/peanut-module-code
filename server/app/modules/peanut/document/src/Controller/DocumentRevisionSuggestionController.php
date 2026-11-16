<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Controller;

use PeanutAdmin\Modules\Document\Contract\DocumentRevisionSuggestions;

/** Same authenticated request boundary as fixed-revision discussions. */
class DocumentRevisionSuggestionController extends DocumentRevisionCommentController
{
    protected string $commandsClass = DocumentRevisionSuggestions::class;
    protected string $queriesClass = DocumentRevisionSuggestions::class;

    public function createSuggestion() { return $this->submit('createSuggestion'); }
    public function acceptSuggestion() { return $this->submit('acceptSuggestion'); }
    public function rejectSuggestion() { return $this->submit('rejectSuggestion'); }
}
