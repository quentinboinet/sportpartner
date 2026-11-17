<?php

namespace App\Tests\Unit\Service\User;

use App\Entity\User;
use App\Service\User\AccountDeleter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Suppression du compte : une seule requête (DELETE FROM users, le reste suit par ON DELETE CASCADE, voir
 * AccountDeletionCoverageTest) dans une transaction, annulée à la moindre erreur. Base simulée : la vraie
 * base est couverte par l'E2E (comptage de chaque table avant / après pour deux utilisateurs).
 */
final class AccountDeleterTest extends TestCase
{
    /** @var list<string> Appels reçus par la connexion et le gestionnaire d'entités, dans l'ordre */
    private array $calls = [];

    /** @var list<array{sql: string, params: list<mixed>, types: list<mixed>}> */
    private array $statements = [];

    private static function user(?int $id = 42): User
    {
        $user = (new User())->setEmail('delete-test@example.invalid');
        if ($id !== null) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        }

        return $user;
    }

    /**
     * @param \Closure(): (int|string) $execute Résultat (ou exception) de executeStatement
     */
    private function deleter(\Closure $execute, ?\Throwable $commitError = null, ?User $expectDetached = null): AccountDeleter
    {
        $this->calls      = [];
        $this->statements = [];
        $inTransaction    = false;

        $conn = $this->createMock(Connection::class);
        $conn->method('beginTransaction')->willReturnCallback(function () use (&$inTransaction): void {
            $this->calls[] = 'begin';
            $inTransaction = true;
        });
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params = [], array $types = []) use ($execute): int|string {
            $this->calls[]      = 'execute';
            $this->statements[] = ['sql' => $sql, 'params' => array_values($params), 'types' => array_values($types)];

            return $execute();
        });
        $conn->method('commit')->willReturnCallback(function () use (&$inTransaction, $commitError): void {
            $this->calls[] = 'commit';
            if ($commitError !== null) {
                throw $commitError;
            }
            $inTransaction = false;
        });
        $conn->method('rollBack')->willReturnCallback(function () use (&$inTransaction): void {
            $this->calls[] = 'rollBack';
            $inTransaction = false;
        });
        $conn->method('isTransactionActive')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('detach')->willReturnCallback(function (object $entity) use ($expectDetached): void {
            $this->calls[] = 'detach';
            if ($expectDetached !== null) {
                $this->assertSame($expectDetached, $entity);
            }
        });
        // Aucune écriture par l'ORM : pas de remove()/flush() qui chargerait les collections
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        return new AccountDeleter($em);
    }

    public function testDeletesTheUserRowOnlyInOneTransaction(): void
    {
        $user = self::user();
        $this->deleter(static fn (): int => 1, null, $user)->delete($user);

        $this->assertSame(['begin', 'execute', 'commit', 'detach'], $this->calls);
        $this->assertCount(1, $this->statements);
        $this->assertSame('DELETE FROM users WHERE id = ?', $this->statements[0]['sql']);
        $this->assertSame([42], $this->statements[0]['params']);
        $this->assertSame([ParameterType::INTEGER], $this->statements[0]['types']);
    }

    public function testReferenceTablesAndOtherUsersAreNeverTargeted(): void
    {
        $user = self::user(7);
        $this->deleter(static fn (): int => 1)->delete($user);

        foreach ($this->statements as $statement) {
            // Une seule ligne visée, par sa clé : jamais de suppression sans condition ni par un autre critère
            $this->assertMatchesRegularExpression('/^DELETE FROM users WHERE id = \?$/', $statement['sql']);
            $this->assertSame([7], $statement['params']);
            foreach (['sports', 'gearBrand', 'healthExercise', 'healthExerciseText'] as $table) {
                $this->assertStringNotContainsStringIgnoringCase($table, $statement['sql']);
            }
        }
    }

    public function testDatabaseErrorRollsBackAndIsRethrown(): void
    {
        $error = new \RuntimeException('SQLSTATE[23000]: Cannot delete or update a parent row: a foreign key constraint fails');
        $deleter = $this->deleter(static function () use ($error): int {
            throw $error;
        });

        try {
            $deleter->delete(self::user());
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }
        $this->assertSame(['begin', 'execute', 'rollBack'], $this->calls);
    }

    public function testMissingUserRowRollsBack(): void
    {
        $deleter = $this->deleter(static fn (): int => 0);

        try {
            $deleter->delete(self::user());
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deleted 0', $e->getMessage());
        }
        $this->assertSame(['begin', 'execute', 'rollBack'], $this->calls);
    }

    public function testMoreThanOneRowRollsBack(): void
    {
        $deleter = $this->deleter(static fn (): string => '2');

        $this->expectException(\RuntimeException::class);
        try {
            $deleter->delete(self::user());
        } finally {
            $this->assertSame(['begin', 'execute', 'rollBack'], $this->calls);
        }
    }

    public function testCommitFailureRollsBack(): void
    {
        $deleter = $this->deleter(static fn (): int => 1, new \RuntimeException('Deadlock found when trying to get lock'));

        try {
            $deleter->delete(self::user());
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Deadlock', $e->getMessage());
        }
        $this->assertSame(['begin', 'execute', 'commit', 'rollBack'], $this->calls);
    }

    public function testUnsavedUserIsRefusedWithoutAnyQuery(): void
    {
        $deleter = $this->deleter(static fn (): int => 1);

        try {
            $deleter->delete(self::user(null));
            $this->fail('exception expected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('never saved', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }
}
