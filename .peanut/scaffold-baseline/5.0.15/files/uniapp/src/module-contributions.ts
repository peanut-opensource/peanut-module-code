import {
  moduleKeys,
  moduleSlots,
  modulePages,
} from 'virtual:peanut-module-contributions';

export const moduleAvailable = (key: string) => moduleKeys.includes(key);
export const hasModuleSlot = (slot: string) => moduleSlots.includes(slot);
export const pageAvailable = (url: string) =>
  modulePages.includes(url.replace(/^\//, '').split('?')[0]);
