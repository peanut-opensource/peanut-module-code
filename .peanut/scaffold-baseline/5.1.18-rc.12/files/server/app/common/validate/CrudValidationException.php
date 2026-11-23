<?php

declare(strict_types=1);

namespace app\common\validate;

use think\exception\ValidateException;

/** A ThinkPHP validation failure at a CRUD input boundary. */
final class CrudValidationException extends ValidateException {}
