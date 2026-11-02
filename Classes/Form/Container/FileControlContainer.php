<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Form\Container;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Form\Container\FilesControlContainer as FilesControlContainerCore;
use TYPO3\CMS\Backend\Form\InlineStackProcessor;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Filter\FileExtensionFilter;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FileControlContainer extends FilesControlContainerCore
{
    public function __construct(
        protected readonly IconFactory $iconFactory,
        protected readonly InlineStackProcessor $inlineStackProcessor,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly OnlineMediaHelperRegistry $onlineMediaHelperRegistry,
        protected readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
        protected readonly HashService $hashService,
    ) {
        // Set private parent properties
        parent::__construct($iconFactory, $inlineStackProcessor, $eventDispatcher, $onlineMediaHelperRegistry, $defaultUploadFolderResolver, $hashService);
    }

    /**
     * Generate buttons to select, reference and upload files.
     */
    protected function getFileSelectors(array $inlineStructure, array $inlineConfiguration, FileExtensionFilter $fileExtensionFilter): array
    {
        $rval = parent::getFileSelectors($inlineStructure, $inlineConfiguration, $fileExtensionFilter);
        /** @var  StorageRepository $service */
        $storageReporitory = GeneralUtility::makeInstance(StorageRepository::class);
        $storages = $storageReporitory->findAll();

        foreach ($storages as $storage) {
            if ($storage->getDriverType() === 'Canto' && $storage->getUid() > 0) {
                $newbuttonData = $this->renderAssetPickerButton($inlineStructure, $inlineConfiguration, $fileExtensionFilter, $storage->getUid(), $storage->getName());
                $rval[count($rval)] = $newbuttonData;
            }
        }

        return $rval;
    }

    /**
     * @param array<string, mixed> $inlineStructure
     * @param array<string, mixed> $inlineConfiguration
     */
    private function renderAssetPickerButton(array $inlineStructure, array $inlineConfiguration, FileExtensionFilter $fileExtensionFilter, int $storageId, string $storageName): string
    {
        $foreign_table = $inlineConfiguration['foreign_table'];
        $currentStructureDomObjectIdPrefix = $this->inlineStackProcessor->getDomObjectIdPrefixFromStructure(
            $inlineStructure,
            $this->data['inlineFirstPid']
        );
        $objectPrefix = $currentStructureDomObjectIdPrefix . '-' . $foreign_table;

        $title = 'Add file [' . $storageName . ']';

        // Core JS toggles the "hidden" attribute when references are added/removed, an inline style would stick
        $attributes = [
            'type' => 'button',
            'class' => 'btn btn-default t3js-element-browser',
            'hidden' => !($inlineConfiguration['inline']['showCreateNewRelationButton'] ?? true) ? 'hidden' : null,
            'data-mode' => 'cantosaas',
            'data-allowed-types' => implode(',', $fileExtensionFilter->getAllowedFileExtensions() ?? []),
            'data-disallowed-types' => implode(',', $fileExtensionFilter->getDisallowedFileExtensions() ?? []),
            'data-irre-object-id' => $objectPrefix,
            'data-entry-point' => $storageId . ':/',
            'title' => $title,
        ];

        return '
<button ' . GeneralUtility::implodeAttributes($attributes, true) . '>' . htmlspecialchars($title) . '</button > ';
    }
}
