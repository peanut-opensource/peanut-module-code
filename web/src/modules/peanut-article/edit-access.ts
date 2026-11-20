import { hasPermission } from '@/hooks/permission';

const editPermissions = [
  'peanut.article.list',
  'peanut.article.detail',
  'peanut.article.edit',
];

export default function canEditArticle(permissions: string[]): boolean {
  return editPermissions.every((permission) =>
    hasPermission(permission, undefined, permissions)
  );
}
