<?php

// MultiApp must resolve the HTTP Application before audience-specific gates run.
return [
    \app\common\http\middleware\TraceIdMiddleware::class,
];
