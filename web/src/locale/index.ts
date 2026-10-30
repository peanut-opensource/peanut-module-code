import { createI18n } from 'vue-i18n';
import contributions from 'virtual:peanut-plugin-contributions';
import en from './en-US';
import cn from './zh-CN';

function moduleMessages(locale: 'en-US' | 'zh-CN'): Record<string, string> {
  const messages: Record<string, string> = {};
  contributions.forEach((contribution) => {
    Object.assign(messages, contribution.locales?.[locale]);
  });
  return messages;
}

export const LOCALE_OPTIONS = [
  { label: '中文', value: 'zh-CN' },
  { label: 'English', value: 'en-US' },
];
const defaultLocale = localStorage.getItem('peanut-locale') || 'zh-CN';

const i18n = createI18n({
  locale: defaultLocale,
  fallbackLocale: 'en-US',
  legacy: false,
  allowComposition: true,
  messages: {
    'en-US': { ...en, ...moduleMessages('en-US') },
    'zh-CN': { ...cn, ...moduleMessages('zh-CN') },
  },
});

export default i18n;
