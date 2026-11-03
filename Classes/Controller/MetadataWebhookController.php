<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3Canto\CantoApi\Http\Asset\SearchRequest;
use TYPO3Canto\CantoFal\Resource\Event\IncomingWebhookEvent;

final class MetadataWebhookController extends ActionController
{
    private const TOKEN_TYPES = [
        'metadata_hook_token' => IncomingWebhookEvent::METADATA_UPDATE,
        'newversion_hook_token' => IncomingWebhookEvent::ASSET_VERSION_UPDATE,
        'deletion_hook_token' => IncomingWebhookEvent::ASSET_DELETION,
    ];

    private const SCHEMES = [
        SearchRequest::SCHEME_IMAGE,
        SearchRequest::SCHEME_VIDEO,
        SearchRequest::SCHEME_AUDIO,
        SearchRequest::SCHEME_DOCUMENT,
        SearchRequest::SCHEME_PRESENTATION,
        SearchRequest::SCHEME_OTHER,
    ];

    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration) {}

    public function indexAction(): ResponseInterface
    {
        if ($this->request->getMethod() !== 'POST') {
            return $this->errorResponse(405, 'Method not allowed');
        }

        $json = $this->getBody();
        if ($json === null) {
            return $this->errorResponse(400, 'Invalid payload');
        }

        $token = $json['secure_token'];
        $type = $this->resolveType($token);
        $event = IncomingWebhookEvent::fromJsonArray($json, $type);
        if ($type === IncomingWebhookEvent::CUSTOM) {
            // Unknown tokens are passed on so that custom listeners can verify them on their own
            $event->setToken($token);
        }

        $this->eventDispatcher->dispatch($event);

        return $this->jsonResponse(json_encode(['success' => true, 'type' => $type], JSON_THROW_ON_ERROR));
    }

    private function resolveType(string $token): string
    {
        foreach (self::TOKEN_TYPES as $configurationKey => $type) {
            $configuredToken = $this->extensionConfiguration->get('canto_fal', $configurationKey);
            if (is_string($configuredToken) && $configuredToken !== '' && hash_equals($configuredToken, $token)) {
                return $type;
            }
        }
        return IncomingWebhookEvent::CUSTOM;
    }

    /**
     * @return array{secure_token: string, scheme: string, album: string, displayname: string, id: string}|null
     */
    private function getBody(): ?array
    {
        try {
            $json = json_decode((string)$this->request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($json)) {
            return null;
        }

        $token = $json['secure_token'] ?? null;
        $scheme = $json['scheme'] ?? null;
        $id = $json['id'] ?? null;
        if (!is_string($token) || $token === ''
            || !in_array($scheme, self::SCHEMES, true)
            || !is_string($id) || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1
        ) {
            return null;
        }

        return [
            'secure_token' => $token,
            'scheme' => $scheme,
            'album' => is_string($json['album'] ?? null) ? $json['album'] : '',
            'displayname' => is_string($json['displayname'] ?? null) ? $json['displayname'] : '',
            'id' => $id,
        ];
    }

    private function errorResponse(int $status, string $message): ResponseInterface
    {
        return $this->jsonResponse(json_encode(['success' => false, 'error' => $message], JSON_THROW_ON_ERROR))
            ->withStatus($status);
    }
}
