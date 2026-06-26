<?php

declare(strict_types=1);

namespace App\Controllers;

use App\LlmsBuilder;

final class LlmsController
{
    public function __construct(private readonly LlmsBuilder $builder)
    {
    }

    public function index(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        echo $this->builder->readOrBuildIndex();
    }
}
