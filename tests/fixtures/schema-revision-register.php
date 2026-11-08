<?php
/**
 * Schema revision register (#2430) — generated.
 * Regenerate: FFC_UPDATE_SCHEMA_REVISION_REGISTER=1 vendor/bin/phpunit --filter SchemaRevisionRegister
 * Each entry is a class that gates its schema on SCHEMA_REVISION, with the
 * revision and a fingerprint of the schema it declares. A schema change with
 * the same revision fails; regenerate only after bumping it.
 */

return array(
	'includes/audience/class-ffc-audience-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Audience\\AudienceActivator',
		'revision'    => '12',
		'fingerprint' => '4ffc0680ef12efbcfe6877b12f1608bd13819e8d',
	),
	'includes/class-ffc-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Activator',
		'revision'    => '1',
		'fingerprint' => 'e6dc1880e6768325d13f70e39a110c6eb6747527',
	),
	'includes/core/class-ffc-activity-log.php' => array(
		'class'       => 'FreeFormCertificate\\Core\\ActivityLog',
		'revision'    => '2.5.0',
		'fingerprint' => '12a904394fd0f7cc28b7973bd38fcb52d561e883',
	),
	'includes/date-messages/class-ffc-date-messages-activator.php' => array(
		'class'       => 'FreeFormCertificate\\DateMessages\\DateMessagesActivator',
		'revision'    => '1',
		'fingerprint' => '0548b5d771d26bb8adedb5a827dfb1e04b3bd78a',
	),
	'includes/recruitment/class-ffc-recruitment-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Recruitment\\RecruitmentActivator',
		'revision'    => '1',
		'fingerprint' => '3a5dce259d637ca3a9d64a6d618bb13dc5f6b5f8',
	),
	'includes/reregistration/class-ffc-reregistration-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Reregistration\\ReregistrationActivator',
		'revision'    => '1',
		'fingerprint' => 'b3e6dfa70fe7ce4cd7bff9ee87e406e6fec21225',
	),
	'includes/security/class-ffc-rate-limit-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Security\\RateLimitActivator',
		'revision'    => '1.6.0',
		'fingerprint' => '4720693ae5bd07c42bd8debcf1b21045bfbba228',
	),
	'includes/self-scheduling/class-ffc-self-scheduling-activator.php' => array(
		'class'       => 'FreeFormCertificate\\SelfScheduling\\SelfSchedulingActivator',
		'revision'    => '3',
		'fingerprint' => 'ca846edce458f1715522f82939b9606ead3aed76',
	),
	'includes/submissions/class-ffc-bulk-issue-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Submissions\\BulkIssueActivator',
		'revision'    => '1',
		'fingerprint' => '3f6f70e83a7c5157b8a8f98dc0fe22a6d21b1d3d',
	),
	'includes/url-shortener/class-ffc-url-shortener-activator.php' => array(
		'class'       => 'FreeFormCertificate\\UrlShortener\\UrlShortenerActivator',
		'revision'    => '2',
		'fingerprint' => '169e551648fd8de7627c53f6328164f1b36d9d13',
	),
	'includes/user-dashboard/class-ffc-user-dashboard-activator.php' => array(
		'class'       => 'FreeFormCertificate\\UserDashboard\\UserDashboardActivator',
		'revision'    => '2',
		'fingerprint' => 'b68466a5b332f40d4d413efa5cc9057833ba2cba',
	),
);
