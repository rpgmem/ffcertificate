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
	'ActivityLogAjaxEndpointTest.php',
	'AdminAjaxTest.php',
	'AdminLoaderTest.php',
	'AdminSubmissionEditPageTest.php',
	'AdminUserCustomFieldsTest.php',
	'AppointmentHandlerUserLinkingTest.php',
	'AppointmentReminderScannerTest.php',
	'AppointmentRestControllerTest.php',
	'AudienceAdminAttendanceTest.php',
	'AudienceAdminAudienceTest.php',
	'AudienceAdminBookingsRenderTest.php',
	'AudienceAdminCalendarTest.php',
	'AudienceAdminEnvironmentTest.php',
	'AudienceCheckinTest.php',
	'AudienceCsvImporterEdgesTest.php',
	'AudienceEditorTabsInventoryTest.php',
	'AudienceFormInviteSendingTest.php',
	'AudienceNotificationHandlerFlowTest.php',
	'AudienceRestControllerTest.php',
	'AudienceSampleDownloadRouteTest.php',
	'AudienceShortcodeTest.php',
	'CalendarRestControllerTest.php',
	'CandidatePersisterTest.php',
	'DashboardShortcodeTest.php',
	'DateMessagesAdminPageTest.php',
	'DateMessagesAjaxEndpointTest.php',
	'DateMessagesLoaderTest.php',
	'DateMessagesRunnerTest.php',
	'FormCacheThirdPartyPurgeTest.php',
	'FormRestControllerTest.php',
	'LoaderMigrationsTest.php',
	'MigrationActionsAjaxEndpointTest.php',
	'PublicCsvDownloadAjaxHandlersTest.php',
	'PublicFormsExportSourceTest.php',
	'RateLimitGuardTest.php',
	'RateLimitStrategiesTest.php',
	'RecordAutosaveEntitiesTest.php',
	'RecruitmentAdminActionsTest.php',
	'RecruitmentCandidateEditPageHandlersTest.php',
	'RecruitmentCandidateEditPageTest.php',
	'RecruitmentCandidatesRestControllerTest.php',
	'RecruitmentClassificationsRestControllerTest.php',
	'RecruitmentDashboardSectionCallsTest.php',
	'RecruitmentEmailDispatcherSendTest.php',
	'RecruitmentPublicShortcodeListingTest.php',
	'RecruitmentPublicShortcodeRenderPipelineTest.php',
	'ReregistrationAdminRendererTest.php',
	'ReregistrationFrontendFlowTest.php',
	'ReregistrationLoaderTest.php',
	'SchedulingFrameControlsTest.php',
	'SelfSchedulingLoaderTest.php',
	'SettingsActionHandlerTest.php',
	'SettingsTest.php',
	'UserAudienceRestControllerTest.php',
	'UserProfileRestControllerTest.php',
	'VerificationHandlerAjaxTest.php',
);
