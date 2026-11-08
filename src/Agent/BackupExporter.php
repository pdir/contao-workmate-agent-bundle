<?php

declare(strict_types=1);

/*
 * pdir Workmate agent for Contao Open Source CMS
 *
 * Copyright (C) 2026 pdir / digital agentur // pdir GmbH
 *
 * @package    contao-workmate-agent-bundle
 * @license    proprietary
 * @author     pdir GmbH <develop@pdir.de>
 */

namespace Pdir\ContaoWorkmateAgentBundle\Agent;

/**
 * Creates a database backup with the Contao backup manager (like contao:backup:create) and writes it unzipped to a
 * file, works with Contao 4.13 (backup directory) and 5.x (virtual filesystem, readStream()).
 */
class BackupExporter
{
    /**
     * @var object|null Contao\CoreBundle\Doctrine\Backup\BackupManager
     */
    private $backupManager;

    public function __construct(?object $backupManager = null)
    {
        $this->backupManager = $backupManager;
    }

    /**
     * @return string the name of the backup
     */
    public function export(string $target): string
    {
        if (null === $this->backupManager) {
            throw new AgentException('Der Contao-Backup-Manager ist nicht verfügbar (ab Contao 4.13).');
        }

        $config = $this->backupManager->createCreateConfig();

        // uncompressed, Contao keeps the name "….sql.gz" for an uncompressed backup, so the name is changed as well
        if (method_exists($config, 'withGzCompression')) {
            $config = $config->withGzCompression(false);
            $name = (string) $config->getBackup()->getFilename();

            if ('.gz' === substr($name, -3) && method_exists($config, 'withFileName')) {
                $config = $config->withFileName(substr($name, 0, -3));
            }
        }

        $this->backupManager->create($config);
        $backup = $config->getBackup();

        if (method_exists($this->backupManager, 'readStream')) {
            $in = $this->backupManager->readStream($backup);
        } elseif (method_exists($backup, 'getFilepath')) {
            $in = fopen($backup->getFilepath(), 'rb');
        } else {
            throw new AgentException('Das Backup kann nicht gelesen werden.');
        }

        if (!\is_resource($in)) {
            throw new AgentException('Das Backup kann nicht gelesen werden.');
        }

        $gz = method_exists($config, 'isGzCompressionEnabled') ? $config->isGzCompressionEnabled() : '.gz' === substr((string) $backup->getFilename(), -3);

        self::write($in, $target, $gz);

        return (string) $backup->getFilename();
    }

    /**
     * @param resource $in
     */
    public static function write($in, string $target, bool $gz = false): void
    {
        $dir = \dirname($target);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new AgentException(sprintf('Das Verzeichnis %s konnte nicht angelegt werden.', basename($dir)));
        }

        if ($gz) {
            stream_filter_append($in, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
        }

        $tmp = $target.'.tmp';
        $out = fopen($tmp, 'wb');

        if (false === $out || false === stream_copy_to_stream($in, $out)) {
            throw new AgentException('Das Backup konnte nicht geschrieben werden.');
        }

        fclose($in);
        fclose($out);

        if (!rename($tmp, $target)) {
            throw new AgentException(sprintf('%s konnte nicht geschrieben werden.', basename($target)));
        }
    }
}
