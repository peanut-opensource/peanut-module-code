import { createPinia } from 'pinia';
import useAppStore from './modules/app';
import useUserStore from './modules/user';
import useTabBarStore from './modules/tab-bar';
import useBrandStore from './modules/brand';

const pinia = createPinia();

export { useAppStore, useUserStore, useTabBarStore, useBrandStore };
export default pinia;
