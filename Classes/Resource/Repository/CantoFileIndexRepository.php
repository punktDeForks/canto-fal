<?php

declare(strict_types=1);

/*
 * This file is part of the "canto_saas_fal" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TYPO3Canto\CantoFal\Resource\Repository;

use Doctrine\DBAL\ParameterType;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class CantoFileIndexRepository extends FileIndexRepository
{
    private const TABLE = 'sys_file';

    protected array $fieldsWithTableNamePrefix;

    public function __construct(EventDispatcherInterface $eventDispatcher, ConnectionPool $connectionPool)
    {
        parent::__construct($eventDispatcher, $connectionPool);
        $this->fieldsWithTableNamePrefix = array_map(
            static function ($fieldName) {
                return sprintf('%s.%s', self::TABLE, $fieldName);
            },
            self::FIELDS
        );
    }

    public function findByFolder(Folder $folder): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLE);

        $result = $queryBuilder
            ->select(...$this->fieldsWithTableNamePrefix)
            ->from(self::TABLE)
            ->leftJoin(
                self::TABLE,
                'sys_file_canto_album',
                'sys_file_canto_album',
                $queryBuilder->expr()->eq(
                    self::TABLE . '.uid',
                    $queryBuilder->quoteIdentifier('sys_file_canto_album.file')
                )
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_file_canto_album.album',
                    $queryBuilder->createNamedParameter($folder->getIdentifier())
                ),
                $queryBuilder->expr()->eq(
                    self::TABLE . '.storage',
                    $queryBuilder->createNamedParameter($folder->getStorage()->getUid(), ParameterType::INTEGER)
                )
            )
            ->groupBy(self::TABLE . '.uid')
            ->executeQuery();

        $resultRows = [];
        while ($row = $result->fetchAssociative()) {
            $resultRows[$row['identifier']] = $row;
        }

        return $resultRows;
    }

    /**
     * @param Folder[] $folders
     */
    public function findByFolders(array $folders, bool $includeMissing = true, ?string $fileName = null): array
    {
        $storageUids = [];
        $folderIdentifiers = [];

        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }

            $storageUids[] = (int)$folder->getStorage()->getUid();
            $folderIdentifiers[] = $folder->getIdentifier();
        }

        $storageUids = array_unique($storageUids);
        $folderIdentifiers = array_unique($folderIdentifiers);

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLE);

        $queryBuilder
            ->select(...$this->fieldsWithTableNamePrefix)
            ->from(self::TABLE)
            ->leftJoin(
                self::TABLE,
                'sys_file_canto_album',
                'sys_file_canto_album',
                $queryBuilder->expr()->eq(
                    self::TABLE . '.uid',
                    $queryBuilder->quoteIdentifier('sys_file_canto_album.file')
                )
            )
            ->where(
                $queryBuilder->expr()->in(
                    'sys_file_canto_album.album',
                    $queryBuilder->createNamedParameter($folderIdentifiers, Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'storage',
                    $queryBuilder->createNamedParameter($storageUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy(self::TABLE . '.uid');

        if (isset($fileName)) {
            $nameParts = str_getcsv($fileName, ' ', '"', '');
            foreach ($nameParts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->like(
                            'name',
                            $queryBuilder->createNamedParameter(
                                '%' . $queryBuilder->escapeLikeWildcards($part) . '%',
                                ParameterType::STRING
                            )
                        )
                    );
                }
            }
        }

        if (!$includeMissing) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'missing',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        }

        $result = $queryBuilder->executeQuery();

        $fileRecords = [];
        while ($fileRecord = $result->fetchAssociative()) {
            $fileRecords[$fileRecord['identifier']] = $fileRecord;
        }

        return $fileRecords;
    }
}
