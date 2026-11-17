import { appRoutes, appExternalRoutes } from '../routes';

const mixinRoutes = [...appRoutes, ...appExternalRoutes];

// The menu tree and server-menu registry also consume these as route records.
// Preserve the trusted component/redirect instead of projecting an incomplete route.
const appClientMenus = mixinRoutes.map((route) => ({ ...route }));

export default appClientMenus;
