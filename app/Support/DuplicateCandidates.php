<?php

namespace App\Support;

/**
 * 候補の一覧。表示は上限件数までで、超えた分は件数で知らせる（黙って切らない）。
 */
final readonly class DuplicateCandidates
{
    /**
     * @param  list<DuplicateCandidate>  $all  強さ・日付の近さの順
     */
    public function __construct(
        public array $all,
        public int $windowDays,
        public int $displayLimit,
    ) {}

    /**
     * @return list<DuplicateCandidate>
     */
    public function shown(): array
    {
        return array_slice($this->all, 0, $this->displayLimit);
    }

    public function hiddenCount(): int
    {
        return max(0, count($this->all) - $this->displayLimit);
    }

    /**
     * 気になる印に数える候補（強い・近い一致で、別の取引と判断されていない）。
     *
     * @return list<DuplicateCandidate>
     */
    public function concerning(): array
    {
        return array_values(array_filter($this->all, fn (DuplicateCandidate $c): bool => $c->raisesConcern()));
    }

    public function find(string $entryId): ?DuplicateCandidate
    {
        foreach ($this->all as $candidate) {
            if ($candidate->entryId === $entryId) {
                return $candidate;
            }
        }

        return null;
    }
}
