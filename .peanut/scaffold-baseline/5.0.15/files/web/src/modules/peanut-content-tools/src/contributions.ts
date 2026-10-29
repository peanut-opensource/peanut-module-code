import lockedContributions from 'virtual:peanut-plugin-contributions';
import ContentToolRegistry from './registry';

/** Same reviewed/locked frontend entries as the application's route composition. */
const createLockedContentToolRegistry = (): ContentToolRegistry =>
  new ContentToolRegistry(lockedContributions);

export default createLockedContentToolRegistry;
