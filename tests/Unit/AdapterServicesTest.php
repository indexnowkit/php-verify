<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Verify\Adapter\VerifyServices;
use IndexNowKit\Verify\Check\DispatchCheck;
use IndexNowKit\Verify\Check\SampleCheck;
use IndexNowKit\Verify\Check\TransportCheck;
use IndexNowKit\Verify\PageSignals;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\Tests\Support\Factory;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** `Verify\Adapter\VerifyServices`: what every framework adapter wires, in one place. */
final class AdapterServicesTest extends TestCase
{
    #[TestDox('package(), options() and config() are the predicate, the owned keys and the validated block of the adapters')]
    public function testPackageOptionsConfig(): void
    {
        $package = VerifyServices::package();
        self::assertSame('indexnowkit/verify', $package->package);
        self::assertSame(PageSignals::class, $package->marker);
        self::assertSame('verify', $package->feature);
        self::assertTrue($package->installed());
        self::assertFalse(VerifyServices::package(false)->installed());
        self::assertSame(VerifyConfig::OPTIONS, VerifyServices::options());

        $logger = new ArrayLogger();
        self::assertTrue(VerifyServices::config(['enabled' => true], $logger, 'php x check')->enabled);
        $broken = VerifyServices::config(['enabled' => true, 'redirect' => 'nope'], $logger, 'php x check');
        self::assertFalse($broken->enabled, 'a broken block switches the pre-flight off');
        self::assertStringContainsString('php x check', implode("\n", $logger->messages('critical')));
    }

    #[TestDox('over a runtime graph: the transport, the robots cache, the decorated submitter and factory, and the check lines with their texts')]
    public function testGraphPieces(): void
    {
        $logger = new ArrayLogger();
        $config = Factory::config(['dispatch' => 'sync']);
        $services = (new ServicesBuilder($config, $logger))->transport(new FakeTransport())->build();
        $verify = VerifyConfig::fromArray(['enabled' => true, 'redirect' => 'follow']);

        $transport = VerifyServices::transportFor($verify, $services);
        $robots = VerifyServices::robotsFor($verify, $services, $transport);
        self::assertInstanceOf(RobotsCache::class, $robots);
        self::assertInstanceOf(VerifyingSubmitter::class, VerifyServices::submitterFor($services->submitter(), $verify, $services, $transport, $robots, false));
        self::assertInstanceOf(VerifyingSubmitterFactory::class, VerifyServices::submitterFactoryFor($services->submitterFactory(), $verify, $services, $transport, $robots));
        self::assertInstanceOf(SubmitterFactoryInterface::class, VerifyServices::submitterFactoryFor($services->submitterFactory(), $verify, $services, $transport, $robots));

        self::assertSame('verify: enabled (redirect: follow, non_canonical: skip, origin_error: skip)', VerifyServices::installedLine($verify));
        self::assertSame('verify: installed, disabled (verify.enabled: false)', VerifyServices::installedLine(VerifyConfig::fromArray([])));

        $sample = VerifyServices::sampleCheck($transport, $verify, $services->normalizer(), $services->keys(), null, $robots);
        self::assertInstanceOf(SampleCheck::class, $sample([], []));

        $checks = VerifyServices::checksFor($verify, $services, 'queue', new StaticCheck(\IndexNowKit\Check\CheckLevel::Ok, 'sample'));
        self::assertCount(4, $checks);
        [$installed, $dispatch, $transportCheck] = $checks;
        self::assertInstanceOf(StaticCheck::class, $installed);
        self::assertInstanceOf(DispatchCheck::class, $dispatch);
        self::assertInstanceOf(TransportCheck::class, $transportCheck);
        $report = new CheckReport();
        $installed->check($report);
        $dispatch->check($report);
        self::assertSame('verify.installed', $report->items()[0]->code);
        self::assertTrue($report->hasWarnings(), 'dispatch: sync with the pre-flight on is a warning');
        self::assertStringContainsString('queue', $report->items()[1]->message, 'the framework word for the asynchronous dispatch');
        self::assertCount(3, VerifyServices::checksFor($verify, $services, 'messenger'), 'no sample check when the adapter has none');
    }
}
