<?php

namespace App\Database;

use Closure;
use Illuminate\Database\SQLiteConnection;
use Throwable;

class SQLiteImmediateConnection extends SQLiteConnection
{
    /**
     * Run the statement to start a new transaction.
     *
     * @return void
     */
    protected function executeBeginTransactionStatement()
    {
        $mode = strtoupper($this->getConfig('transaction_mode') ?? 'IMMEDIATE');

        if (in_array($mode, ['IMMEDIATE', 'EXCLUSIVE', 'DEFERRED'], true)) {
            $this->getPdo()->exec("BEGIN {$mode} TRANSACTION");
            return;
        }

        $this->getPdo()->beginTransaction();
    }

    /**
     * Execute a Closure within a transaction.
     *
     * @param  \Closure(static): mixed  $callback
     * @param  int  $attempts
     * @return mixed
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (Throwable $e) {
                $this->handleTransactionException(
                    $e, $currentAttempt, $attempts
                );

                continue;
            }

            $levelBeingCommitted = $this->transactions;

            try {
                if ($this->transactions === 1) {
                    $this->fireConnectionEvent('committing');

                    if ($this->getPdo()->inTransaction()) {
                        $this->getPdo()->commit();
                    } else {
                        $this->getPdo()->exec('COMMIT');
                    }
                }

                $this->transactions = max(0, $this->transactions - 1);
            } catch (Throwable $e) {
                $this->handleCommitTransactionException(
                    $e, $currentAttempt, $attempts
                );

                continue;
            }

            $this->transactionsManager?->commit(
                $this->getName(),
                $levelBeingCommitted,
                $this->transactions
            );

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    /**
     * Commit the active database transaction.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function commit()
    {
        if ($this->transactionLevel() === 1) {
            $this->fireConnectionEvent('committing');

            if ($this->getPdo()->inTransaction()) {
                $this->getPdo()->commit();
            } else {
                $this->getPdo()->exec('COMMIT');
            }
        }

        [$levelBeingCommitted, $this->transactions] = [
            $this->transactions,
            max(0, $this->transactions - 1),
        ];

        $this->transactionsManager?->commit(
            $this->getName(), $levelBeingCommitted, $this->transactions
        );

        $this->fireConnectionEvent('committed');
    }

    /**
     * Perform a rollback within the database.
     *
     * @param  int  $toLevel
     * @return void
     *
     * @throws \Throwable
     */
    protected function performRollBack($toLevel)
    {
        if ($toLevel === 0) {
            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK');
            }
        } elseif ($this->queryGrammar->supportsSavepoints()) {
            $this->getPdo()->exec(
                $this->queryGrammar->compileSavepointRollBack('trans'.($toLevel + 1))
            );
        }
    }
}
