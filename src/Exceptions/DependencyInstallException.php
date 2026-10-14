<?php

namespace MaxGojko\ModrinthIntegration\Exceptions;

use Exception;
use Throwable;

/**
 * A required dependency of a mod/plugin could not be resolved or installed.
 */
class DependencyInstallException extends Exception
{
    public function __construct(protected string $dependencyName, ?Throwable $previous = null)
    {
        parent::__construct("Failed to install required dependency $dependencyName", 0, $previous);
    }

    public function getDependencyName(): string
    {
        return $this->dependencyName;
    }
}
