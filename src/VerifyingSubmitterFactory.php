<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The pre-flight around the command submitters (`--force`, `--dry-run`): every submitter the decorated factory
 * creates is wrapped in a {@see VerifyingSubmitter}, so `indexnow:submit` and the entity commands verify like the
 * application does. The adapters register it in place of `Adapter\SubmitterFactory` when `verify.enabled` is on
 * and keep the undecorated factory for `sitemap --no-verify`.
 */
final class VerifyingSubmitterFactory implements SubmitterFactoryInterface
{
    /**
     * @param SubmitterFactoryInterface $inner the adapter's factory
     * @param TransportInterface        $transport the pre-flight transport ({@see VerifyingSubmitter::__construct()})
     */
    public function __construct(
        private readonly SubmitterFactoryInterface $inner,
        private readonly TransportInterface $transport,
        private readonly VerifyConfig $config,
        private readonly KeyProviderInterface $keys,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?SubmissionStoreInterface $store = null,
        private readonly ?RobotsCache $robots = null,
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function create(bool $force, bool $dryRun): SubmitterInterface
    {
        return new VerifyingSubmitter($this->inner->create($force, $dryRun), $this->transport, $this->config, $this->keys, $this->normalizer, $this->logger, $this->events, $this->store, $this->robots, $this->clock);
    }
}
