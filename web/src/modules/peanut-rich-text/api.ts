import axios from 'axios';
import type { PageData } from '@/types/global';
import type { RichTextDocumentValue } from './src/document';
import type { RichTextCollaborationConfig } from './components/types';

export interface RichTextDocumentRecord {
  id: number;
  title: string;
  revision: number;
  document?: RichTextDocumentValue;
  collaboration_state?: string;
  created_by_member_id: number;
  updated_by_member_id: number;
  create_time: string;
  update_time: string;
}

export type RichTextDocumentList = PageData<RichTextDocumentRecord>;

export const getRichTextDocuments = (
  params: {
    title?: string;
    page_no?: number;
    page_size?: number;
  },
  signal?: AbortSignal
) =>
  axios.get<RichTextDocumentList>(
    '/adminapi/peanut.rich-text.document.list',
    { params, signal }
  );

export const getRichTextDocument = (id: number, signal?: AbortSignal) =>
  axios.get<RichTextDocumentRecord>(
    '/adminapi/peanut.rich-text.document.detail',
    { params: { id }, signal }
  );

export const addRichTextDocument = (
  data: {
    title: string;
    document: RichTextDocumentValue;
    collaboration_state: string;
  },
  signal?: AbortSignal
) => axios.post('/adminapi/peanut.rich-text.document.add', data, { signal });

export const editRichTextDocument = (
  data: {
    id: number;
    title: string;
    document: RichTextDocumentValue;
    collaboration_state: string;
    revision: number;
  },
  signal?: AbortSignal
) => axios.post('/adminapi/peanut.rich-text.document.edit', data, { signal });

export const deleteRichTextDocument = (id: number, signal?: AbortSignal) =>
  axios.post(
    '/adminapi/peanut.rich-text.document.delete',
    { id },
    { signal }
  );

export const getRichTextCollaboration = (id: number, signal?: AbortSignal) =>
  axios.get<RichTextCollaborationConfig>(
    '/adminapi/peanut.rich-text.document.collaboration',
    { params: { id }, signal }
  );
