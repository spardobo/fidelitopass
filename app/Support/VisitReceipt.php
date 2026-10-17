<?php

namespace App\Support;

use App\Exceptions\CustomerPassInspectionException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Reads original committed Visit presentation without accepting or retrying an operation. */
final class VisitReceipt
{
    /**
     * Receives the existing current-credential and owner inspection boundary.
     *
     * @param  CustomerPassInspector  $inspector  Read-only authority check; eligibility is not receipt authority.
     */
    public function __construct(private readonly CustomerPassInspector $inspector) {}

    /**
     * Finds one committed operation using current ownership and a current private credential.
     *
     * Null means unknown or unconfirmed, never proof of rollback or permission to retry.
     * Consumers already holding accepted Visit facts must retain them if this ancillary read fails.
     *
     * @param  User|null  $actor  Server-authenticated owner, or null.
     * @param  string  $method  Existing token or manual credential method.
     * @param  string  $credential  Private input revalidated by the Inspector for this request.
     * @param  string  $operationId  UUID for the same intentional confirmation, not mutation authority.
     * @return array{
     *     visit_id: int,
     *     operation_id: string,
     *     customer_pass_id: int,
     *     promotion_id: int,
     *     points_awarded: int,
     *     confirmed_at: string,
     *     promotion: array{
     *         reward_title: string,
     *         reward_description: string|null,
     *         target_points: int,
     *         earned_points: int,
     *         reward_unlocked: bool
     *     }
     * }|null Original Visit facts and frozen terms with progress through this Visit only.
     *
     * @throws CustomerPassInspectionException If current credential inspection or the receipt read fails.
     * @throws Throwable If native logging or an unexpected failure interrupts the read.
     */
    public function find(?User $actor, string $method, #[SensitiveParameter] string $credential, string $operationId): ?array
    {
        if (! Str::isUuid($operationId)) {
            return null;
        }

        $operationId = strtolower($operationId);
        $inspection = $this->inspector->inspect($actor, $method, $credential);

        // inspection owns its failures; only the additional receipt query is translated here
        try {
            $row = $this->readSnapshot($actor, $inspection['customer_pass_id'], $method, $credential, $operationId);

            return $row === null ? null : $this->buildResult($row);
        } catch (QueryException|PDOException $exception) {
            $failure = CustomerPassInspectionException::fromException($exception);
            $failure->log([
                'actor_id' => $actor?->id,
                'customer_pass_id' => $inspection['customer_pass_id'],
                'operation_id' => $operationId,
                'read_context' => 'visit_receipt',
                'outcome' => 'unavailable',
            ]);

            throw $failure;
        }
    }

    /**
     * Reads the original offer and insertion prefix in one scoped statement snapshot.
     *
     * The pass identity comes only from current inspection, never hydrated input. The same
     * statement reconfirms ownership and credential binding without another validity clock.
     *
     * @param  User|null  $actor  Actor already checked by the Inspector.
     * @param  int  $passId  Pass resolved by current credential inspection.
     * @param  string  $method  Credential method already allowlisted by the Inspector.
     * @param  string  $credential  Private input already validated, used only in a bound comparison.
     * @param  string  $operationId  Normalized valid operation UUID.
     * @return stdClass|null Explicit original facts, or no matching committed Visit.
     *
     * @throws QueryException If the statement fails.
     * @throws PDOException If the driver fails.
     */
    private function readSnapshot(
        ?User $actor,
        int $passId,
        string $method,
        #[SensitiveParameter] string $credential,
        string $operationId,
    ): ?stdClass {
        $column = $method === 'token' ? 'validation_token_hash' : 'manual_code';
        $value = $method === 'token' ? hash('sha256', $credential) : trim($credential);

        return DB::selectOne(<<<SQL
            SELECT visit.id AS visit_id, visit.operation_id, visit.customer_pass_id,
                   visit.promotion_id, visit.awarded_points, visit.confirmed_at,
                   promotion.reward_title, promotion.reward_description, promotion.target_points,
                   (SELECT SUM(prefix.awarded_points)
                    FROM visits AS prefix
                    WHERE prefix.business_id = visit.business_id
                      AND prefix.customer_pass_id = visit.customer_pass_id
                      AND prefix.promotion_id = visit.promotion_id
                      AND prefix.id <= visit.id) AS earned_points
            FROM visits AS visit
            JOIN customer_passes AS pass
                ON pass.id = visit.customer_pass_id AND pass.business_id = visit.business_id
            JOIN businesses AS business ON business.id = pass.business_id
            JOIN promotions AS promotion
                ON promotion.id = visit.promotion_id AND promotion.business_id = visit.business_id
            WHERE business.user_id = ? AND pass.id = ? AND pass.{$column} = ?
              AND visit.operation_id = CAST(? AS uuid)
            SQL, [$actor?->id, $passId, $value, $operationId]);
    }

    /**
     * Projects immutable facts without confusing historical unlock with current redeemability.
     *
     * Per-pass serialized acceptance makes Visit insertion order the committed progress prefix.
     * Frozen target and awarded points identify whether this Visit first crossed the target.
     *
     * @param  stdClass  $row  Explicit selected Visit, original Promotion and prefix facts.
     * @return array{visit_id: int, operation_id: string, customer_pass_id: int, promotion_id: int, points_awarded: int, confirmed_at: string, promotion: array{reward_title: string, reward_description: string|null, target_points: int, earned_points: int, reward_unlocked: bool}} Nonsecret receipt presentation.
     */
    private function buildResult(stdClass $row): array
    {
        $points = (int) $row->awarded_points;
        $earnedPoints = (int) $row->earned_points;
        $target = (int) $row->target_points;

        return [
            'visit_id' => (int) $row->visit_id,
            'operation_id' => (string) $row->operation_id,
            'customer_pass_id' => (int) $row->customer_pass_id,
            'promotion_id' => (int) $row->promotion_id,
            'points_awarded' => $points,
            'confirmed_at' => CarbonImmutable::parse($row->confirmed_at)->utc()->toISOString(),
            'promotion' => [
                'reward_title' => (string) $row->reward_title,
                'reward_description' => $row->reward_description === null ? null : (string) $row->reward_description,
                'target_points' => $target,
                'earned_points' => $earnedPoints,
                'reward_unlocked' => $earnedPoints >= $target && $earnedPoints - $points < $target,
            ],
        ];
    }
}
