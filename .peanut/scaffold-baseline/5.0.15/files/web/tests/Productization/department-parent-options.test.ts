import {
  buildParentDepartmentOptions,
  initialParentDepartmentId,
  ROOT_DEPARTMENT_ID,
} from '../../src/views/system/dept/parent-options';

function expect(condition: boolean, message: string): void {
  if (!condition) throw new Error(message);
}

expect(
  initialParentDepartmentId() === ROOT_DEPARTMENT_ID,
  'top-level creation must default to the backend root id'
);
expect(
  initialParentDepartmentId(11) === 11,
  'child creation must keep the selected parent department id'
);

const emptyOptions = buildParentDepartmentOptions([], 'Top Level');
expect(
  emptyOptions.length === 1,
  'empty department table must expose one option'
);
expect(
  emptyOptions[0].id === ROOT_DEPARTMENT_ID,
  'the empty-table option must submit the backend root id'
);
expect(
  emptyOptions[0].name === 'Top Level',
  'the root option must use the translated label'
);

const nestedOptions = buildParentDepartmentOptions(
  [
    {
      id: 11,
      name: 'Engineering',
      children: [{ id: 12, name: 'Platform' }],
    },
  ],
  'Top Level'
);
expect(
  nestedOptions[1].id === 11,
  'existing root departments must remain selectable'
);
expect(
  nestedOptions[1].children[0].id === 12,
  'child departments must preserve their hierarchy'
);

// eslint-disable-next-line no-console
console.log('DEPT-PARENT-OPTIONS Web passed');
