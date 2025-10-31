<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use RuntimeException;
use Throwable;

/**
 * Re-saves configured entities to force formula recalculation.
 */
class RecalculateFormulas implements JobDataLess
{
    private const CONFIG_KEY_ENTITY_TYPES = 'jobRecalculateFormulasEntityTypes';
    private const CONFIG_KEY_BATCH_SIZE = 'jobRecalculateFormulasBatchSize';
    private const DEFAULT_ENTITY_TYPES = [
        'CCustomer',
        'CCompany',
        'CContract',
    ];
    private const DEFAULT_BATCH_SIZE = 200;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private Config $config,
    ) {}

    public function run(): void
    {
        $entityTypeList = $this->getEntityTypeList();
        $batchSize = $this->getBatchSize();

        if ($entityTypeList === []) {
            $this->log->warning('Job RecalculateFormulas: No entity types configured, skipping.');

            return;
        }

        $startedAt = microtime(true);
        $totalProcessed = 0;
        $totalErrors = 0;

        foreach ($entityTypeList as $entityType) {
            if (!$this->entityManager->hasRepository($entityType)) {
                $this->log->warning(
                    sprintf('Job RecalculateFormulas: Repository for "%s" not found, skipping.', $entityType)
                );

                continue;
            }

            [$processed, $errors] = $this->processEntityType($entityType, $batchSize);

            $totalProcessed += $processed;
            $totalErrors += $errors;
        }

        $duration = microtime(true) - $startedAt;

        $this->log->info(
            sprintf(
                'Job RecalculateFormulas: Finished, %d record(s) processed in %.2f second(s).',
                $totalProcessed,
                $duration
            )
        );

        if ($totalErrors > 0) {
            throw new RuntimeException(
                sprintf('Job RecalculateFormulas finished with %d error(s). See log for details.', $totalErrors)
            );
        }
    }

    /**
     * @return array{int, int} [processed, errors]
     */
    private function processEntityType(string $entityType, int $batchSize): array
    {
        $repository = $this->entityManager->getRDBRepository($entityType);
        $lastId = null;
        $processed = 0;
        $errors = 0;

        $this->log->info(
            sprintf('Job RecalculateFormulas: Processing entity "%s" with batch size %d.', $entityType, $batchSize)
        );

        while (true) {
            $builder = $repository->order('id')->limit(null, $batchSize);

            if ($lastId !== null) {
                $builder = $builder->where(['id>' => $lastId]);
            }

            $collection = $builder->find();

            if (count($collection) === 0) {
                break;
            }

            foreach ($collection as $entity) {
                $entityId = $entity->get('id');

                if (!is_string($entityId) || $entityId === '') {
                    $this->log->warning(
                        sprintf('Job RecalculateFormulas: %s record without ID skipped.', $entityType)
                    );

                    continue;
                }

                $lastId = $entityId;

                try {
                    $this->entityManager->saveEntity($entity, [
                        SaveOption::SILENT => true,
                        SaveOption::NO_STREAM => true,
                        SaveOption::NO_NOTIFICATIONS => true,
                    ]);

                    $processed++;
                } catch (Throwable $exception) {
                    $errors++;

                    $this->log->error(
                        sprintf(
                            'Job RecalculateFormulas: Failed to save %s (%s): [%s] %s',
                            $entityType,
                            $entityId,
                            $exception->getCode(),
                            $exception->getMessage()
                        )
                    );
                }
            }
        }

        $this->log->info(
            sprintf('Job RecalculateFormulas: %s processed (%d record(s)).', $entityType, $processed)
        );

        return [$processed, $errors];
    }

    private function getEntityTypeList(): array
    {
        $configured = $this->config->get(self::CONFIG_KEY_ENTITY_TYPES, self::DEFAULT_ENTITY_TYPES);

        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }

        if (!is_array($configured)) {
            return self::DEFAULT_ENTITY_TYPES;
        }

        $filtered = array_values(
            array_filter(
                array_map(
                    static fn ($value) => is_string($value) ? trim($value) : '',
                    $configured
                ),
                static fn ($value) => $value !== ''
            )
        );

        return $filtered !== [] ? $filtered : self::DEFAULT_ENTITY_TYPES;
    }

    private function getBatchSize(): int
    {
        $value = $this->config->get(self::CONFIG_KEY_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);

        if (!is_int($value)) {
            $value = (int) $value;
        }

        return max(1, $value);
    }
}