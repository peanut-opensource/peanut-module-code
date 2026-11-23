export default {
  'menu.appSetting.readiness': 'Production Readiness',
  'readiness.title': 'First-run configuration checklist',
  'readiness.error.load':
    'Unable to load the readiness checklist. Check access and try again.',
  'readiness.description':
    'Review production readiness, impact, ownership, and the next action after installation.',
  'readiness.boundary.title': 'Evidence boundary',
  'readiness.boundary.description':
    'Configured means that the local configuration is structurally complete. External channels, cloud storage, backups, workers, and every domain still need their own production qualification or runtime evidence. This page never probes, returns, or submits secrets.',
  'readiness.summary.ready': 'No production blocker in this checklist',
  'readiness.summary.blocked': '{count} production blocker(s) remain',
  'readiness.columns.item': 'Capability',
  'readiness.columns.status': 'Status',
  'readiness.columns.impact': 'Purpose and impact',
  'readiness.columns.productionBlocking': 'Blocks production',
  'readiness.columns.action': 'Next action / entry',
  'readiness.common.yes': 'Yes',
  'readiness.common.no': 'No',
  'readiness.ownerPrefix': 'Owner',
  'readiness.scope.tenant': 'Tenant scope',
  'readiness.scope.instance': 'Instance scope',
  'readiness.status.configured': 'Configured',
  'readiness.status.observed': 'Observed on this entry',
  'readiness.status.action_required': 'Action required',
  'readiness.status.unverified': 'Unverified',
  'readiness.status.not_implemented': 'Not implemented',
  'readiness.audience.tenant_admin': 'Open Tenant settings',
  'readiness.audience.platform_operator': 'Platform Operator',
  'readiness.audience.deployment_owner': 'Deployment owner',
  'readiness.items.brand.title': 'Brand and site identity',
  'readiness.items.brand.impact':
    'Controls the product name, icons, copyright, and links shown by Admin, PC, and mobile clients.',
  'readiness.items.brand.action':
    'Review whether the default brand is appropriate. Defaults do not prevent runtime use, but a derived product should replace them.',
  'readiness.items.notification.title': 'Notification channels',
  'readiness.items.notification.impact':
    'SMS verification and notifications depend on a Tenant Provider. No email or SMTP Provider exists yet.',
  'readiness.items.notification.action':
    'Configure the required SMS Provider and qualify real delivery separately. Configured does not mean production-ready.',
  'readiness.items.storage.title': 'File storage',
  'readiness.items.storage.impact':
    'Materials, exports, and private files depend on instance-wide public and private default routes.',
  'readiness.items.storage.action':
    'The instance owner must verify routes, directories, or cloud connectivity. Instance credentials are never shown to Tenant admins.',
  'readiness.items.backup.title': 'Database and file backup',
  'readiness.items.backup.impact':
    'Without paired backups and restore evidence, recovery after upgrades, mistakes, or failures is not proven.',
  'readiness.items.backup.action':
    'The in-app backup ledger and center are not implemented. The deployment owner must use the registered paired-backup gate for now.',
  'readiness.items.worker.title': 'Task worker',
  'readiness.items.worker.impact':
    'Scheduled jobs, imports, and exports need a continuously running and observable consumer.',
  'readiness.items.worker.action':
    'No authoritative worker heartbeat exists yet. The instance owner must verify processes, recent consumption, and failures; static Compose configuration is not health evidence.',
  'readiness.items.domain_tls.title': 'Current Admin domain and TLS',
  'readiness.items.domain_tls.impact':
    'A public Admin entry needs a trusted domain and HTTPS. The current request cannot prove other Tenant, PC, H5, or callback domains.',
  'readiness.items.domain_tls.action':
    'Verify DNS, certificate coverage and expiry, and Host allowlists for every external entry. This item observes only the current Admin request.',
  'readiness.items.account_security.title': 'Admin account security',
  'readiness.items.account_security.impact':
    'Strong passwords, login failure lockout, and least-privilege Admin roles protect the control plane. MFA is not available yet.',
  'readiness.items.account_security.action':
    'Review Admin accounts and roles, use a unique password that meets the current policy, and remove unused accounts.',
};
