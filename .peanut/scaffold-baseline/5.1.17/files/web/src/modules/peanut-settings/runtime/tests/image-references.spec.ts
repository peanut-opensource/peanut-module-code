import { describe, expect, it, vi } from 'vitest';
import usePublicImageReferences from '@/hooks/public-image-references';
import { saveDecorationTabbar } from '@/api/decoration';
import readDecorationPage from '@/api/decoration-model';
import { saveUserSetting } from '@/api/system-settings';
import type { FileRecord } from '@/modules/peanut-file/api';
import axios from 'axios';

vi.mock('element-plus', () => ({ ElMessage: { error: vi.fn() } }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));

describe('current settings image editors', () => {
  it('stores the selected public file key and keeps its preview separately', () => {
    const images = usePublicImageReferences();
    const file: FileRecord = {
      id: 1,
      cid: 0,
      type: 10,
      name: 'Image',
      uri: 'file_' + 'a'.repeat(32),
      file_key: 'file_' + 'a'.repeat(32),
      url: '/preview',
      access_type: 'public',
    };
    expect(images.select(['/preview'], [file])).toBe(file.file_key);
    expect(images.preview(file.file_key)).toBe('/preview');
    expect(
      images.select(
        ['/private'],
        [{ ...file, access_type: 'private' }],
        file.file_key
      )
    ).toBe(file.file_key);
    expect(images.preview(file.file_key)).toBe('/preview');
    images.replace({ [file.uri]: '/new-preview' });
    expect(images.preview(file.uri)).toBe('/new-preview');
    expect(images.preview('/untrusted-url')).toBe('');
  });

  it('parses stable editor values without replacing them by delivery URLs', () => {
    const key = 'file_' + 'a'.repeat(32);
    const raw = {
      id: 1,
      type: 5,
      name: 'Theme',
      data: { themeColorId: 3 },
      meta: [],
      resource_urls: { [key]: '/preview' },
    };
    expect(readDecorationPage(raw).resource_urls).toEqual({
      [key]: '/preview',
    });
    expect(() =>
      readDecorationPage({ ...raw, resource_urls: { [key]: 123 } })
    ).toThrow();
  });

  it('projects admin-only preview and record metadata out of save requests', () => {
    const key = 'file_' + 'a'.repeat(32);
    saveUserSetting({ default_avatar: key, default_avatar_url: '/preview' });
    expect(axios.post).toHaveBeenLastCalledWith('/adminapi/config/user/save', {
      default_avatar: key,
    });
    const item = {
      id: 7,
      position: 4,
      name: 'Home',
      selected: key,
      unselected: key,
      link: { target_type: 'shop' as const, target: 'home' },
      is_show: 1 as const,
    };
    saveDecorationTabbar({
      style: { default_color: '#666666', selected_color: '#2F80ED' },
      list: [item],
      resource_urls: { [key]: '/preview' },
    });
    expect(axios.post).toHaveBeenLastCalledWith(
      '/adminapi/decoration/tabbar/save',
      {
        style: { default_color: '#666666', selected_color: '#2F80ED' },
        list: [
          {
            name: 'Home',
            selected: key,
            unselected: key,
            link: item.link,
            is_show: 1,
          },
        ],
      }
    );
  });
});
