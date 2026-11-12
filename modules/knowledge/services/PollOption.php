<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

final class PollOption
{
    public ?int   $id        = null;
    public int    $pollId    = 0;
    public int    $sortOrder = 0;
    public string $label     = '';
    public string $locale    = '';

    /** @var array<string, string> locale => label */
    public array $labels = [];
}
