// Retained governance fixtures run against their actual source and public Core imports.
export default {
  test: {
    environment: 'node',
    include: ['src/modules/governance/optional-runtime/tests/**/*.spec.ts'],
    passWithNoTests: false,
  },
};
