<?php

namespace Statamic\Console\Commands;

use Facades\Statamic\Console\Processes\Composer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Statamic\Console\EnhancesCommands;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\File;
use Statamic\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class InstallEloquentDriver extends Command
{
    use EnhancesCommands, RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statamic:install:eloquent-driver';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Install & configure Statamic's Eloquent Driver package";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! Composer::isInstalled('statamic/eloquent-driver')) {
            spin(
                callback: fn () => Composer::withoutQueue()->throwOnFailure()->require('statamic/eloquent-driver'),
                message: 'Installing the statamic/eloquent-driver package...'
            );

            $this->components->info('Installed statamic/eloquent-driver package');
        }

        if (! File::exists(config_path('statamic/eloquent-driver.php'))) {
            $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-config');
            $this->components->info('Config file [config/statamic/eloquent-driver.php] published successfully.');
        }

        if ($this->availableRepositories()->isEmpty()) {
            return $this->components->warn("No repositories left to migrate. You're already using the Eloquent Driver for all repositories.");
        }

        $repositories = multiselect(
            label: 'Which repositories would you like to migrate?',
            hint: 'You can always import other repositories later.',
            options: $this->availableRepositories()->all(),
            validate: fn (array $values) => count($values) === 0
                ? 'You must select at least one repository to migrate.'
                : null
        );

        foreach ($repositories as $repository) {
            $method = 'migrate'.Str::studly($repository);
            $this->$method();
        }
    }

    protected function availableRepositories(): Collection
    {
        return collect([
            'assets' => 'Assets',
            'blueprints' => 'Blueprints & Fieldsets',
            'collections' => 'Collections',
            'entries' => 'Entries',
            'forms' => 'Forms',
            'globals' => 'Globals',
            'navs' => 'Navigations',
            'revisions' => 'Revisions',
            'taxonomies' => 'Taxonomies',
        ])->reject(function ($value, $key) {
            switch ($key) {
                case 'assets':
                    return config('statamic.eloquent-driver.asset_containers.driver') === 'eloquent'
                        || config('statamic.eloquent-driver.assets.driver') === 'eloquent';

                case 'blueprints':
                    return config('statamic.eloquent-driver.blueprints.driver') === 'eloquent';

                case 'collections':
                    return config('statamic.eloquent-driver.collections.driver') === 'eloquent'
                        || config('statamic.eloquent-driver.collection_trees.driver') === 'eloquent';

                case 'entries':
                    return config('statamic.eloquent-driver.entries.driver') === 'eloquent';

                case 'forms':
                    return config('statamic.eloquent-driver.forms.driver') === 'eloquent';

                case 'globals':
                    return config('statamic.eloquent-driver.global_sets.driver') === 'eloquent'
                        || config('statamic.eloquent-driver.global_set_variables.driver') === 'eloquent';

                case 'navs':
                    return config('statamic.eloquent-driver.navigations.driver') === 'eloquent'
                        || config('statamic.eloquent-driver.navigation_trees.driver') === 'eloquent';

                case 'revisions':
                    return config('statamic.eloquent-driver.revisions.driver') === 'eloquent';

                case 'taxonomies':
                    return config('statamic.eloquent-driver.taxonomies.driver') === 'eloquent'
                        || config('statamic.eloquent-driver.terms.driver') === 'eloquent';
            }
        });
    }

    protected function migrateAssets(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-asset-migrations');
                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('asset_containers');
                $this->switchToEloquentDriver('assets');
            },
            message: 'Migrating assets...'
        );

        $this->components->info('Configured assets');

        if (confirm('Would you like to import existing assets?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-assets --force'),
                message: 'Importing existing assets...'
            );

            $this->components->info('Imported existing assets');
        }
    }

    protected function migrateBlueprints(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-blueprint-migrations');
                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('blueprints');
            },
            message: 'Migrating blueprints...'
        );

        $this->components->info('Configured blueprints');

        if (confirm('Would you like to import existing blueprints?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-blueprints'),
                message: 'Importing existing blueprints...'
            );

            $this->components->info('Imported existing blueprints');
        }
    }

    protected function migrateCollections(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-collection-migrations');
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-navigation-tree-migrations');

                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('collections');
                $this->switchToEloquentDriver('collection_trees');
            },
            message: 'Migrating collections...'
        );

        $this->components->info('Configured collections');

        if (confirm('Would you like to import existing collections?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-collections --force'),
                message: 'Importing existing collections...'
            );

            $this->components->info('Imported existing collections');
        }
    }

    protected function migrateEntries(): void
    {
        $shouldImportEntries = confirm('Would you like to import existing entries?');

        spin(
            callback: function () use ($shouldImportEntries) {
                $this->switchToEloquentDriver('entries');

                if ($shouldImportEntries) {
                    File::put(
                        config_path('statamic/eloquent-driver.php'),
                        Str::of(File::get(config_path('statamic/eloquent-driver.php')))
                            ->replace("'model'  => \Statamic\Eloquent\Entries\EntryModel::class", "'model'  => \Statamic\Eloquent\Entries\UuidEntryModel::class")
                            ->__toString()
                    );

                    $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-entries-table-with-string-ids');
                    $this->runArtisanCommand('migrate');

                    $this->runArtisanCommand('statamic:eloquent:import-entries');

                    return;
                }

                if (File::exists(base_path('content/collections/pages/home.md'))) {
                    File::delete(base_path('content/collections/pages/home.md'));
                }

                if (File::exists(base_path('content/trees/collections/pages.yaml'))) {
                    File::put(base_path('content/trees/collections/pages.yaml'), 'tree: {}');
                }

                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-entries-table');
                $this->runArtisanCommand('migrate');
            },
            message: $shouldImportEntries
                ? 'Migrating entries...'
                : 'Migrating and importing entries...'
        );

        $this->components->info(
            $shouldImportEntries
                ? 'Configured & imported existing entries'
                : 'Configured entries'
        );
    }

    protected function migrateForms(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-form-migrations');
                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('forms');
                $this->switchToEloquentDriver('form_submissions');
            },
            message: 'Migrating forms...'
        );

        $this->components->info('Configured forms');

        if (confirm('Would you like to import existing forms?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-forms'),
                message: 'Importing existing forms...'
            );

            $this->components->info('Imported existing forms');
        }
    }

    protected function migrateGlobals(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-global-migrations');
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-global-variables-migrations');
                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('global_sets');
                $this->switchToEloquentDriver('global_set_variables');
            },
            message: 'Migrating globals...'
        );

        $this->components->info('Configured globals');

        if (confirm('Would you like to import existing globals?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-globals'),
                message: 'Importing existing global variables...'
            );

            $this->components->info('Imported existing globals');
        }
    }

    protected function migrateNavs(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-navigation-migrations');
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-navigation-tree-migrations');

                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('navigations');
                $this->switchToEloquentDriver('navigation_trees');
            },
            message: 'Migrating navs...'
        );

        $this->components->info('Configured navs');

        if (confirm('Would you like to import existing navs?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-navs --force'),
                message: 'Importing existing navs...'
            );

            $this->components->info('Imported existing navs');
        }
    }

    protected function migrateRevisions(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-revision-migrations');
                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('revisions');
            },
        );

        $this->components->info('Configured revisions');

        if (confirm('Would you like to import existing revisions?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-revisions'),
                message: 'Importing existing revisions...'
            );

            $this->components->info('Imported existing revisions');
        }
    }

    protected function migrateTaxonomies(): void
    {
        spin(
            callback: function () {
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-taxonomy-migrations');
                $this->runArtisanCommand('vendor:publish --tag=statamic-eloquent-term-migrations');
                $this->runArtisanCommand('migrate');

                $this->switchToEloquentDriver('taxonomies');
                $this->switchToEloquentDriver('terms');
            },
            message: 'Migrating taxonomies...'
        );

        $this->components->info('Configured taxonomies');

        if (confirm('Would you like to import existing taxonomies?')) {
            spin(
                callback: fn () => $this->runArtisanCommand('statamic:eloquent:import-taxonomies --force'),
                message: 'Importing existing taxonomies...'
            );

            $this->components->info('Imported existing taxonomies');
        }
    }

    private function switchToEloquentDriver(string $repository): void
    {
        File::put(
            config_path('statamic/eloquent-driver.php'),
            Str::of(File::get(config_path('statamic/eloquent-driver.php')))
                ->replace(
                    "'{$repository}' => [\n        'driver' => 'file'",
                    "'{$repository}' => [\n        'driver' => 'eloquent'"
                )
                ->__toString()
        );
    }

    private function runArtisanCommand(string $command, bool $writeOutput = false): ProcessResult
    {
        $components = array_merge(
            [
                (new PhpExecutableFinder())->find(false) ?: 'php',
                defined('ARTISAN_BINARY') ? ARTISAN_BINARY : 'artisan',
            ],
            explode(' ', $command)
        );

        $result = Process::run($components, function ($type, $line) use ($writeOutput) {
            if ($writeOutput) {
                $this->output->write($line);
            }
        });

        // We're doing this instead of ->throw() so we can control the output of errors.
        if ($result->failed()) {
            if (Str::of($result->output())->contains('Unknown database')) {
                error('The database does not exist. Please create it before running this command.');
                exit(1);
            }

            error('Failed to run command: '.$command);
            $this->output->write($result->output());
            exit(1);
        }

        return $result;
    }
}
