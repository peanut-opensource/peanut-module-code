export class DocumentCommentRejection extends Error {
  constructor(readonly status: number, readonly errorCode: string) {
    super(errorCode);
  }

  get isDefinitive(): boolean {
    // These responses retain an original durable operation or an unconfirmed result.
    // An HTTP conflict is not evidence that this request made no changes.
    return (
      this.status >= 400 &&
      this.status < 500 &&
      ![
        'COLLABORATION_CONTROL_UNKNOWN',
        'CONTROL_HOLDER_CHANGED',
        'DOCUMENT_SUGGESTION_APPLICATION_UNCONFIRMED',
        'DOCUMENT_BUSINESS_PENDING',
        'DOCUMENT_SUGGESTION_RECEIPT_MISSING',
      ].includes(this.errorCode)
    );
  }

  get confirmsAcceptanceCancellation(): boolean {
    // The accept endpoint exposes these only before preparation, or after the
    // exact original operation is cancelled and its room release is confirmed.
    return this.status === 409 && [
      'SUGGESTION_CONTENT_INVALID',
      'SUGGESTION_BASELINE_INVALID',
      'SUGGESTION_BASELINE_CONFLICT',
      'DOCUMENT_SUGGESTION_BASE_CHANGED',
      'DOCUMENT_SUGGESTION_NOT_APPLIED_PROGRESS_MISMATCH',
    ].includes(this.errorCode);
  }
}

export default DocumentCommentRejection;
