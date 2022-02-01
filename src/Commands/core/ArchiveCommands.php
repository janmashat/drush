<?php

namespace Drush\Commands\core;

use Consolidation\SiteAlias\HostPath;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Backend\BackendPathEvaluator;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Sql\SqlBase;
use Drush\Utils\FsUtils;
use Exception;
use FilesystemIterator;
use Phar;
use PharData;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ArchiveCommands.
 */
class ArchiveCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use SiteAliasManagerAwareTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var string
     */
    private string $archiveDir;

    private const WEB_DOCROOT = 'web';

    private const CODE_ARCHIVE_ROOT_DIR = 'code';
    private const CODE_ARCHIVE_FILE_NAME = 'code.tar';

    private const DRUPAL_FILES_ARCHIVE_ROOT_DIR = 'files';
    private const DRUPAL_FILES_ARCHIVE_FILE_NAME = 'files.tar';

    private const SQL_DUMP_FILE_NAME = 'database.sql';
    private const DATABASE_ARCHIVE_ROOT_DIR = 'database';
    private const DATABASE_ARCHIVE_FILE_NAME = 'database.tar';

    private const ARCHIVES_DIR_NAME = 'archives';
    private const ARCHIVE_SUBDIR_NAME = 'archive';
    private const ARCHIVE_FILE_NAME = 'archive.tar';
    private const MANIFEST_FORMAT_VERSION = '1.0';
    private const MANIFEST_FILE_NAME = 'MANIFEST.yml';

    /**
     * ArchiveCommands constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();

        $this->filesystem = new Filesystem();

        $this->archiveDir = implode(
            [FsUtils::prepareBackupDir(self::ARCHIVES_DIR_NAME), DIRECTORY_SEPARATOR, self::ARCHIVE_SUBDIR_NAME]
        );
        $this->filesystem->mkdir($this->archiveDir);

        register_shutdown_function([$this, 'cleanUp']);
    }

    /**
     * Backup your code, files, and database into a single file.
     *
     * The following root-level directories would be excluded from a code archive:
     *  - "[docroot]/.git"
     *  - "[docroot]/vendor"
     *  - "[docroot]/modules/contrib"
     *  - "[docroot]/themes/contrib"
     *  - "[docroot]/profiles/contrib"
     *  - "[docroot]/sites/@/modules/contrib"
     *  - "[docroot]/sites/@/themes/contrib"
     *  - "[docroot]/sites/@/profiles/contrib"
     *  - "[docroot]/sites/@/files"
     *  - "[docroot]/sites/@/settings.@.php"
     *
     * In addition, the following directories would be excluded from code archive of a "web" docroot-based site:
     *  - "web" directory contents except for the following subdirectories:
     *  -- "web/modules"
     *  -- "web/themes"
     *  -- "web/profiles"
     *  -- "web/sites"
     *
     * The following directories would be excluded from a file archive:
     * - css
     * - js
     * - styles
     * - php
     *
     * @command archive:dump
     * @aliases ard
     *
     * @option code Archive codebase.
     * @option files Archive Drupal files.
     * @option db Archive database SQL dump.
     * @option destination The full path and filename in which the archive should be stored. If omitted, it will be saved to the drush-backups directory.
     * @option overwrite Overwrite destination file if exists.
     * @option description Describe the archive contents.
     * @option tags Add tags to the archive manifest. Delimit several by commas.
     * @option overwrite Do not fail if the destination file exists; overwrite it instead. Default is --no-overwrite.
     * @option generator The generator name to store in the MANIFEST.yml file. The default is "Drush archive-dump".
     * @option generatorversion The generator version number to store in the MANIFEST file. The default is Drush version.
     * @option exclude-code-paths Comma-separated list of paths to exclude from the code archive.
     *
     * @optionset_sql
     * @optionset_table_selection
     *
     * @bootstrap max configuration
     *
     * @param array $options
     *
     * @throws \Exception
     */
    public function dump(array $options = [
        'code' => false,
        'files' => false,
        'db' => false,
        'destination' => null,
        'overwrite' => false,
        'description' => null,
        'tags' => null,
        'generator' => null,
        'generatorversion' => null,
        'exclude-code-paths' => null,
    ]): void
    {
        if (!$options['code'] && !$options['files'] && !$options['db']) {
            $options['code'] = $options['files'] = $options['db'] = true;
        }

        $archiveComponents = [];

        if ($options['code']) {
            $excludes = $options['exclude-code-paths']
                ? $this->getExcludesByPaths(explode(',', $options['exclude-code-paths']))
                : [];

            $excludes = array_merge(
                $excludes,
                $this->getExcludesByPaths(
                    [
                        '.git',
                        'vendor',
                    ]
                ),
                $this->getDrupalExcludes()
            );

            if ($this->isWebRootSite()) {
                $excludes = array_merge(
                    $excludes,
                    $this->getWebDocrootDrupalExcludes()
                );
            }

            $archiveComponents[] = [
                'name' => 'code',
                'path' => $this->getProjectPath(),
                'excludes' => $excludes,
            ];
        }

        if ($options['files']) {
            $excludes = $this->getExcludesByPaths([
                'css',
                'js',
                'styles',
                'php',
            ]);

            $archiveComponents[] = [
                'name' => 'files',
                'path' => $this->getDrupalFilesComponentPath(),
                'excludes' => $excludes,
            ];
        }

        if ($options['db']) {
            $archiveComponents[] = [
                'name' => 'database',
                'path' => $this->getDatabaseComponentPath($options),
            ];
        }

        $this->createArchiveFile($archiveComponents, $options);
    }

    /**
     * Creates the archive file.
     *
     * @param array $archiveComponents
     *   The list of components (files) to include into the archive file.
     * @param array $options
     *   The command options.
     *
     * @throws \Exception
     */
    private function createArchiveFile(array $archiveComponents, array $options): void
    {
        if (!$archiveComponents) {
            throw new Exception(dt('Nothing to archive'));
        }

        $this->logger()->info(dt('Creating archive...'));
        $archivePath = implode([$this->archiveDir, DIRECTORY_SEPARATOR, self::ARCHIVE_FILE_NAME]);
        $archive = new PharData($archivePath);

        foreach ($archiveComponents as $component) {
            $path = $component['path'];
            $name = $component['name'];
            $excludes = $component['excludes'] ?? [];

            $this->logger()->info(dt('Adding !path to archive...', ['!path' => $path]));

            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(
                        $path,
                        FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME
                    ),
                    function ($file) use ($excludes, $path) {
                        $localFileName = str_replace($path . DIRECTORY_SEPARATOR, '', $file);

                        foreach ($excludes as $exclude) {
                            if (preg_match($exclude, $localFileName)) {
                                $this->logger()->info(dt(
                                    'Path excluded (!exclude): !path',
                                    ['!exclude' => $exclude, '!path' => $localFileName]
                                ));

                                return false;
                            }
                        }

                        $this->validateSensitiveData($file, $localFileName);

                        return true;
                    }
                )
            );

            $archive->addEmptyDir($name);
            foreach ($iterator as $file) {
                $localFileName = str_replace($path . DIRECTORY_SEPARATOR, '', $file);
                $archive->addFile($file, $name . DIRECTORY_SEPARATOR . $localFileName);
            }

            $this->logger()->info(
                dt('!path has been added to archive.', ['!path' => $path])
            );
        }

        $archive->addFile($this->createManifestFile($options), self::MANIFEST_FILE_NAME);

        $archive->compress(Phar::GZ);
        unset($archive);
        Phar::unlinkArchive($archivePath);
        $archivePath .= '.gz';

        if (!$options['destination']) {
            $this->logger()->success(
                dt('Archive file has been created: !path', ['!path' => $archivePath])
            );

            return;
        }

        if ($this->filesystem->exists($options['destination'])) {
            if (!$options['overwrite']) {
                throw new Exception(
                    sprintf(
                        'The destination file %s already exists. Use "--overwrite" option for overwriting an existing file.',
                        $options['destination']
                    )
                );
            }

            $this->filesystem->remove($options['destination']);
        }

        $this->logger()->info(
            dt(
                'Moving archive file from !from to !to',
                ['!from' => $archivePath, '!to' => $options['destination']]
            )
        );
        if (!rename($archivePath, $options['destination'])) {
            throw new Exception(
                sprintf(
                    'Failed moving archive from %s to %s.',
                    $archivePath,
                    $options['destination']
                )
            );
        }

        $this->logger()->success(
            dt('Archive file has been created: !path', ['!path' => $options['destination']])
        );
    }

    /**
     * Creates the MANIFEST file.
     *
     * @param array $options
     *   The command options.
     *
     * @return string
     */
    private function createManifestFile(array $options): string
    {
        $this->logger()->info(dt('Creating !manifest file...', ['!manifest' => self::MANIFEST_FILE_NAME]));
        $manifest = [
            'datestamp' => time(),
            'formatversion' => self::MANIFEST_FORMAT_VERSION,
            'components' => [
                'code' => $options['code'],
                'files' => $options['files'],
                'database' => $options['db'],
            ],
            'description' => $options['description'] ?? null,
            'tags' => $options['tags'] ?? null,
            'generator' => $options['generator'] ?? 'Drush archive:dump',
            'generatorversion' => $options['generatorversion'] ?? Drush::getVersion(),
        ];
        $manifestFilePath = $this->archiveDir . DIRECTORY_SEPARATOR . self::MANIFEST_FILE_NAME;
        file_put_contents(
            $manifestFilePath,
            Yaml::dump($manifest)
        );

        return $manifestFilePath;
    }

    /**
     * Returns TRUE is the site is a "web" docroot site.
     *
     * @return bool
     *
     * @throws \Exception
     */
    private function isWebRootSite(): bool
    {
        return self::WEB_DOCROOT === basename($this->siteAliasManager()->getSelf()->root());
    }

    /**
     * Returns the path to the site's project directory.
     *
     * @return string
     *  The full path to the site's project directory.
     *
     * @throws \Exception
     */
    private function getProjectPath(): string
    {
        return $this->isWebRootSite()
            ? dirname($this->siteAliasManager()->getSelf()->root())
            : $this->siteAliasManager()->getSelf()->root();
    }

    /**
     * Returns site's docroot name.
     *
     * @return string
     *
     * @throws \Exception
     */
    private function getDocrootName(): string
    {
        return $this->isWebRootSite() ? self::WEB_DOCROOT : '';
    }

    /**
     * Copies Drupal files into a temporary archive directory and returns the absolute path.
     *
     * @return string
     *  The full path to the Drupal files archive component directory.
     *
     * @throws \Exception
     */
    private function getDrupalFilesComponentPath(): string
    {
        $evaluatedPath = HostPath::create($this->siteAliasManager(), '%files');
        $pathEvaluator = new BackendPathEvaluator();
        $pathEvaluator->evaluate($evaluatedPath);

        $drupalFilesPath = $evaluatedPath->fullyQualifiedPath();
        $drupalFilesArchivePath = $this->archiveDir . DIRECTORY_SEPARATOR . self::DRUPAL_FILES_ARCHIVE_ROOT_DIR;
        $this->logger()->info(
            dt(
                'Copying Drupal files from !from_path to !to_path...',
                ['!from_path' => $drupalFilesPath, '!to_path' => $drupalFilesArchivePath]
            )
        );
        $this->filesystem->mirror($drupalFilesPath, $drupalFilesArchivePath);

        return $this->archiveDir . DIRECTORY_SEPARATOR . 'files';
    }

    /**
     * Creates a database archive (SQL dump) and returns the path the database archive component directory.
     *
     * @param array $options
     *
     * @return string
     *   The full path to the database archive component directory.
     *
     * @throws \Exception
     *
     * @see \Drush\Commands\sql\SqlCommands::dump()
     */
    private function getDatabaseComponentPath(array $options): string
    {
        $this->logger()->info(dt('Creating database SQL dump file...'));
        $databaseArchiveDir = implode([$this->archiveDir, DIRECTORY_SEPARATOR, self::DATABASE_ARCHIVE_ROOT_DIR]);
        $this->filesystem->mkdir($databaseArchiveDir);

        $options['result-file'] = implode([$databaseArchiveDir, DIRECTORY_SEPARATOR, self::SQL_DUMP_FILE_NAME]);
        $sql = SqlBase::create($options);
        if (false === $sql->dump()) {
            throw new Exception('Unable to dump database. Rerun with --debug to see any error message.');
        }

        return $databaseArchiveDir;
    }

    /**
     * Returns the list of regular expressions to match paths.
     *
     * @param array $paths
     *
     * @return array
     */
    private function getExcludesByPaths(array $paths): array
    {
        $regexps = [];
        foreach ($paths as $path) {
            $regexps[] = sprintf('#^%s$#', addslashes(trim($path)));
        }

        return $regexps;
    }

    /**
     * Returns docroot directory name with trailing escaped slash for a "web" docroot site for use in regular expressions, otherwise - empty string.
     *
     * @return string
     *
     * @throws \Exception
     */
    private function getDocrootRegexpPrefix(): string
    {
        return $this->getDocrootName() ? $this->getDocrootName() . '\/' : '';
    }

    /**
     * Returns the list of regular expressions to match Drupal files paths and sites/@/settings.@.php files.
     *
     * @return array
     *
     * @throws \Exception
     */
    private function getDrupalExcludes(): array
    {
        $excludes = [
            str_replace(
                '%docroot%',
                $this->getDocrootRegexpPrefix(),
                '#^%docroot%sites\/.+\/files$#'
            ),
            str_replace(
                '%docroot%',
                $this->getDocrootRegexpPrefix(),
                '#^%docroot%sites\/.+\/settings\..+\.php$#'
            ),
        ];

        return str_replace('/', DIRECTORY_SEPARATOR, $excludes);
    }

    /**
     * Returns the list of regular expressions to match Drupal contrib projects paths (modules, themes and profiles).
     *
     * @return array
     *
     * @throws \Exception
     */
    private function getWebDocrootDrupalExcludes(): array
    {
        $excludes = [
            str_replace(
                '%docroot%',
                $this->getDocrootRegexpPrefix(),
                '#^(%docroot%(?!modules|themes|profiles|sites)|%docroot%modules\/contrib$|%docroot%sites\/.+\/modules\/contrib$)#'
            ),
            str_replace(
                '%docroot%',
                $this->getDocrootRegexpPrefix(),
                '#^(%docroot%(?!modules|themes|profiles|sites)|%docroot%themes\/contrib$|%docroot%sites\/.+\/themes\/contrib$)#'
            ),
            str_replace(
                '%docroot%',
                $this->getDocrootRegexpPrefix(),
                '#^(%docroot%(?!modules|themes|profiles|sites)|%docroot%profiles\/contrib$|%docroot%sites\/.+\/profiles\/contrib$)#'
            ),
        ];

        return str_replace('/', DIRECTORY_SEPARATOR, $excludes);
    }

    /**
     * Validates files for sensitive data (database connection).
     *
     * Prevents creating a code archive containing a [docroot]/sites/@/settings.php file with database connection settings
     * defined.
     *
     * @param string $file
     *   The absolute path to the file.
     * @param string $localFileName
     *   The local (project-base) path to the file.
     *
     * @throws \Exception
     */
    private function validateSensitiveData(string $file, string $localFileName): void
    {
        $regexp = str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            sprintf('#^%ssites\/.*\/settings\.php$#', $this->getDocrootRegexpPrefix())
        );
        if (!preg_match($regexp, $localFileName)) {
            return;
        }

        if (!@include($file)) {
            throw new Exception(sprintf('Failed opening %s for validation', $file));
        }

        /** @var array $databases */
        if ($databases) {
            throw new Exception(
                sprintf(
                    'Found database connection settings in %s. It is risky to include them to the archive. Please move the database connection settings into a setting.*.php file or exclude them from the archive with "--exclude-code-paths=%s".',
                    $localFileName,
                    $localFileName
                )
            );
        }
    }

    /**
     * Performs clean-up tasks - deletes temporary files.
     */
    public function cleanUp(): void
    {
        $fs = new Filesystem();

        $pathsToDelete = [
            self::CODE_ARCHIVE_ROOT_DIR,
            self::DRUPAL_FILES_ARCHIVE_ROOT_DIR,
            self::DATABASE_ARCHIVE_ROOT_DIR,
            self::MANIFEST_FILE_NAME,
        ];

        try {
            foreach ($pathsToDelete as $dirToDelete) {
                $pathToDelete = $this->archiveDir . DIRECTORY_SEPARATOR . $dirToDelete;
                if (!$fs->exists($pathToDelete)) {
                    continue;
                }

                $this->logger()->info(dt('Deleting !path...', ['!path' => $pathToDelete]));
                $fs->remove($pathToDelete);
            }
        } catch (IOException $e) {
            $this->logger()->info(
                dt(
                    'Failed deleting !path: !message',
                    ['!path' => $pathToDelete, '!message' => $e->getMessage()]
                )
            );
        }
    }
}
