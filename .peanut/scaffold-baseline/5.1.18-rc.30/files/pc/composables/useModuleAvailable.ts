export const useModuleAvailable = (key: string) => {
  const keys: readonly string[] = useAppConfig().moduleKeys;
  return keys.includes(key);
};
