<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Hooks;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\SysLog\Action\Database as DatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogError;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Metadata\Exporter;
use TYPO3Canto\CantoFal\Resource\Repository\CantoRepository;

class DataHandlerHooks
{
    protected Registry $registry;

    protected FrontendInterface $cantoFolderCache;

    protected FrontendInterface $cantoFileCache;

    protected Exporter $exporter;

    public function __construct(
        Registry $registry,
        FrontendInterface $cantoFolderCache,
        FrontendInterface $cantoFileCache,
        Exporter $exporter
    ) {
        $this->registry = $registry;
        $this->cantoFolderCache = $cantoFolderCache;
        $this->cantoFileCache = $cantoFileCache;
        $this->exporter = $exporter;
    }

    /**
     * Exports only the fields DataHandler has actually written, so access checks and excluded fields are respected.
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($status !== 'update' || $table !== 'sys_file_metadata' || $fieldArray === []) {
            return;
        }
        try {
            $this->exporter->exportToCanto((int)$id, $fieldArray);
        } catch (\Throwable $exception) {
            // A failing Canto request must not abort the remaining DataHandler operations
            $dataHandler->log($table, (int)$id, DatabaseAction::UPDATE, null, SystemLogError::WARNING, 'Exporting metadata to Canto failed: %s', null, [$exception->getMessage()]);
        }
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->clearCantoCache($dataHandler);
    }

    protected function clearCantoCache(DataHandler $dataHandler): void
    {
        foreach ($dataHandler->datamap ?? [] as $table => $data) {
            if ($table === 'sys_file_storage') {
                foreach ($data as $uid => $values) {
                    if (($values['driver'] ?? '') === CantoDriver::DRIVER_NAME) {
                        $tag = sprintf('canto_storage_%s', $uid);
                        $this->cantoFileCache->flushByTag($tag);
                        $this->cantoFolderCache->flushByTag($tag);
                        $this->registry->removeAllByNamespace(CantoRepository::REGISTRY_NAMESPACE);
                    }
                }
            }
        }
    }
}
