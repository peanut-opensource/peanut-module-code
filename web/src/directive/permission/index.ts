import { DirectiveBinding } from 'vue';
import { hasPermission } from '@/hooks/permission';

function checkPermission(el: HTMLElement, binding: DirectiveBinding) {
  const { value } = binding;

  if (Array.isArray(value)) {
    if (value.length > 0) {
      if (!hasPermission(value) && el.parentNode) {
        el.parentNode.removeChild(el);
      }
    }
  } else {
    throw new Error(`need permissions! Like v-permission="['perm/a']"`);
  }
}

export default {
  mounted(el: HTMLElement, binding: DirectiveBinding) {
    checkPermission(el, binding);
  },
  updated(el: HTMLElement, binding: DirectiveBinding) {
    checkPermission(el, binding);
  },
};
