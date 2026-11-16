// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FileAssetSelector from '../src/FileAssetSelector.vue';
import type { AssetCandidate } from '../src/contracts';

const asset: AssetCandidate = {
  materialId: 7,
  fileKey: 'file_0123456789abcdef0123456789abcdef',
  originalName: 'profile.png',
  mediaType: 'image/png',
  width: 640,
  height: 480,
  previewUri: '/api/v1/files/token/content',
  variants: [],
};

describe('file asset selector', () => {
  it('renders bounded metadata and emits the selected opaque asset', async () => {
    const wrapper = mount(FileAssetSelector, {
      props: { items: [asset], selectedFileKey: asset.fileKey },
      global: {
        stubs: {
          EmptyState: true,
          ElButton: {
            template: '<button @click="$emit(\'click\')"><slot /></button>',
          },
        },
      },
    });
    expect(wrapper.text()).toContain('profile.png');
    expect(wrapper.text()).toContain('640 x 480');
    expect(
      wrapper.find('button.asset-selector__choice').attributes('aria-pressed')
    ).toBe('true');
    await wrapper.find('button.asset-selector__choice').trigger('click');
    expect(wrapper.emitted('select')?.[0]?.[0]).toEqual(asset);
  });

  it('does not expose selectable controls while disabled', () => {
    const wrapper = mount(FileAssetSelector, {
      props: { items: [asset], disabled: true },
      global: { stubs: { EmptyState: true, ElButton: true } },
    });
    expect(
      wrapper.find('button.asset-selector__choice').attributes('disabled')
    ).toBeDefined();
  });
});
