<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Hook;
use Mops\Core\SectionRegistry;
use Mops\Tests\TestCase;

final class SectionRegistryTest extends TestCase
{
    private SectionRegistry $registry;

    protected function setUp(): void
    {
        Hook::reset();
        $this->registry = new SectionRegistry();
    }

    protected function tearDown(): void
    {
        Hook::reset();

        parent::tearDown();
    }

    public function test_registry_order_matches_the_spec(): void
    {
        $this->assertSame(
            ['profile', 'about', 'experience', 'projects', 'skills', 'education', 'certifications', 'awards', 'blog'],
            $this->registry->ids(),
        );
    }

    public function test_limits_are_hard_coded_in_the_registry(): void
    {
        $limits = [];

        foreach ($this->registry->all() as $section) {
            $limits[$section['id']] = $section['limit'];
        }

        $this->assertSame([
            'profile' => null,
            'about' => null,
            'experience' => 5,
            'projects' => 6,
            'skills' => 12,
            'education' => 3,
            'certifications' => 5,
            'awards' => 3,
            'blog' => 4,
        ], $limits);
    }

    public function test_fresh_install_defaults_enable_only_identity_and_bio(): void
    {
        $defaults = [];

        foreach ($this->registry->all() as $section) {
            $defaults[$section['id']] = $section['default'];
        }

        $this->assertTrue($defaults['profile']);
        $this->assertTrue($defaults['about']);

        foreach (['experience', 'projects', 'skills', 'education', 'certifications', 'awards', 'blog'] as $id) {
            $this->assertFalse($defaults[$id], "Section {$id} must be OFF on a fresh install.");
        }
    }

    public function test_profile_is_always_visible_and_has_no_toggle(): void
    {
        $profile = $this->registry->find('profile');

        $this->assertNotNull($profile);
        $this->assertTrue($profile['always_visible']);
        $this->assertFalse($profile['has_toggle']);
        $this->assertNull($profile['more_url']);
        $this->assertTrue($this->registry->isVisible($profile, false, 0));
    }

    /**
     * The spec pins the behaviour matrix: flag ON/OFF x total 0/n.
     */
    public function test_visibility_matrix(): void
    {
        $section = $this->registry->find('experience');
        $this->assertNotNull($section);

        // Flag OFF => hidden, even with data.
        $this->assertFalse($this->registry->isVisible($section, false, 12));
        // Flag OFF and no data => hidden.
        $this->assertFalse($this->registry->isVisible($section, false, 0));
        // Flag ON but empty => auto-hide.
        $this->assertFalse($this->registry->isVisible($section, true, 0));
        // Flag ON with data => visible.
        $this->assertTrue($this->registry->isVisible($section, true, 12));
    }

    public function test_more_url_only_when_there_is_more_to_show(): void
    {
        $section = $this->registry->find('projects');
        $this->assertNotNull($section);

        // Total 10, rendered 6 (limit) => button.
        $this->assertSame('/projects', $this->registry->moreUrlFor($section, 10, 6));
        // Total equals rendered => no button.
        $this->assertNull($this->registry->moreUrlFor($section, 6, 6));
        // Nothing rendered yet => no button.
        $this->assertNull($this->registry->moreUrlFor($section, 0, 0));
    }

    public function test_sections_without_detail_route_never_expose_more_url(): void
    {
        $about = $this->registry->find('about');
        $this->assertNotNull($about);

        $this->assertNull($this->registry->moreUrlFor($about, 10, 1));
    }

    public function test_toggleable_excludes_profile(): void
    {
        $ids = array_map(
            static fn (array $section): string => (string) $section['id'],
            $this->registry->toggleable(),
        );

        $this->assertNotContains('profile', $ids);
        $this->assertContains('about', $ids);
        $this->assertContains('blog', $ids);
    }

    public function test_setting_key_convention(): void
    {
        $this->assertSame('section_enabled_experience', $this->registry->settingKey('experience'));
    }

    public function test_plugins_can_extend_the_registry_through_a_filter(): void
    {
        Hook::addFilter('section_registry', static function (array $sections): array {
            $sections[] = [
                'id' => 'services',
                'title' => 'Layanan',
                'limit' => 4,
                'default' => false,
                'more_url' => '/services',
            ];

            return $sections;
        });

        $registry = new SectionRegistry();

        $this->assertContains('services', $registry->ids());
        $this->assertSame(10, count($registry->all()));
        $this->assertSame(4, $registry->find('services')['limit']);
    }
}
