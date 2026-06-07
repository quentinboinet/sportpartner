<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240005_DefaultNamingStrategy extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename columns to camelCase to match doctrine.orm.naming_strategy.default';
    }

    public function up(Schema $schema): void
    {
        // ── users ────────────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE users
            RENAME COLUMN first_name               TO firstName,
            RENAME COLUMN last_name                TO lastName,
            RENAME COLUMN strava_access_token      TO stravaAccessToken,
            RENAME COLUMN strava_refresh_token     TO stravaRefreshToken,
            RENAME COLUMN strava_token_expires_at  TO stravaTokenExpiresAt,
            RENAME COLUMN strava_athlete_id        TO stravaAthleteId,
            RENAME COLUMN stripe_customer_id       TO stripeCustomerId,
            RENAME COLUMN stripe_subscription_id   TO stripeSubscriptionId,
            RENAME COLUMN subscription_plan        TO subscriptionPlan,
            RENAME COLUMN email_verified           TO emailVerified,
            RENAME COLUMN email_verification_token TO emailVerificationToken,
            RENAME COLUMN created_at               TO createdAt,
            RENAME COLUMN updated_at               TO updatedAt
        ');

        // ── activities ───────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE activities
            RENAME COLUMN strava_id              TO stravaId,
            RENAME COLUMN distance_meters        TO distanceMeters,
            RENAME COLUMN moving_time_seconds    TO movingTimeSeconds,
            RENAME COLUMN elapsed_time_seconds   TO elapsedTimeSeconds,
            RENAME COLUMN total_elevation_gain   TO totalElevationGain,
            RENAME COLUMN average_heartrate      TO averageHeartrate,
            RENAME COLUMN max_heartrate          TO maxHeartrate,
            RENAME COLUMN average_cadence        TO averageCadence,
            RENAME COLUMN average_speed          TO averageSpeed,
            RENAME COLUMN start_date             TO startDate,
            RENAME COLUMN created_at             TO createdAt
        ');

        // ── sports ───────────────────────────────────────────────────────────
        $this->addSql('ALTER TABLE sports RENAME COLUMN sort_order TO sortOrder');

        // ── athlete_profiles ─────────────────────────────────────────────────
        $this->addSql('ALTER TABLE athlete_profiles
            RENAME COLUMN resting_hr TO restingHr,
            RENAME COLUMN max_hr     TO maxHr
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users
            RENAME COLUMN firstName              TO first_name,
            RENAME COLUMN lastName               TO last_name,
            RENAME COLUMN stravaAccessToken      TO strava_access_token,
            RENAME COLUMN stravaRefreshToken     TO strava_refresh_token,
            RENAME COLUMN stravaTokenExpiresAt   TO strava_token_expires_at,
            RENAME COLUMN stravaAthleteId        TO strava_athlete_id,
            RENAME COLUMN stripeCustomerId       TO stripe_customer_id,
            RENAME COLUMN stripeSubscriptionId   TO stripe_subscription_id,
            RENAME COLUMN subscriptionPlan       TO subscription_plan,
            RENAME COLUMN emailVerified          TO email_verified,
            RENAME COLUMN emailVerificationToken TO email_verification_token,
            RENAME COLUMN createdAt              TO created_at,
            RENAME COLUMN updatedAt              TO updated_at
        ');

        $this->addSql('ALTER TABLE activities
            RENAME COLUMN stravaId            TO strava_id,
            RENAME COLUMN distanceMeters      TO distance_meters,
            RENAME COLUMN movingTimeSeconds   TO moving_time_seconds,
            RENAME COLUMN elapsedTimeSeconds  TO elapsed_time_seconds,
            RENAME COLUMN totalElevationGain  TO total_elevation_gain,
            RENAME COLUMN averageHeartrate    TO average_heartrate,
            RENAME COLUMN maxHeartrate        TO max_heartrate,
            RENAME COLUMN averageCadence      TO average_cadence,
            RENAME COLUMN averageSpeed        TO average_speed,
            RENAME COLUMN startDate           TO start_date,
            RENAME COLUMN createdAt           TO created_at
        ');

        $this->addSql('ALTER TABLE sports RENAME COLUMN sortOrder TO sort_order');

        $this->addSql('ALTER TABLE athlete_profiles
            RENAME COLUMN restingHr TO resting_hr,
            RENAME COLUMN maxHr     TO max_hr
        ');
    }
}
