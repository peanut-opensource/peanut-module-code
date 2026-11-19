import type { ContentToolsContribution } from './src/contracts';
import builtinContentAdapters from './src/builtins';

const contribution: ContentToolsContribution = {
  moduleKey: 'peanut.content-tools',
  routes: [],
  contentAdapters: builtinContentAdapters,
};

export default contribution;
