<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Composer\Console\Input\InputArgument;
use Composer\Console\Input\InputOption;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Util\Fair\MetadataFetcher;
use Composer\Util\Fair\PlcDidResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds a FAIR package to the project by DID, automatically updating both the
 * repositories list and the require section of composer.json in one step.
 *
 * Usage:
 *   composer fair-require did:plc:afjf7gsjzsqmgc7dlhb553mv
 *   composer fair-require did:plc:afjf7gsjzsqmgc7dlhb553mv --vendor=acme --constraint="^1.0"
 *   composer fair-require did:plc:afjf7gsjzsqmgc7dlhb553mv --dry-run
 *
 * @author FAIR Contributors
 */
final class FairRequireCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('fair-require')
            ->setDescription('Require a FAIR package by its Decentralized Identifier (DID)')
            ->setDefinition([
                new InputArgument(
                    'did',
                    InputArgument::REQUIRED,
                    'The DID of the package (e.g. did:plc:abc123)',
                ),
                new InputOption(
                    'vendor',
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Vendor prefix used when the package name is derived from the metadata slug',
                    'fair',
                ),
                new InputOption(
                    'constraint',
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Version constraint to require (defaults to *)',
                    '*',
                ),
                new InputOption(
                    'dry-run',
                    null,
                    InputOption::VALUE_NONE,
                    'Preview what would be changed without writing anything',
                ),
            ])
            ->setHelp(<<<'EOT'
The <info>fair-require</info> command resolves a FAIR package DID, fetches its
metadata, and then updates <comment>composer.json</comment> in a single step —
writing both the <comment>repositories</comment> entry and the <comment>require</comment>
entry so you never have to declare the same package twice.

  <info>composer fair-require did:plc:afjf7gsjzsqmgc7dlhb553mv</info>

Use <comment>--vendor</comment> to control the vendor prefix when the package
name is derived from the metadata slug (default: <comment>fair</comment>):

  <info>composer fair-require did:plc:abc123 --vendor=acme</info>

Use <comment>--dry-run</comment> to preview what would happen without making changes:

  <info>composer fair-require did:plc:abc123 --dry-run</info>
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();

        /** @var string $did */
        $did = $input->getArgument('did');
        /** @var string $vendor */
        $vendor = $input->getOption('vendor');
        /** @var string $constraint */
        $constraint = $input->getOption('constraint');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!str_starts_with($did, 'did:plc:')) {
            $io->writeError('<error>Invalid DID format. Only did:plc: DIDs are currently supported.</error>');

            return 1;
        }

        $composer = $this->requireComposer();
        $httpDownloader = $composer->getLoop()->getHttpDownloader();

        // ------------------------------------------------------------------
        // Step 1: Resolve DID → service endpoint
        // ------------------------------------------------------------------
        $io->write(sprintf('  - Resolving <info>%s</info>...', $did));

        $resolver = new PlcDidResolver($httpDownloader);
        try {
            $didDocument = $resolver->resolve($did);
        } catch (\Throwable $e) {
            $io->writeError(sprintf('<error>Failed to resolve DID: %s</error>', $e->getMessage()));

            return 1;
        }

        $serviceEndpoint = $didDocument->getServiceEndpoint();
        if ($serviceEndpoint === null) {
            $io->writeError('<error>DID has no FairPackageManagementRepo service endpoint.</error>');

            return 1;
        }

        // ------------------------------------------------------------------
        // Step 2: Fetch metadata → derive package name
        // ------------------------------------------------------------------
        $io->write(sprintf('  - Fetching metadata from <info>%s</info>...', $serviceEndpoint));

        $fetcher = new MetadataFetcher($httpDownloader);
        try {
            $metadata = $fetcher->fetch($serviceEndpoint);
        } catch (\Throwable $e) {
            $io->writeError(sprintf('<error>Failed to fetch FAIR metadata: %s</error>', $e->getMessage()));

            return 1;
        }

        $packageName = $vendor . '/' . $metadata->slug;
        $latestVersion = $metadata->releases[0]->version ?? 'unknown';

        $io->write(sprintf(
            '  - Found <info>%s</info> (<comment>%s</comment>) — latest: <comment>%s</comment>',
            $metadata->name,
            $packageName,
            $latestVersion,
        ));

        if ($dryRun) {
            $io->write('');
            $io->write('<comment>Dry run — no changes written.</comment>');
            $io->write('');
            $io->write('Would add to <comment>composer.json</comment>:');
            $io->write(sprintf('  repositories: { type: fair, packages: { %s: %s } }', $packageName, $did));
            $io->write(sprintf('  require:       %s: %s', $packageName, $constraint));

            return 0;
        }

        // ------------------------------------------------------------------
        // Step 3: Update composer.json
        // ------------------------------------------------------------------
        $composerJsonPath = Factory::getComposerFile();
        $json = new JsonFile($composerJsonPath);

        $contents = file_get_contents($composerJsonPath);
        if ($contents === false) {
            $io->writeError('<error>Could not read composer.json</error>');

            return 1;
        }

        $manipulator = new JsonManipulator($contents);
        $decoded = $json->read();

        // Find an existing fair repository to append to, or create a new one.
        $fairRepoIndex = $this->findFairRepositoryIndex($decoded);

        if ($fairRepoIndex !== null) {
            // Merge the new package into the existing fair repository entry.
            $existingPackages = $decoded['repositories'][$fairRepoIndex]['packages'] ?? [];
            $existingPackages[$packageName] = $did;

            $updatedRepo = $decoded['repositories'][$fairRepoIndex];
            $updatedRepo['packages'] = $existingPackages;

            // Remove the old entry by list index, then append the updated one.
            // Pass '' as name so addRepository does not inject a "name" property.
            $manipulator->removeListItem('repositories', $fairRepoIndex);
            $manipulator->addRepository('', $updatedRepo);
        } else {
            // No fair repository yet — add a fresh one.
            // Pass '' as name so addRepository does not inject a "name" property.
            $manipulator->addRepository('', [
                'type'     => 'fair',
                'packages' => [$packageName => $did],
            ]);
        }

        // Add to require section.
        $manipulator->addLink('require', $packageName, $constraint);

        if (false === file_put_contents($composerJsonPath, $manipulator->getContents())) {
            $io->writeError('<error>Could not write composer.json</error>');

            return 1;
        }

        $io->write(sprintf(
            '  - <info>composer.json</info> updated: added <comment>%s</comment> (<comment>%s</comment>)',
            $packageName,
            $did,
        ));

        // ------------------------------------------------------------------
        // Step 4: Install the new package
        // ------------------------------------------------------------------
        $io->write('');
        $io->write(sprintf('Running <info>composer update %s</info>...', $packageName));
        $io->write('');

        // Re-initialize Composer from the freshly written composer.json so the
        // new repository and require entries are picked up by the solver.
        $this->resetComposer();
        $composer = $this->requireComposer();

        $install = Installer::create($io, $composer);
        $install
            ->setUpdate(true)
            ->setUpdateAllowList([$packageName])
            ->setUpdateAllowTransitiveDependencies(Installer::UPDATE_LISTED_WITH_TRANSITIVE_DEPS);

        return $install->run();
    }

    /**
     * Find the array index of an existing "fair" type repository in composer.json,
     * or null if none exists yet.
     *
     * @param array<string, mixed> $decoded
     */
    private function findFairRepositoryIndex(array $decoded): ?int
    {
        foreach ($decoded['repositories'] ?? [] as $index => $repo) {
            if (is_array($repo) && ($repo['type'] ?? '') === 'fair') {
                return (int) $index;
            }
        }

        return null;
    }
}
