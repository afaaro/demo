<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

final class Poll
{
    public ?int    $id       = null;
    public int     $entryId  = 0;
    public bool    $active   = true;
    public ?string $closesAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    /** Resolved question for current locale. */
    public string $question = '';
    public string $locale   = '';

    /** @var array<string, string> locale => question */
    public array $questions = [];

    /** @var list<PollOption> */
    public array $options = [];

    /** @var array<int, int> option_id => votes */
    public array $tallies = [];

    public int $totalVotes = 0;

    public ?int $votedOptionId = null;

    public function isOpen(): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($this->closesAt === null || trim($this->closesAt) === '') {
            return true;
        }
        $ts = strtotime($this->closesAt);
        return $ts === false || $ts > time();
    }
}
