<?php

namespace Goldnead\EmailTemplates\Console;

use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\Brands;
use Illuminate\Console\Command;
use Statamic\Facades\Entry;

/**
 * Move email templates from one brand to another.
 *
 * The reason this exists: templates written before the brand field are filed
 * under the default brand on the first boot after the upgrade, because a
 * template with no brand is reachable by nobody and being wrong-but-visible
 * beats being right-but-invisible. That guess is usually wrong on an install
 * where the default brand is a placeholder and the real content belongs to
 * another — the hub, where six FamilyStack mails landed under `default`.
 *
 * Fixing that on six edit forms is six chances to miss one. Fixing it here is
 * one line, and `--dry-run` says what it would do before it does it.
 *
 *   php please email-templates:assign-brand familystack --from=default
 *   php please email-templates:assign-brand familystack --slug=welcome --slug=nurture-01
 */
class AssignBrandCommand extends Command
{
    protected $signature = 'email-templates:assign-brand
        {brand : The brand handle to file the templates under}
        {--from= : Only move templates currently filed under this brand handle}
        {--slug=* : Only move these slugs (repeatable)}
        {--dry-run : List what would change and write nothing}';

    protected $description = 'Move email templates to another brand.';

    public function handle(): int
    {
        if (! Brands::active()) {
            $this->error('This install is not running multi-brand, so templates have no brand to move.');

            return self::FAILURE;
        }

        $brand = (string) $this->argument('brand');
        $known = Brands::options();

        if (! isset($known[$brand])) {
            $this->error(sprintf(
                'Unknown brand [%s]. Known: %s',
                $brand,
                $known === [] ? '(none)' : implode(', ', array_keys($known)),
            ));

            return self::FAILURE;
        }

        $query = Entry::query()->where('collection', EmailTemplateCollectionManager::HANDLE);

        if ($from = $this->option('from')) {
            $query->where(Brands::FIELD, $from);
        }

        if ($slugs = array_filter((array) $this->option('slug'))) {
            $query->whereIn('slug', $slugs);
        }

        // The brand it is already filed under is not a move. Filtered here
        // rather than in the query so `--from` and this cannot contradict each
        // other into an empty result that looks like "nothing matched".
        $entries = $query->get()->filter(fn ($entry) => $entry->get(Brands::FIELD) !== $brand);

        if ($entries->isEmpty()) {
            $this->info('Nothing to move.');

            return self::SUCCESS;
        }

        foreach ($entries as $entry) {
            $this->line(sprintf(
                '  %s: %s → %s',
                $entry->slug(),
                $entry->get(Brands::FIELD) ?: '(none)',
                $brand,
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $entry->set(Brands::FIELD, $brand);
            $entry->saveQuietly();
        }

        $this->info(sprintf(
            '%s %d template(s).',
            $this->option('dry-run') ? 'Would move' : 'Moved',
            $entries->count(),
        ));

        return self::SUCCESS;
    }
}
