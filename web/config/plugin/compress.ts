/**
 * Used to package and output gzip. Note that this does not work properly in Vite, the specific reason is still being investigated
 * gzip压缩
 * https://github.com/anncwb/vite-plugin-compression
 */
import type { Plugin } from 'vite';
import compressPlugin from 'vite-plugin-compression';

// Generating a compressed sibling could overwrite a file owned by a Module.
const filter = /^(?!.*[/\\]module-assets[/\\]).*\.(js|mjs|json|css|html)$/i;

export default function configCompressPlugin(
  compress: 'gzip' | 'brotli',
  deleteOriginFile = false
): Plugin | Plugin[] {
  const plugins: Plugin[] = [];

  if (compress === 'gzip') {
    plugins.push(
      compressPlugin({
        ext: '.gz',
        filter,
        deleteOriginFile,
      })
    );
  }

  if (compress === 'brotli') {
    plugins.push(
      compressPlugin({
        ext: '.br',
        algorithm: 'brotliCompress',
        filter,
        deleteOriginFile,
      })
    );
  }
  return plugins;
}
