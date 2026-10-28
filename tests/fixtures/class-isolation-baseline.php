<?php
/**
 * Class-level isolation baseline — generated (#2091).
 * Regenerate: FFC_UPDATE_ISOLATION_BASELINE=1 vendor/bin/phpunit --filter ProcessIsolationScope
 * Each entry is a test file that runs every test in its own process,
 * almost always because setUp() declares an alias: or overload: mock.
 * The purpose is to reduce the tests that pay for their own process:
 * this list may only shrink.
 * A file not listed here fails; a listed file that stopped isolating
 * fails until it is removed.
 *
 * This list is a debt register, not a target to grow.
 */

return array(
	'ActivityLogClearPlaintextMigrationStrategyTest.php',
	'ActivityLogEncryptIpMigrationStrategyTest.php',
	'ActivityLogLocationCleanupMigrationStrategyTest.php',
	'CertificateCapabilityBackfillMigrationStrategyTest.php',
	'CpfRfSplitMigrationStrategyTest.php',
	'EmailHashRehashMigrationStrategyTest.php',
	'FormProcessorGeofenceBeforeRateLimitTest.php',
	'FormProcessorNonceRecoveryTest.php',
	'FormProcessorRequiredPresenceOrderTest.php',
	'FrontendTest.php',
	'KeyRotationMigrationStrategyTest.php',
	'MigrationForeignKeysTest.php',
	'PreflightStatsServiceTest.php',
	'PreflightTelemetryTest.php',
	'ReregistrationEmailHandlerTest.php',
	'ReregistrationInvitationBatchTest.php',
	'ReregistrationReminderBatchTest.php',
	'SelfSchedulingAdminTest.php',
	'SelfSchedulingCPTTest.php',
	'SelfSchedulingCleanupHandlerTest.php',
	'SelfSchedulingEditorTest.php',
	'SensitiveFieldPolicyTest.php',
	'SubmissionHandlerTest.php',
	'UrlShortenerMetaBoxTest.php',
	'VerificationResponseRendererTest.php',
);
