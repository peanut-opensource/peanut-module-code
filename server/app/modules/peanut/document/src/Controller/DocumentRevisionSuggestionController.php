<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Controller;

use PeanutAdmin\Modules\Document\Contract\DocumentRevisionComments;
use PeanutAdmin\Modules\Document\Contract\DocumentRevisionSuggestions;
use think\App;

/** Same authenticated request boundary as fixed-revision discussions. */
class DocumentRevisionSuggestionController extends DocumentRevisionCommentController
{
    public function __construct(
        App $app,
        DocumentRevisionComments $comments,
        private readonly DocumentRevisionSuggestions $suggestions,
    ) {
        parent::__construct($app, $comments);
    }

    public function createSuggestion() { return $this->submit($this->suggestions->createSuggestion(...)); }
    public function acceptSuggestion() { return $this->submit($this->suggestions->acceptSuggestion(...)); }
    public function rejectSuggestion() { return $this->submit($this->suggestions->rejectSuggestion(...)); }
}
