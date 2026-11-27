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
		'revision'    => '24',
		'fingerprint' => '8eece47b140fbf78f8daeb2390d8a9eafccb895d',
	),
	'includes/class-ffc-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Activator',
		'revision'    => '1',
		'fingerprint' => 'e6dc1880e6768325d13f70e39a110c6eb6747527',
	),
	'includes/core/class-ffc-activity-log.php' => array(
		'class'       => 'FreeFormCertificate\\Core\\ActivityLog',
		'revision'    => '2.6.0',
		'fingerprint' => '843619444d0eff21532fbcbfc37ed4affc4710cf',
	),
	'includes/date-messages/class-ffc-date-messages-activator.php' => array(
		'class'       => 'FreeFormCertificate\\DateMessages\\DateMessagesActivator',
		'revision'    => '2',
		'fingerprint' => '6a6ac095f8fee6f2509b4427e7985b2b7ff05c3e',
	),
	'includes/frontend/class-ffc-certificate-terminal-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Frontend\\CertificateTerminalActivator',
		'revision'    => '1',
		'fingerprint' => 'c7e331201288b28686599993c40a9f045a1f9020',
	),
	'includes/recruitment/class-ffc-recruitment-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Recruitment\\RecruitmentActivator',
		'revision'    => '5',
		'fingerprint' => 'bd29612d5a8b799543fcf53d67181c16106cc563',
	),
	'includes/reregistration/class-ffc-reregistration-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Reregistration\\ReregistrationActivator',
		'revision'    => '1',
		'fingerprint' => 'b3e6dfa70fe7ce4cd7bff9ee87e406e6fec21225',
	),
	'includes/security/class-ffc-rate-limit-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Security\\RateLimitActivator',
		'revision'    => '1.7.0',
		'fingerprint' => 'c30cc56e738407c28f7cfe36a9fac53a133326fc',
	),
	'includes/self-scheduling/class-ffc-self-scheduling-activator.php' => array(
		'class'       => 'FreeFormCertificate\\SelfScheduling\\SelfSchedulingActivator',
		'revision'    => '4',
		'fingerprint' => '53fa9c46f890768ae85fd8c3637409be383368ab',
	),
	'includes/submissions/class-ffc-bulk-issue-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Submissions\\BulkIssueActivator',
		'revision'    => '1',
		'fingerprint' => '3f6f70e83a7c5157b8a8f98dc0fe22a6d21b1d3d',
	),
	'includes/submissions/class-ffc-certificate-signature-activator.php' => array(
		'class'       => 'FreeFormCertificate\\Submissions\\CertificateSignatureActivator',
		'revision'    => '1',
		'fingerprint' => '97dd2cb77982095aa6dc10dba04f0b4ec58ecb0e',
	),
	'includes/url-shortener/class-ffc-url-shortener-activator.php' => array(
		'class'       => 'FreeFormCertificate\\UrlShortener\\UrlShortenerActivator',
		'revision'    => '3',
		'fingerprint' => '16a8bdc88e96eb93bcc7150bd6197dcf78086684',
	),
	'includes/user-dashboard/class-ffc-user-dashboard-activator.php' => array(
		'class'       => 'FreeFormCertificate\\UserDashboard\\UserDashboardActivator',
		'revision'    => '2',
		'fingerprint' => 'b68466a5b332f40d4d413efa5cc9057833ba2cba',
	),
);
