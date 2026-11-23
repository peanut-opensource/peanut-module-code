export default {
  'menu.appSetting.readiness': '生产准备清单',
  'readiness.title': '首次运行配置清单',
  'readiness.error.load': '无法加载生产准备清单，请检查访问权限后重试。',
  'readiness.description':
    '集中查看开箱后的生产准备度、影响、责任方和下一动作。',
  'readiness.boundary.title': '状态证据边界',
  'readiness.boundary.description':
    '“已配置”只代表本地配置结构完整；外部渠道、云存储、备份、Worker 与全部域名仍需各自的生产资格或运行证据。页面不会探测、回显或提交任何密钥。',
  'readiness.summary.ready': '当前清单无生产阻塞项',
  'readiness.summary.blocked': '仍有 {count} 项生产阻塞',
  'readiness.columns.item': '能力',
  'readiness.columns.status': '当前状态',
  'readiness.columns.impact': '作用与影响',
  'readiness.columns.productionBlocking': '阻塞生产',
  'readiness.columns.action': '下一动作 / 配置入口',
  'readiness.common.yes': '是',
  'readiness.common.no': '否',
  'readiness.ownerPrefix': '责任方',
  'readiness.scope.tenant': '租户级',
  'readiness.scope.instance': '实例级',
  'readiness.status.configured': '已配置',
  'readiness.status.observed': '当前入口已观察',
  'readiness.status.action_required': '需要处理',
  'readiness.status.unverified': '尚未验证',
  'readiness.status.not_implemented': '尚未实施',
  'readiness.audience.tenant_admin': '进入租户管理配置',
  'readiness.audience.platform_operator': 'Platform Operator',
  'readiness.audience.deployment_owner': '部署运维负责人',
  'readiness.items.brand.title': '品牌与站点身份',
  'readiness.items.brand.impact':
    '决定管理端、PC 和移动端对用户展示的产品名称、图标、版权与入口信息。',
  'readiness.items.brand.action':
    '检查默认品牌是否符合当前产品；保留默认值不会阻止运行，但派生产品应完成品牌替换。',
  'readiness.items.notification.title': '通知渠道',
  'readiness.items.notification.impact':
    '短信验证码和通知依赖租户级 Provider；当前没有邮件/SMTP Provider。',
  'readiness.items.notification.action':
    '按实际业务启用并填写短信 Provider，再独立验证真实送达；“已配置”不等于生产可用。',
  'readiness.items.storage.title': '文件存储',
  'readiness.items.storage.impact':
    '素材、导出和私有文件依赖实例级公开/私有默认路由。',
  'readiness.items.storage.action':
    '由实例责任方检查存储路由、目录或云 Provider 连通性；租户管理员不会看到实例凭据。',
  'readiness.items.backup.title': '数据库与文件备份',
  'readiness.items.backup.impact':
    '没有配对备份和恢复验证时，升级、误操作或故障后无法证明可恢复。',
  'readiness.items.backup.action':
    '应用内备份账本和备份中心尚未实施；当前只能由部署侧执行已登记的配对备份门禁。',
  'readiness.items.worker.title': '任务 Worker',
  'readiness.items.worker.impact':
    '定时任务、导入导出等后台工作需要持续运行且可观测的消费者。',
  'readiness.items.worker.action':
    '当前没有权威 Worker 心跳；由实例责任方核对进程、最近消费与失败记录，不能用静态 Compose 配置代替健康证据。',
  'readiness.items.domain_tls.title': '当前管理入口域名与 TLS',
  'readiness.items.domain_tls.impact':
    '公开管理入口需要可信域名和 HTTPS；当前请求不能证明其他 Tenant、PC、H5 或回调域名。',
  'readiness.items.domain_tls.action':
    '为全部外部入口分别核对 DNS、证书覆盖、到期时间和 Host 白名单；本项只观察当前管理请求。',
  'readiness.items.account_security.title': '管理员账户安全',
  'readiness.items.account_security.impact':
    '强密码、登录失败锁定和最小管理员授权决定后台身份安全。当前产品尚未提供 MFA。',
  'readiness.items.account_security.action':
    '复核管理员列表与角色，使用符合当前要求的独立密码并清理不再使用的账号。',
};
