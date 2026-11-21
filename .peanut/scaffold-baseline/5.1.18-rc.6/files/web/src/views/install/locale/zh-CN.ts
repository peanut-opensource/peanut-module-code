export default {
  'installation.brand.alt': 'Peanut Module Code 标志',
  'installation.title': '安装 Peanut Module Code',
  'installation.subtitle': '完成一次性安装，开始使用管理端。',
  'installation.step.preflight': '环境预检',
  'installation.step.identity': '初始身份',
  'installation.step.install': '安装并检查健康状态',
  'installation.mode': '部署模式',
  'installation.mode.standalone': '单实例',
  'installation.mode.multiTenant': '多租户',
  'installation.preflight.ready': '环境预检已通过，可以继续安装。',
  'installation.preflight.blockedTitle': '安装暂不可用',
  'installation.preflight.blocked': '环境预检未通过，请处理阻断项后重试。',
  'installation.preflight.check': '预检项',
  'installation.preflight.remediation': '建议',
  'installation.preflight.retry': '重新检查',
  'installation.blocked.migrationPending':
    '安装状态迁移尚未完成，请由维护人员核对迁移进度。',
  'installation.blocked.migrationRequired':
    '检测到旧版安装状态，请由维护人员先完成状态迁移。',
  'installation.blocked.lockInvalid':
    '安装完成记录无效，请核对记录与当前应用来源，保留现有数据。',
  'installation.blocked.lockedDatabaseUnavailable':
    '安装完成记录已存在，但数据库暂不可用，请核对连接和登记资源。',
  'installation.blocked.databaseUnavailable':
    '数据库暂不可用，请核对连接和登记资源后重新检查。',
  'installation.blocked.lockedDatabaseMismatch':
    '安装完成记录与数据库状态不一致，请核对安装记录及数据库来源。',
  'installation.blocked.partialState':
    '检测到部分安装数据，请由维护人员核对恢复方案，保留现有数据。',
  'installation.blocked.lockMissing':
    '数据库已有安装数据，但安装完成记录缺失。为保护现有数据，安装已暂停；请核对安装记录与数据库来源。',
  'installation.blocked.unknown': '安装状态异常，请联系维护人员核对。',
  'installation.blocked.unknownWithCode':
    '安装状态异常，请联系维护人员核对（诊断码：{code}）。',
  'installation.token.label': '一次性安装令牌',
  'installation.token.placeholder': '输入部署配置提供的一次性令牌',
  'installation.token.help': '令牌仅用于本次请求，成功后立即失效，不会保存。',
  'installation.admin.title': '管理员身份',
  'installation.admin.email': '管理员邮箱',
  'installation.admin.emailPlaceholder': '输入管理员邮箱',
  'installation.admin.password': '管理员密码',
  'installation.admin.passwordPlaceholder': '请输入密码',
  'installation.platform.title': 'Platform 身份',
  'installation.platform.email': 'Platform 邮箱',
  'installation.platform.emailPlaceholder': '输入 Platform 邮箱',
  'installation.platform.password': 'Platform 密码',
  'installation.platform.passwordPlaceholder': '请输入密码',
  'installation.modules.title': '官方模块',
  'installation.modules.description': '选择首次安装时启用的官方模块。',
  'installation.modules.empty': '未选择官方模块（可在安装后按需启用）。',
  'installation.modules.catalogMissing':
    '安装服务未返回模块能力目录，已阻止使用本地硬编码清单继续安装。',
  'installation.submit': '开始安装',
  'installation.submitting': '安装中…',
  'installation.validation.required': '此项不能为空',
  'installation.validation.email': '请输入有效邮箱',
  'installation.validation.password': '密码长度须为 {min}～{max} 个 UTF-8 字节',
  'installation.validation.policyLoading': '正在读取密码要求',
  'installation.validation.policyUnavailable': '无法读取密码要求，请重试',
  'installation.error.title': '安装失败',
  'installation.error.retry': '检查状态后重试',
  'installation.success': '安装完成，正在跳转到登录页。',
  'installation.automatic.title': '安装由部署流程管理',
  'installation.automatic.description':
    '当前部署使用自动安装入口，请返回登录页。',
  'installation.login': '前往登录',
};
