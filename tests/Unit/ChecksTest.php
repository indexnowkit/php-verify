<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Verify\Check\DispatchCheck;
use IndexNowKit\Verify\Check\TransportCheck;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ChecksTest extends TestCase
{
    #[TestDox('DispatchCheck warns with dispatch: sync naming the adapter\'s queue mode; TransportCheck warns about an application http.client')]
    public function testChecks(): void
    {
        self::assertSame([], self::lines(new DispatchCheck(false, 'queue')));
        self::assertSame(['warning verify.dispatch verify: verify.enabled with dispatch: sync fetches your own pages inside the web request; use dispatch: messenger'], self::lines(new DispatchCheck(true, 'messenger')));
        self::assertSame([], self::lines(new TransportCheck(true, null)));
        self::assertSame([], self::lines(new TransportCheck(false, 'app.http_client')));
        $lines = self::lines(new TransportCheck(true, 'app.http_client'));
        self::assertCount(1, $lines);
        self::assertStringStartsWith('warning verify.transport verify: the pre-flight uses http.client "app.http_client"', $lines[0]);
    }

    /**
     * @return list<string>
     */
    private static function lines(DispatchCheck|TransportCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->code . ' ' . $i->message, $report->items());
    }
}
