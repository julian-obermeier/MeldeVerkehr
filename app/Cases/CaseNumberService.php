<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cases;

use PDO;

final class CaseNumberService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function next(?int $year = null): string
    {
        $year ??= (int) gmdate('Y');

        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException('Case number allocation requires an active database transaction.');
        }

        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO case_sequences (sequence_year, next_number) VALUES (:year, 1)'
        );
        $insert->execute(['year' => $year]);

        $select = $this->pdo->prepare(
            'SELECT next_number FROM case_sequences WHERE sequence_year = :year FOR UPDATE'
        );
        $select->execute(['year' => $year]);
        $number = $select->fetchColumn();

        if ($number === false) {
            throw new \RuntimeException('Could not allocate case number.');
        }

        $update = $this->pdo->prepare(
            'UPDATE case_sequences SET next_number = next_number + 1 WHERE sequence_year = :year'
        );
        $update->execute(['year' => $year]);

        return sprintf('OWI-%04d-%06d', $year, (int) $number);
    }
}
