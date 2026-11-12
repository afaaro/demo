<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class PollRepository
{
    public function __construct(private Database $db) {}

    public function findByEntryId(int $entryId, ?string $locale = null, ?string $voterKey = null): ?Poll
    {
        $row = $this->db->first('knowledge_polls', ['entry_id' => $entryId]);
        if ($row === []) {
            return null;
        }
        return $this->hydrate($row, $locale, $voterKey);
    }

    public function find(int $id, ?string $locale = null, ?string $voterKey = null): ?Poll
    {
        $row = $this->db->first('knowledge_polls', ['id' => $id]);
        if ($row === []) {
            return null;
        }
        return $this->hydrate($row, $locale, $voterKey);
    }

    /**
     * Create or replace poll for an entry from admin form data.
     *
     * @param array{
     *   active?:bool|int,
     *   closes_at?:string|null,
     *   questions?:array<string,string>,
     *   options?:list<array{id?:int,labels:array<string,string>}>
     * } $data
     */
    public function syncForEntry(int $entryId, array $data): Poll
    {
        $existing = $this->db->first('knowledge_polls', ['entry_id' => $entryId]);
        $active = !empty($data['active']);
        $closesAt = $this->normalizeClosesAt($data['closes_at'] ?? null);

        if ($existing === []) {
            $pollId = $this->db->insert('knowledge_polls', [
                'entry_id'  => $entryId,
                'active'    => $active ? 1 : 0,
                'closes_at' => $closesAt,
            ]);
        } else {
            $pollId = (int) $existing['id'];
            $this->db->update('knowledge_polls', [
                'active'    => $active ? 1 : 0,
                'closes_at' => $closesAt,
            ], ['id' => $pollId]);
        }

        $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];
        $this->syncQuestions($pollId, $questions);

        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $this->syncOptions($pollId, $options);

        return $this->find($pollId)
            ?? throw new RuntimeException('Failed to reload poll.');
    }

    public function deleteForEntry(int $entryId): void
    {
        $this->db->query('DELETE FROM `#__knowledge_polls` WHERE `entry_id` = ?', [$entryId]);
    }

    public function castVote(int $pollId, int $optionId, string $voterKey, ?int $userId = null): void
    {
        $poll = $this->find($pollId);
        if ($poll === null) {
            throw new RuntimeException('Poll not found.');
        }
        if (!$poll->isOpen()) {
            throw new RuntimeException('This poll is closed.');
        }

        $optionOk = false;
        foreach ($poll->options as $opt) {
            if ((int) $opt->id === $optionId) {
                $optionOk = true;
                break;
            }
        }
        if (!$optionOk) {
            throw new InvalidArgumentException('Invalid poll option.');
        }

        $existing = $this->db->query(
            'SELECT `id` FROM `#__knowledge_poll_votes` WHERE `poll_id` = ? AND `voter_key` = ? LIMIT 1',
            [$pollId, $voterKey]
        )->row;
        if ($existing !== []) {
            throw new RuntimeException('You have already voted on this poll.');
        }

        $this->db->insert('knowledge_poll_votes', [
            'poll_id'   => $pollId,
            'option_id' => $optionId,
            'user_id'   => $userId !== null && $userId > 0 ? $userId : null,
            'voter_key' => mb_substr($voterKey, 0, 80),
        ]);
    }

    private function hydrate(array $row, ?string $locale = null, ?string $voterKey = null): Poll
    {
        $locale = $locale ?? $this->defaultLocale();
        $default = $this->defaultLocale();

        $poll = new Poll();
        $poll->id        = (int) $row['id'];
        $poll->entryId   = (int) $row['entry_id'];
        $poll->active    = (bool) ($row['active'] ?? true);
        $poll->closesAt  = $row['closes_at'] ?? null;
        $poll->createdAt = $row['created_at'] ?? null;
        $poll->updatedAt = $row['updated_at'] ?? null;
        $poll->locale    = $locale;

        $qRows = $this->db->query(
            'SELECT `locale`, `question` FROM `#__knowledge_poll_translations` WHERE `poll_id` = ?',
            [$poll->id]
        )->rows;
        foreach ($qRows as $qRow) {
            $poll->questions[(string) $qRow['locale']] = (string) ($qRow['question'] ?? '');
        }
        $poll->question = $poll->questions[$locale]
            ?? $poll->questions[$default]
            ?? (string) reset($poll->questions);

        $optRows = $this->db->query(
            'SELECT * FROM `#__knowledge_poll_options` WHERE `poll_id` = ? ORDER BY `sort_order`, `id`',
            [$poll->id]
        )->rows;

        $labelRows = $this->db->query(
            'SELECT ot.`option_id`, ot.`locale`, ot.`label`
               FROM `#__knowledge_poll_option_translations` ot
               INNER JOIN `#__knowledge_poll_options` o ON o.`id` = ot.`option_id`
              WHERE o.`poll_id` = ?',
            [$poll->id]
        )->rows;
        $labelsByOption = [];
        foreach ($labelRows as $lr) {
            $oid = (int) $lr['option_id'];
            $labelsByOption[$oid][(string) $lr['locale']] = (string) ($lr['label'] ?? '');
        }

        foreach ($optRows as $optRow) {
            $opt = new PollOption();
            $opt->id = (int) $optRow['id'];
            $opt->pollId = (int) $optRow['poll_id'];
            $opt->sortOrder = (int) ($optRow['sort_order'] ?? 0);
            $opt->labels = $labelsByOption[$opt->id] ?? [];
            $opt->locale = $locale;
            $opt->label = $opt->labels[$locale]
                ?? $opt->labels[$default]
                ?? (string) reset($opt->labels);
            $poll->options[] = $opt;
        }

        $tallyRows = $this->db->query(
            'SELECT `option_id`, COUNT(*) AS cnt
               FROM `#__knowledge_poll_votes`
              WHERE `poll_id` = ?
              GROUP BY `option_id`',
            [$poll->id]
        )->rows;
        $total = 0;
        foreach ($tallyRows as $tr) {
            $oid = (int) $tr['option_id'];
            $cnt = (int) $tr['cnt'];
            $poll->tallies[$oid] = $cnt;
            $total += $cnt;
        }
        $poll->totalVotes = $total;

        if ($voterKey !== null && $voterKey !== '') {
            $vote = $this->db->query(
                'SELECT `option_id` FROM `#__knowledge_poll_votes`
                  WHERE `poll_id` = ? AND `voter_key` = ? LIMIT 1',
                [$poll->id, $voterKey]
            )->row;
            if ($vote !== []) {
                $poll->votedOptionId = (int) $vote['option_id'];
            }
        }

        return $poll;
    }

    /** @param array<string, string> $questions */
    private function syncQuestions(int $pollId, array $questions): void
    {
        $this->db->query('DELETE FROM `#__knowledge_poll_translations` WHERE `poll_id` = ?', [$pollId]);
        foreach ($this->configuredLocales() as $locale) {
            $question = trim((string) ($questions[$locale] ?? ''));
            if ($question === '') {
                continue;
            }
            $this->db->insert('knowledge_poll_translations', [
                'poll_id'  => $pollId,
                'locale'   => $locale,
                'question' => mb_substr($question, 0, 500),
            ]);
        }
    }

    /**
     * @param list<array{id?:int,labels?:array<string,string>}> $options
     */
    private function syncOptions(int $pollId, array $options): void
    {
        $keepIds = [];
        $sort = 0;
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $labels = is_array($opt['labels'] ?? null) ? $opt['labels'] : [];
            $hasLabel = false;
            foreach ($this->configuredLocales() as $locale) {
                if (trim((string) ($labels[$locale] ?? '')) !== '') {
                    $hasLabel = true;
                    break;
                }
            }
            if (!$hasLabel) {
                continue;
            }

            $optionId = (int) ($opt['id'] ?? 0);
            if ($optionId > 0) {
                $owned = $this->db->query(
                    'SELECT `id` FROM `#__knowledge_poll_options` WHERE `id` = ? AND `poll_id` = ? LIMIT 1',
                    [$optionId, $pollId]
                )->row;
                if ($owned === []) {
                    $optionId = 0;
                }
            }

            if ($optionId > 0) {
                $this->db->update('knowledge_poll_options', [
                    'sort_order' => $sort,
                ], ['id' => $optionId]);
            } else {
                $optionId = $this->db->insert('knowledge_poll_options', [
                    'poll_id'    => $pollId,
                    'sort_order' => $sort,
                ]);
            }
            $keepIds[] = $optionId;
            $sort++;

            $this->db->query(
                'DELETE FROM `#__knowledge_poll_option_translations` WHERE `option_id` = ?',
                [$optionId]
            );
            foreach ($this->configuredLocales() as $locale) {
                $label = trim((string) ($labels[$locale] ?? ''));
                if ($label === '') {
                    continue;
                }
                $this->db->insert('knowledge_poll_option_translations', [
                    'option_id' => $optionId,
                    'locale'    => $locale,
                    'label'     => mb_substr($label, 0, 255),
                ]);
            }
        }

        if ($keepIds === []) {
            $this->db->query('DELETE FROM `#__knowledge_poll_options` WHERE `poll_id` = ?', [$pollId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $this->db->query(
            "DELETE FROM `#__knowledge_poll_options`
              WHERE `poll_id` = ? AND `id` NOT IN ({$placeholders})",
            array_merge([$pollId], $keepIds)
        );
    }

    private function normalizeClosesAt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private function defaultLocale(): string
    {
        if (function_exists('knowledge_module_config')) {
            return knowledge_module_config()['default_locale'];
        }
        return 'en';
    }

    /** @return list<string> */
    private function configuredLocales(): array
    {
        if (function_exists('knowledge_module_config')) {
            return knowledge_module_config()['locales'];
        }
        return ['en', 'so', 'ar'];
    }
}
