<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use App\Domain\Learning\Enums\ModuleKey;
use RuntimeException;

final class ModuleNotEnabledException extends RuntimeException
{
    public function __construct(public readonly ModuleKey $module, string $message = '')
    {
        parent::__construct(
            $message !== '' ? $message : sprintf('Module [%s] is not enabled for this academy.', $module->value)
        );
    }

    public static function for(ModuleKey $module): self
    {
        return new self($module);
    }
}
