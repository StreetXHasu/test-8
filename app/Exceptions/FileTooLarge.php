<?php

declare(strict_types=1);

namespace App\Exceptions;

final class FileTooLarge extends ApiException
{
    public function __construct()
    {
        parent::__construct('File is larger than 5 MB', 413);
    }
}
