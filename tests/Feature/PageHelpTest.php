<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Ai\GuideLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every page with a header has a "?" that says what it is for, in English and Tagalog
 * (resources/js/help/page-help.json, keyed by the page's component).
 */
class PageHelpTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{title: string, en: string, tl: string, guides: list<string>, ask: list<string>}> */
    private function help(): array
    {
        return json_decode(File::get(resource_path('js/help/page-help.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_page_with_a_header_has_help_text(): void
    {
        $pagesDir = resource_path('js/Pages');
        $missing = collect(File::allFiles($pagesDir))
            ->filter(fn ($file) => $file->getExtension() === 'vue')
            ->reject(fn ($file) => Str::contains($file->getRelativePath(), ['components', 'partials', 'Partials']))
            ->filter(fn ($file) => Str::contains($file->getContents(), ['<ContentHeader', '<WalletLayout', '<PageHelp']))
            ->map(fn ($file) => str_replace('\\', '/', Str::beforeLast($file->getRelativePathname(), '.vue')))
            ->reject(fn ($component) => array_key_exists($component, $this->help()))
            ->values()
            ->all();

        $this->assertSame([], $missing, 'Add these pages to resources/js/help/page-help.json');
    }

    public function test_each_entry_says_what_the_page_is_for_in_both_languages(): void
    {
        $guides = app(GuideLibrary::class)->all();

        foreach ($this->help() as $component => $entry) {
            foreach (['title', 'en', 'tl'] as $field) {
                $this->assertNotSame('', trim($entry[$field] ?? ''), "{$component}: {$field} is empty");
            }
            $this->assertNotSame($entry['en'], $entry['tl'], "{$component}: the Tagalog is the English");
            foreach ($entry['guides'] ?? [] as $guide) {
                $this->assertArrayHasKey($guide, $guides, "{$component}: there is no guide \"{$guide}\"");
            }
            $this->assertTrue(File::exists(resource_path("js/Pages/{$component}.vue")), "{$component}: no such page");
        }
    }

    public function test_pages_get_the_titles_of_the_guides_the_user_may_follow(): void
    {
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
        $domain = Domain::query()->create([
            'name' => 'Help Org', 'name_slug' => 'help-org-'.Str::lower(Str::random(6)),
            'settings' => ['features' => ['finance' => true]],
        ]);
        InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $admin = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true]);

        $this->actingAs($admin)->get(route('domains.wallet.drawer', ['domain' => $domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('helpGuides.end-shift', 'Count the cash and end the shift'));
    }
}
