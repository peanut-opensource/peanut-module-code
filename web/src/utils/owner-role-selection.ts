/** Editing may remove an existing owner but must never grant that role through the ordinary form. */
export function reconcileRoleSelection(
  current: number[],
  proposed: number[],
  editingOwner: boolean,
  ownerRoleId: number | null
): number[] {
  if (editingOwner && ownerRoleId === null) return current;
  const admissible =
    editingOwner || ownerRoleId === null
      ? proposed
      : proposed.filter((roleId) => roleId !== ownerRoleId);
  return [...new Set(admissible)];
}

export default reconcileRoleSelection;
