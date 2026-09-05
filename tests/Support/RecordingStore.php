<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Support;

use DateTimeImmutable;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Submission\SubmissionRecord;
use IndexNowKit\Submission\SubmissionStoreInterface;

final class RecordingStore implements SubmissionStoreInterface
{
    /** @var list<SubmissionRecord> */
    public array $records = [];

    public function record(Result $result, DateTimeImmutable $at): void
    {
        $this->records[] = new SubmissionRecord($result->urls, $result, $at);
    }

    public function recent(int $limit = 100, ?string $host = null, ?ResultStatus $status = null): iterable
    {
        return array_reverse($this->records);
    }

    public function lastFor(string $url): ?SubmissionRecord
    {
        foreach (array_reverse($this->records) as $record) {
            if (\in_array($url, $record->urls, true)) {
                return $record;
            }
        }

        return null;
    }
}
