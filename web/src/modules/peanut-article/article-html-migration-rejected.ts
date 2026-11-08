export default class ArticleHtmlMigrationRejected extends Error {
  constructor() {
    super('原文、资讯状态或正文绑定已改变，请重新预览当前来源。');
  }
}
