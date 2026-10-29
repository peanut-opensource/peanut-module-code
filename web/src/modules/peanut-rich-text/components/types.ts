export interface RichTextCollaborationConfig {
  enabled: boolean;
  url: string | null;
  document_name: string;
  token: string | null;
  expires_at: number | null;
}
