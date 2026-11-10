module.exports = {
  extends: [
    'stylelint-config-standard',
    'stylelint-config-prettier',
    'stylelint-config-recommended-vue',
  ],
  defaultSeverity: 'warning',
  overrides: [
    {
      files: ['**/*.less'],
      customSyntax: 'postcss-less',
    },
  ],
  plugins: ['stylelint-order'],
  rules: {
    'order/properties-order': require('./config/stylelint-property-order'),
    'at-rule-no-unknown': [
      true,
      {
        ignoreAtRules: ['plugin'],
      },
    ],
    'rule-empty-line-before': [
      'always',
      {
        except: ['after-single-line-comment', 'first-nested'],
      },
    ],
    'selector-pseudo-class-no-unknown': [
      true,
      {
        ignorePseudoClasses: ['deep'],
      },
    ],
    'property-no-unknown': [
      true,
      {
        ignoreProperties: [
          'composes',
          '@import',
          '@extend',
          '@mixin',
          '@at-root',
        ],
      },
    ],
  },
};
