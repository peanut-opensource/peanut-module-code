export default {
  'installation.brand.alt': 'Peanut Module Code logo',
  'installation.title': 'Install Peanut Module Code',
  'installation.subtitle':
    'Complete the one-time setup to start using the admin console.',
  'installation.step.preflight': 'Preflight',
  'installation.step.identity': 'Initial identity',
  'installation.step.install': 'Install and verify health',
  'installation.mode': 'Deployment mode',
  'installation.mode.standalone': 'Standalone',
  'installation.mode.multiTenant': 'Multi-tenant',
  'installation.preflight.ready':
    'Preflight passed. Installation can continue.',
  'installation.preflight.blockedTitle': 'Installation is unavailable',
  'installation.preflight.blocked':
    'Resolve the blocked checks before trying again.',
  'installation.preflight.check': 'Check',
  'installation.preflight.remediation': 'Remediation',
  'installation.preflight.retry': 'Check again',
  'installation.blocked.migrationPending':
    'Installation state migration is pending. Ask an administrator to check its progress.',
  'installation.blocked.migrationRequired':
    'Legacy installation state was found. Ask an administrator to complete its migration.',
  'installation.blocked.lockInvalid':
    'The installation completion record is invalid. Check it against this application source and preserve existing data.',
  'installation.blocked.lockedDatabaseUnavailable':
    'An installation completion record exists, but the database is unavailable. Check its connection and registered resource.',
  'installation.blocked.databaseUnavailable':
    'The database is unavailable. Check its connection and registered resource, then check again.',
  'installation.blocked.lockedDatabaseMismatch':
    'The installation completion record and database state disagree. Check the record and database source.',
  'installation.blocked.partialState':
    'Partial installation data was found. Ask an administrator to review recovery while preserving existing data.',
  'installation.blocked.lockMissing':
    'The database contains installed data, but its installation completion record is missing. Installation is paused to protect existing data; check the record and database source.',
  'installation.blocked.unknown':
    'The installation state is unavailable. Ask an administrator to investigate.',
  'installation.blocked.unknownWithCode':
    'The installation state is unavailable. Ask an administrator to investigate (diagnostic code: {code}).',
  'installation.token.label': 'One-time setup token',
  'installation.token.placeholder':
    'Enter the one-time token from deployment configuration',
  'installation.token.help':
    'The token is used only for this request and expires after success. It is never saved.',
  'installation.admin.title': 'Administrator identity',
  'installation.admin.email': 'Administrator email',
  'installation.admin.emailPlaceholder': 'Enter the administrator email',
  'installation.admin.password': 'Administrator password',
  'installation.admin.passwordPlaceholder': 'Enter password',
  'installation.platform.title': 'Platform identity',
  'installation.platform.email': 'Platform email',
  'installation.platform.emailPlaceholder': 'Enter the Platform email',
  'installation.platform.password': 'Platform password',
  'installation.platform.passwordPlaceholder': 'Enter password',
  'installation.modules.title': 'Official modules',
  'installation.modules.description':
    'Choose the official modules to enable during the initial installation.',
  'installation.modules.empty':
    'No official modules selected (they can be enabled after installation).',
  'installation.modules.catalogMissing':
    'The installation service did not return a module catalog. Installation cannot continue with a local fallback list.',
  'installation.submit': 'Start installation',
  'installation.submitting': 'Installing…',
  'installation.validation.required': 'This field is required',
  'installation.validation.email': 'Enter a valid email address',
  'installation.validation.password':
    'Password must be {min}–{max} UTF-8 bytes',
  'installation.validation.policyLoading': 'Loading password requirements',
  'installation.validation.policyUnavailable':
    'Password requirements unavailable; please retry',
  'installation.error.title': 'Installation failed',
  'installation.error.retry': 'Check status and retry',
  'installation.success': 'Installation complete. Redirecting to login.',
  'installation.automatic.title': 'Installation is managed by deployment',
  'installation.automatic.description':
    'This deployment uses the automatic installation entry point. Return to login.',
  'installation.login': 'Go to login',
};
