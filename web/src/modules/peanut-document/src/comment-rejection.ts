export class DocumentCommentRejection extends Error {
  constructor(readonly status: number, readonly errorCode: string) {
    super(errorCode);
  }
}

export default DocumentCommentRejection;
