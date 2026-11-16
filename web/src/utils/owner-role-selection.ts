/** Preserve the system-owned tenant owner binding without granting any business role implicitly. */
export function reconcileRoleSelection(
  current: number[],
  proposed: number[],
  editingOwner: boolean,
  ownerRoleId: number | null,
): number[] {
  if (!editingOwner) return proposed;
  // A missing authoritative owner key is fail-closed, not an empty role list.
  if (ownerRoleId === null) return current;
  return [...new Set([...proposed, ownerRoleId])];
}
