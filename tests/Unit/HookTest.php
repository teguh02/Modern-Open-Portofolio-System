<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Hook;
use Mops\Tests\TestCase;

final class HookTest extends TestCase
{
    protected function setUp(): void
    {
        Hook::reset();
    }

    protected function tearDown(): void
    {
        Hook::reset();

        parent::tearDown();
    }

    public function test_actions_run_in_priority_order(): void
    {
        $order = [];

        Hook::addAction('test_hook', function () use (&$order): void {
            $order[] = 'late';
        }, 20);

        Hook::addAction('test_hook', function () use (&$order): void {
            $order[] = 'early';
        }, 5);

        Hook::doAction('test_hook');

        $this->assertSame(['early', 'late'], $order);
    }

    public function test_action_receives_arguments(): void
    {
        $received = [];

        Hook::addAction('save', function (int $id, string $name) use (&$received): void {
            $received = [$id, $name];
        });

        Hook::doAction('save', 7, 'Proyek');

        $this->assertSame([7, 'Proyek'], $received);
    }

    public function test_filters_chain_values(): void
    {
        Hook::addFilter('title', fn (string $value): string => $value . '!');
        Hook::addFilter('title', fn (string $value): string => strtoupper($value));

        $this->assertSame('MOPS!', Hook::applyFilters('title', 'mops'));
    }

    public function test_filter_receives_extra_arguments(): void
    {
        Hook::addFilter('price', fn (float $value, float $tax): float => $value * (1 + $tax));

        $this->assertEqualsWithDelta(110.0, Hook::applyFilters('price', 100.0, 0.1), 0.0001);
    }

    public function test_listeners_can_be_removed(): void
    {
        $calls = 0;
        $listener = function () use (&$calls): void {
            $calls++;
        };

        Hook::addAction('counted', $listener);
        Hook::doAction('counted');
        Hook::removeAction('counted', $listener);
        Hook::doAction('counted');

        $this->assertSame(1, $calls);
    }

    public function test_did_action_tracks_execution(): void
    {
        $this->assertFalse(Hook::didAction('never'));

        Hook::doAction('never');

        $this->assertTrue(Hook::didAction('never'));
        $this->assertSame(1, Hook::firedCount('never'));
    }

    public function test_unregistered_hook_is_not_registered(): void
    {
        $this->assertFalse(Hook::hasAction('nope'));
        $this->assertFalse(Hook::hasFilter('nope'));
    }
}
