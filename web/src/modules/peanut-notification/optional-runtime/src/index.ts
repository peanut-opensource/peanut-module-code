export const NOTIFICATION_SMS_PACKAGE =
  '@peanut-admin/peanut-notification/optional-runtime' as const;
export const NOTIFICATION_SMS_VERSION = '0.1.0' as const;
export * from './contracts';
export * from './runtime';
export { default as createNotificationModuleContribution } from './contribution';
