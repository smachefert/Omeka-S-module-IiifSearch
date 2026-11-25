<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Set the visibility of the extracted text according to the module Access.
 *
 * The text of a resource is public only when the resource is public, free and
 * not under embargo. The text of an item requires the same for all its images.
 * It is the set-based version of ExtractOcr::isContentPublic(), used when the
 * access statuses change.
 */
class ContentVisibility
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param int[]|null $resourceIds Null to process all resources.
     * @return int Number of updated values.
     */
    public function update(int $propertyId, ?array $resourceIds): int
    {
        if ($resourceIds === null) {
            return $this->updateValues($propertyId, null);
        }

        $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
        if (!$resourceIds) {
            return 0;
        }

        // The text of an item depends on its images.
        $itemIds = [];
        foreach (array_chunk($resourceIds, 1000) as $ids) {
            $itemIds = array_merge($itemIds, $this->connection->executeQuery(
                'SELECT DISTINCT `item_id` FROM `media` WHERE `id` IN (:ids)',
                ['ids' => $ids],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )->fetchFirstColumn());
        }
        $resourceIds = array_values(array_unique(array_merge($resourceIds, array_map('intval', $itemIds))));

        $total = 0;
        foreach (array_chunk($resourceIds, 1000) as $ids) {
            $total += $this->updateValues($propertyId, $ids);
        }
        return $total;
    }

    protected function updateValues(int $propertyId, ?array $ids): int
    {
        $now = $this->connection->quote(date('Y-m-d H:i:s'));
        $isOpen = static fn (string $resource, string $status): string => <<<SQL
            (`$resource`.`is_public` = 1
                AND COALESCE(`$status`.`level`, 'free') = 'free'
                AND NOT COALESCE(
                    (`$status`.`embargo_start` IS NOT NULL OR `$status`.`embargo_end` IS NOT NULL)
                    AND (`$status`.`embargo_start` IS NULL OR `$status`.`embargo_start` <= $now)
                    AND (`$status`.`embargo_end` IS NULL OR `$status`.`embargo_end` >= $now),
                    0
                ))
            SQL;
        $isResourceOpen = $isOpen('resource', 'access_status');
        $isImageOpen = $isOpen('r_image', 'a_image');

        $params = ['property_id' => $propertyId];
        $types = [];
        $where = '';
        if ($ids !== null) {
            $where = 'AND `value`.`resource_id` IN (:ids)';
            $params['ids'] = $ids;
            $types['ids'] = Connection::PARAM_INT_ARRAY;
        }

        return (int) $this->connection->executeStatement(
            <<<SQL
            UPDATE `value`
            JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
            LEFT JOIN `access_status` ON `access_status`.`id` = `resource`.`id`
            SET `value`.`is_public` = (
                $isResourceOpen
                AND NOT EXISTS (
                    SELECT 1
                    FROM `media` `image`
                    JOIN `resource` `r_image` ON `r_image`.`id` = `image`.`id`
                    LEFT JOIN `access_status` `a_image` ON `a_image`.`id` = `image`.`id`
                    WHERE `image`.`item_id` = `resource`.`id`
                        AND `image`.`media_type` LIKE 'image/%'
                        AND `image`.`has_original` = 1
                        AND NOT $isImageOpen
                )
            )
            WHERE `value`.`property_id` = :property_id
                $where
            SQL,
            $params,
            $types
        );
    }
}
