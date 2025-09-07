<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Job that iterates records (in batches) and saves them to force formula recalculation.
 * Uses "id > lastId" pagination (no offset()).
 */
class RecalculateFormulas implements JobDataLess
{
    protected EntityManager $entityManager;
    protected ?Log $log = null;

    // --- CONFIG ---
    private const ENABLE_LOGGING = true;    // set to false to disable logging
    private const BATCH_SIZE = 200;         // adjust if DB/memory is under load

    private int $errorCount = 0;

    public function __construct(EntityManager $entityManager, ?Log $log = null)
    {
        $this->entityManager = $entityManager;
        $this->log = (self::ENABLE_LOGGING && $log !== null) ? $log : null;
    }

    public function run(): void
    {
        // --- CONFIG: change this to the entity types you want to recalc ---
        $entityTypes = [
            'CCustomer',
            'CCompany',
            'CContract'
            // add others as needed
        ];

        foreach ($entityTypes as $entityType) {
            $this->logInfo("Processing entity type '{$entityType}'");

            $repo = $this->entityManager->getRDBRepository($entityType);

            $lastId = null;
            $totalProcessed = 0;

            while (true) {
                $qb = $repo->where([])->order('id'); // stable ordering

                if ($lastId !== null) {
                    $qb->where(['id>' => $lastId]);
                }

                $collection = $qb->limit(self::BATCH_SIZE)->find();

                if (count($collection) === 0) {
                    $this->logInfo("Finished '{$entityType}', total {$totalProcessed} entities processed.");
                    break;
                }

                foreach ($collection as $entity) {
                    try {
                        $lastId = $entity->get('id'); // remember last processed ID
                        $this->entityManager->saveEntity(
                            $entity,
                            [ SaveOption::SILENT => true ] // silent save to avoid triggering user notifications
                        );
                        $totalProcessed++;
                    } catch (Throwable $e) {
                        $this->errorCount++;
                        $this->logError("Failed saving {$entityType} ID {$lastId}: " . $e->getMessage());
                    }
                }

                gc_collect_cycles(); // keep memory under control
            }
        }

        if ($this->errorCount > 0) {
            // this makes the error visible in Scheduled Jobs UI
            throw new \Exception("RecalculateFormulas finished with {$this->errorCount} errors. Check logs for details.");
        }
    }

    private function logInfo(string $message): void
    {
        if (!self::ENABLE_LOGGING) {
            return;
        }
        $this->log
            ? $this->log->info("RecalculateFormulas: {$message}")
            : error_log("[INFO] RecalculateFormulas: {$message}");
    }

    private function logError(string $message): void
    {
        if (!self::ENABLE_LOGGING) {
            return;
        }
        $this->log
            ? $this->log->error("RecalculateFormulas: {$message}")
            : error_log("[ERROR] RecalculateFormulas: {$message}");
    }
}