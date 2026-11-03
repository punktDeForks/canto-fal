<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\EventListener;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\Event\IncomingWebhookEvent;
use TYPO3Canto\CantoFal\Utility\CantoUtility;

/**
 * The asset is already gone in Canto, so the file is only marked as missing.
 * Deleting it through FAL would call the driver and send a delete request back to Canto.
 */
final class DeleteAssetWebhookEventListener
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly StorageRepository $storageRepository,
        private readonly ProcessedFileRepository $processedFileRepository,
        private readonly FileIndexRepository $fileIndexRepository,
    ) {}

    public function __invoke(IncomingWebhookEvent $event): void
    {
        if ($event->getType() !== IncomingWebhookEvent::ASSET_DELETION) {
            return;
        }
        $cantoStorages = $this->storageRepository->findByStorageType(CantoDriver::DRIVER_NAME);
        $identifier = CantoUtility::buildCombinedIdentifier($event->getScheme(), $event->getId());
        foreach ($this->findIndexedFiles($cantoStorages, $identifier) as $file) {
            foreach ($this->processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
                $processedFile->delete(true);
            }
            $this->fileIndexRepository->markFileAsMissing($file->getUid());
        }
    }

    /**
     * Only looks up already indexed files, so unknown identifiers never create new sys_file records.
     *
     * @param ResourceStorage[] $storages
     * @return File[]
     */
    private function findIndexedFiles(array $storages, string $identifier): array
    {
        $files = [];
        foreach ($storages as $storage) {
            $record = $this->fileIndexRepository->findOneByStorageAndIdentifier($storage, $identifier);
            if (is_array($record)) {
                $files[] = $this->resourceFactory->getFileObject((int)$record['uid'], $record);
            }
        }
        return $files;
    }
}
