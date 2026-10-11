<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\AdminMenu;
use Mops\Tests\TestCase;
use RuntimeException;

final class AdminMenuTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminMenu::reset();

        parent::tearDown();
    }

    public function test_add_menu_page_registers_a_page(): void
    {
        $slug = add_menu_page('cv-settings', 'CV Settings', 'document', static fn (): string => '<p>hi</p>');

        $this->assertSame('cv-settings', $slug);

        $page = AdminMenu::find('cv-settings');

        $this->assertNotNull($page);
        $this->assertSame('CV Settings', $page['title']);
        $this->assertSame('document', $page['icon']);
        $this->assertSame('<p>hi</p>', ($page['renderer'])());
    }

    public function test_reserved_core_slugs_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved');

        add_menu_page('settings', 'Nope', 'cog', static fn (): string => '');
    }

    public function test_invalid_slug_pattern_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        add_menu_page('Bad Slug', 'Nope', 'cog', static fn (): string => '');
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        add_menu_page('dup', 'One', 'puzzle', static fn (): string => '');

        $this->expectException(RuntimeException::class);

        add_menu_page('dup', 'Two', 'puzzle', static fn (): string => '');
    }
}
