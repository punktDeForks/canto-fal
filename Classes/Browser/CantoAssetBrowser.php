<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Browser;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\ElementBrowser\AbstractElementBrowser;
use TYPO3\CMS\Backend\ElementBrowser\ElementBrowserInterface;
use TYPO3\CMS\Backend\Tree\View\LinkParameterProviderInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Canto\CantoFal\Resource\CantoClientFactory;
use TYPO3Canto\CantoFal\Resource\Driver\CantoDriver;
use TYPO3Canto\CantoFal\Resource\NoCantoStorageException;

final class CantoAssetBrowser extends AbstractElementBrowser implements ElementBrowserInterface, LinkParameterProviderInterface
{
    protected ResourceStorage $storage;

    public const IDENTIFIER = 'cantosaas';
    protected string $identifier = self::IDENTIFIER;

    /**
     * @throws NoCantoStorageException
     */
    protected function initialize(ServerRequestInterface $request): void
    {
        parent::initialize($request);

        $this->initializeStorage($request);
        $this->pageRenderer->loadJavaScriptModule('@typo3-canto/canto-fal/BrowseCantoAssetsV12.js');

        $this->pageRenderer->addCssFile(
            'EXT:canto_fal/Resources/Public/Css/CantoAssetBrowser.css'
        );
    }

    protected function getBodyTagAttributes(): array
    {
        return [
            'data-mode' => 'canto',
            'data-storage-uid' => (string)$this->storage->getUid(),
            'data-allowed-file-extensions' => $this->browserParameters->allowedTypes,
        ];
    }

    public function render(): string
    {
        $contentOnly = (bool)($this->getRequest()->getQueryParams()['contentOnly'] ?? false);
        $this->pageRenderer->setTitle($this->getLanguageService()->sL('LLL:EXT:canto_fal/Resources/Private/Language/locallang_be.xlf:canto_asset_browser.title'));

        $domain = $this->getAssetPickerDomain();

        /** @var CantoClientFactory $cantoClientFactory */
        $cantoClientFactory = GeneralUtility::makeInstance(CantoClientFactory::class);
        $client = $cantoClientFactory->createClientFromDriverConfiguration($this->storage->getConfiguration());

        $this->view->assignMultiple([
            'storage' => $this->storage,
            'assetPickerDomain' => $domain,
            'token' => $client->getAccessToken(),
        ]);

        $content = $this->view->render('CantoAssetBrowser/Search');
        if ($contentOnly) {
            return $content;
        }

        $this->pageRenderer->setBodyContent('<body ' . $this->getBodyTagParameters() . '>' . $content);
        return $this->pageRenderer->render();
    }

    /**
     * Needs to stay to prevent error with phpstan because of wrong parent return declaration.
     * @param mixed[] $data
     * @return array
     */
    public function processSessionData($data): array
    {
        return [$data, false];
    }

    public function getScriptUrl(): string
    {
        $thisScript = (string)$this->uriBuilder->buildUriFromRoute(
            $this->getRequest()->getAttribute('route')->getOption('_identifier')
        );
        return $thisScript;
    }

    public function getUrlParameters(array $values): array
    {
        return [
            'mode' => 'canto',
            'bparams' => $this->bparams,
        ];
    }

    public function isCurrentlySelectedItem(array $values): bool
    {
        return false;
    }

    /**
     * @throws NoCantoStorageException
     */
    protected function initializeStorage(ServerRequestInterface $request): void
    {
        // The storage is passed as entry point "<storageUid>:/" (TYPO3 14 drops additional bparams segments)
        $entryPoint = (string)($request->getQueryParams()['expandFolder'] ?? '');
        $storageId = str_contains($entryPoint, ':')
            ? (int)strstr($entryPoint, ':', true)
            : (int)(explode('|', (string)($request->getQueryParams()['bparams'] ?? ''))[5] ?? 0);
        $this->storage = $this->findStorageById($storageId);
    }

    /**
     * @throws NoCantoStorageException
     */
    protected function findStorageById(int $storageId): ResourceStorage
    {
        $storage = $this->getStorageRepository()->findByUid($storageId);
        if ($storage === null || $storage->getDriverType() !== CantoDriver::DRIVER_NAME) {
            throw new NoCantoStorageException('Invalid canto storage given.', 1628164687);
        }
        return $storage;
    }

    protected function getStorageRepository(): StorageRepository
    {
        return GeneralUtility::makeInstance(StorageRepository::class);
    }

    public function getAssetPickerDomain(): string
    {
        $domain = $this->storage->getConfiguration()['cantoDomain'] ?? '';
        if (!is_string($domain) || $domain === '') {
            throw new \Exception('Pixelboxx-Domain does not seem to be configured for %d', $this->storage->getUid());
        }
        return $domain;
    }
}
