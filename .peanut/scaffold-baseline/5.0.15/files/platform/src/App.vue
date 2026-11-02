<template>
  <section v-if="invitationToken" class="login-shell">
    <el-card class="login-card" v-loading="loading">
      <p class="brand">PEANUT ADMIN</p>
      <template v-if="invitation">
        <h1>接受租户所有者邀请</h1>
        <p class="muted"
          >{{ invitation.tenant_name }} · {{ invitation.display_name }}（{{
            invitation.email_hint
          }}）</p
        >
        <el-alert
          :title="`邀请有效期至 ${invitation.expires_at}`"
          type="info"
          :closable="false"
        />
        <el-alert
          v-if="error"
          :title="error"
          type="error"
          :closable="false"
          show-icon
        />
        <el-form
          v-if="invitation.requires_password"
          label-position="top"
          @submit.prevent="acceptInvitation"
        >
          <el-form-item label="设置账户密码"
            ><el-input
              v-model="acceptPassword"
              type="password"
              show-password
              autocomplete="new-password"
          /></el-form-item>
          <el-button
            native-type="submit"
            type="primary"
            :loading="loading"
            class="wide"
            >接受邀请</el-button
          >
        </el-form>
        <el-button
          v-else
          type="primary"
          :loading="loading"
          class="wide"
          @click="acceptInvitation"
          >接受邀请</el-button
        >
        <el-button class="wide" :disabled="loading" @click="goToTenantLogin"
          >取消并前往租户管理端登录</el-button
        >
      </template>
      <el-result
        v-else-if="error"
        icon="error"
        title="邀请不可用"
        :sub-title="error"
        ><template #extra
          ><el-button @click="goToTenantLogin"
            >前往租户管理端登录</el-button
          ></template
        ></el-result
      >
    </el-card>
  </section>
  <section v-else-if="!authenticated" class="login-shell"
    ><el-card class="login-card"
      ><p class="brand">PEANUT ADMIN</p><h1>实例平台管理</h1
      ><p class="muted"
        >实例平台操作员使用独立平台会话；平台身份不授予租户业务权限。</p
      ><el-form @submit.prevent="login"
        ><el-form-item
          ><el-input
            v-model="credentials.email"
            placeholder="实例平台操作员邮箱"
            autocomplete="username" /></el-form-item
        ><el-form-item
          ><el-input
            v-model="credentials.password"
            type="password"
            show-password
            placeholder="密码"
            autocomplete="current-password" /></el-form-item
        ><el-alert
          v-if="error"
          :title="error"
          type="error"
          :closable="false"
        /><el-button
          native-type="submit"
          type="primary"
          :loading="loading"
          class="wide"
          >登录实例平台</el-button
        ></el-form
      ></el-card
    ></section
  >
  <component
    v-else
    :is="platformShell"
    class="app-shell platform-preset"
    :theme="platformPresentation.theme"
    :layout="platformPresentation.layout"
    :navigation-mode="platformPresentation.navigationMode"
  >
    <el-aside
      width="224px"
      class="platform-navigation"
      :class="{ 'navigation-open': navigationOpen }"
      @keydown.esc.capture="closeNavigation"
      ><div class="logo">{{ platformPresentation.brand }}</div
      ><el-menu
        id="platform-navigation-menu"
        :mode="
          platformPresentation.navigationMode === 'top'
            ? 'horizontal'
            : 'vertical'
        "
        :default-active="view"
        @select="selectView"
        ><el-menu-item index="overview">概览</el-menu-item
        ><component :is="navigationGroup" index="tenant-operations"
          ><template #title>租户运营</template
          ><el-menu-item index="tenants">租户与生命周期</el-menu-item
          ><el-menu-item index="owners">租户所有者邀请</el-menu-item
          ><el-menu-item index="endpoints">入口域名与客户端</el-menu-item
          ><el-menu-item v-if="can('platform.tenant.read')" index="modules"
            >租户许可证</el-menu-item
          ><el-menu-item v-if="can('platform.plan.read')" index="plans"
            >套餐管理</el-menu-item
          ></component
        ><component :is="navigationGroup" index="organization-access"
          ><template #title>组织与权限</template
          ><el-menu-item index="operators">实例平台操作员</el-menu-item
          ><el-menu-item index="roles">平台角色与权限</el-menu-item></component
        ><component :is="navigationGroup" index="operations-audit"
          ><template #title>运维与审计</template
          ><el-menu-item v-if="can('platform.ops.read')" index="ops"
            >运行与维护</el-menu-item
          ><el-menu-item v-if="can('platform.module.read')" index="developer"
            >开发者中心</el-menu-item
          ><el-menu-item index="storage">存储基础设施</el-menu-item
          ><el-menu-item index="audit">平台审计</el-menu-item></component
        ></el-menu
      ></el-aside
    ><el-container
      ><el-header
        ><el-button
          class="navigation-toggle"
          text
          :aria-label="navigationOpen ? '关闭导航菜单' : '打开导航菜单'"
          :aria-expanded="navigationOpen"
          aria-controls="platform-navigation-menu"
          @click="toggleNavigation"
          >菜单</el-button
        ><div class="platform-header-title"
          ><b>{{ title }}</b
          ><span class="muted"
            >实例平台控制面 · 账户 {{ platformAccount }}</span
          ></div
        ><div class="platform-header-actions"
          ><el-popover
            v-if="hasPresentationChoices"
            trigger="click"
            width="260"
            :teleported="false"
          >
            <template #reference><el-button text>展示设置</el-button></template>
            <el-form label-position="top">
              <el-form-item
                v-if="platformUserOptions.presets.length > 1"
                label="展示预设"
              >
                <el-select
                  :model-value="platformPresentation.preset"
                  @change="selectPlatformPreset"
                >
                  <el-option
                    v-for="value in platformUserOptions.presets"
                    :key="value"
                    :value="value"
                    :label="value === 'admin' ? '默认管理台' : '工作台'"
                  />
                </el-select>
              </el-form-item>
              <el-form-item
                v-if="platformUserOptions.layouts.length > 1"
                label="布局"
              >
                <el-select v-model="platformPresentation.layout"
                  ><el-option
                    v-for="value in platformUserOptions.layouts"
                    :key="value"
                    :value="value"
                    :label="value === 'admin' ? '管理台' : '工作台'"
                /></el-select>
              </el-form-item>
              <el-form-item
                v-if="platformUserOptions.navigationModes.length > 1"
                label="导航方式"
              >
                <el-select v-model="platformPresentation.navigationMode"
                  ><el-option
                    v-for="value in platformUserOptions.navigationModes"
                    :key="value"
                    :value="value"
                    :label="
                      { tree: '树形', grouped: '分组平铺', top: '顶部' }[value]
                    "
                /></el-select>
              </el-form-item>
              <el-form-item
                v-if="platformUserOptions.densities.length > 1"
                label="界面密度"
              >
                <el-select v-model="platformPresentation.theme.density"
                  ><el-option
                    v-for="value in platformUserOptions.densities"
                    :key="value"
                    :value="value"
                    :label="value === 'compact' ? '紧凑' : '舒适'"
                /></el-select>
              </el-form-item>
              <el-form-item
                v-if="platformUserOptions.modes.length > 1"
                label="主题模式"
              >
                <el-select v-model="platformPresentation.theme.mode"
                  ><el-option
                    v-for="value in platformUserOptions.modes"
                    :key="value"
                    :value="value"
                    :label="value === 'dark' ? '暗色' : '亮色'"
                /></el-select>
              </el-form-item>
            </el-form> </el-popover
          ><el-button text :loading="loading" @click="refreshView"
            >刷新</el-button
          ><el-button text @click="logout">退出登录</el-button></div
        ></el-header
      ><el-main
        ><el-breadcrumb class="platform-breadcrumb"
          ><el-breadcrumb-item>{{
            platformPresentation.brand
          }}</el-breadcrumb-item
          ><el-breadcrumb-item>{{ title }}</el-breadcrumb-item></el-breadcrumb
        >
        <el-alert
          v-if="error && view !== 'audit'"
          :title="error"
          type="error"
          show-icon
          @close="error = ''"
        />
        <template v-if="view === 'overview'"
          ><div class="metric-grid"
            ><el-card
              ><span>租户总数</span
              ><strong>{{ tenants.length }}</strong></el-card
            ><el-card
              ><span>运行中租户</span
              ><strong>{{ activeCount }}</strong></el-card
            ><el-card
              ><span>实例平台操作员</span
              ><strong>{{ operators.length }}</strong></el-card
            ></div
          ><el-card
            ><template #header>实例状态</template
            ><el-descriptions :column="2"
              ><el-descriptions-item label="控制面"
                >实例平台操作员独立会话</el-descriptions-item
              ><el-descriptions-item label="治理范围"
                >仅管理本实例租户，不进入租户业务会话</el-descriptions-item
              ></el-descriptions
            ></el-card
          ></template
        >
        <template v-else-if="view === 'ops'">
          <ops-console-page>
            <template #refresh
              ><el-button :loading="loading" @click="loadView"
                >刷新各区域</el-button
              ></template
            >
            <template #upgrade
              ><el-alert
                v-if="opsErrors.readiness"
                :title="opsErrors.readiness"
                type="warning"
                :closable="false" /><el-alert
                v-if="opsErrors.upgrades"
                :title="opsErrors.upgrades"
                type="warning"
                :closable="false" /><el-card
                v-loading="upgradeReadinessLoading"
                class="backup-center-card"
                ><template #header>升级就绪</template
                ><template v-if="upgradeReadiness"
                  ><el-alert
                    :title="`${readinessLabel(
                      upgradeReadiness.state
                    )} · ${opsReason(upgradeReadiness.code)}`"
                    :type="
                      upgradeReadiness.code === 'SOURCE_IDENTITY_UNKNOWN'
                        ? 'info'
                        : readinessType(upgradeReadiness.state)
                    "
                    :closable="false"
                  /><el-descriptions :column="2" border
                    ><el-descriptions-item label="静态预检"
                      >{{ readinessLabel(upgradeReadiness.preflight.state) }}
                      ·
                      {{
                        opsReason(upgradeReadiness.preflight.code)
                      }}</el-descriptions-item
                    ><el-descriptions-item label="当前 Runtime">{{
                      upgradeReadiness.source.runtime.release_key ||
                      upgradeReadiness.source.runtime.commit?.slice(0, 12) ||
                      '未知（源码运行）'
                    }}</el-descriptions-item
                    ><el-descriptions-item label="目标 Release">{{
                      upgradeReadiness.target?.release_key ||
                      '尚未由 Deployment 固定'
                    }}</el-descriptions-item
                    ><el-descriptions-item label="目标迁移">{{
                      upgradeReadiness.migrations.release
                        ? `${
                            upgradeReadiness.migrations.release.pending_count ??
                            '待分析'
                          } 项待应用`
                        : '待分析'
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Module 兼容"
                      >{{ upgradeReadiness.modules.compatible_count }} /
                      {{
                        upgradeReadiness.modules.installed_count
                      }}</el-descriptions-item
                    >
                    <el-descriptions-item label="Scaffold 来源">{{
                      upgradeReadiness.source.application &&
                      upgradeReadiness.target
                        ? `${upgradeReadiness.source.application.template_version} → ${upgradeReadiness.target.scaffold.to_version} · ${upgradeReadiness.scaffold.code}`
                        : '待核验'
                    }}</el-descriptions-item>
                    <el-descriptions-item label="部署语义"
                      >完整应用发布，业务代码随本应用发布包部署；不在运行环境合并脚手架文件</el-descriptions-item
                    >
                    <el-descriptions-item label="恢复指针">{{
                      upgradeReadiness.recovery_pointer
                        ? `${
                            upgradeReadiness.recovery_pointer
                              .backup_reference_key
                          } / ${upgradeReadiness.recovery_pointer.restore_verification_sha256.slice(
                            0,
                            12
                          )}…`
                        : '尚未形成'
                    }}</el-descriptions-item></el-descriptions
                  ><el-table
                    :data="upgradeReadiness.checks"
                    empty-text="暂无升级检查"
                    ><el-table-column prop="key" label="检查" /><el-table-column
                      label="状态"
                      ><template #default="{ row }"
                        ><el-tag :type="readinessType(row.status)">{{
                          readinessLabel(row.status)
                        }}</el-tag></template
                      ></el-table-column
                    ><el-table-column prop="code" label="原因"
                      ><template #default="{ row }">{{
                        opsReason(row.code)
                      }}</template></el-table-column
                    ></el-table
                  ></template
                ><el-empty v-else description="升级就绪投影尚未加载" /></el-card
              ><el-card
                v-loading="upgradeCenterLoading"
                class="backup-center-card"
                ><template #header
                  ><div class="card-header"
                    ><span>应用升级执行</span
                    ><el-button
                      v-if="can('platform.ops.upgrade.manage')"
                      type="primary"
                      :loading="upgradeSubmitting"
                      :disabled="
                        !upgradeReadiness ||
                        upgradeReadiness.preflight.state !== 'ready' ||
                        !!activeUpgrade
                      "
                      @click="executeUpgrade"
                      >执行固定升级</el-button
                    ></div
                  ></template
                ><el-alert
                  title="执行目标只来自 Deployment 已固定的 descriptor。页面不能提交路径、URL、命令、Release、镜像、凭据或部署目标。"
                  type="info"
                  :closable="false" /><template v-if="latestUpgrade"
                  ><el-descriptions :column="2" border
                    ><el-descriptions-item label="任务">{{
                      latestUpgrade.task_key
                    }}</el-descriptions-item
                    ><el-descriptions-item label="状态">{{
                      statusLabel(latestUpgrade.status)
                    }}</el-descriptions-item
                    ><el-descriptions-item label="当前步骤">{{
                      upgradeStepLabel(latestUpgrade.current_step)
                    }}</el-descriptions-item
                    ><el-descriptions-item label="目标 Release">{{
                      latestUpgrade.target.release_key
                    }}</el-descriptions-item
                    ><el-descriptions-item label="新备份">{{
                      latestUpgrade.backup_reference_key || '待执行'
                    }}</el-descriptions-item
                    ><el-descriptions-item label="恢复指针">{{
                      latestUpgrade.recovery_pointer_sha256
                        ? `${latestUpgrade.recovery_pointer_sha256.slice(
                            0,
                            12
                          )}…`
                        : '待形成'
                    }}</el-descriptions-item></el-descriptions
                  ><el-table
                    :data="latestUpgrade.steps"
                    empty-text="任务尚未被控制 worker 领取"
                    ><el-table-column label="步骤"
                      ><template #default="{ row }">{{
                        upgradeStepLabel(row.step_key)
                      }}</template></el-table-column
                    ><el-table-column label="状态"
                      ><template #default="{ row }">{{
                        statusLabel(row.status)
                      }}</template></el-table-column
                    ><el-table-column prop="last_error_code" label="停止原因"
                      ><template #default="{ row }">{{
                        row.last_error_code || '—'
                      }}</template></el-table-column
                    ><el-table-column prop="completed_at" label="完成时间"
                      ><template #default="{ row }">{{
                        row.completed_at || '—'
                      }}</template></el-table-column
                    ></el-table
                  ></template
                ><el-empty v-else description="暂无应用升级任务" /></el-card
            ></template>
            <template #backup
              ><el-alert
                v-if="opsErrors.backups"
                :title="opsErrors.backups"
                type="warning"
                :closable="false" /><el-card
                v-loading="backupCenterLoading"
                class="backup-center-card"
                ><template #header>备份中心</template
                ><el-alert
                  v-if="backupCenter && !backupCenter.source_identity_known"
                  title="源码运行未提供可验证发行身份。可查看备份历史；创建备份、恢复及升级仍需完整来源资格。"
                  type="info"
                  :closable="false" /><template v-if="backupCenter"
                  ><p class="muted">最新已验证备份</p
                  ><el-alert
                    v-if="backupCenter.latest_verified === null"
                    :title="
                      backupCenter.backup_contract_status ===
                      'unsupported_schema'
                        ? '需要重新备份，旧记录不适用于当前部署'
                        : '尚无已验证备份'
                    "
                    type="info"
                    :closable="false" /><el-descriptions
                    v-else
                    :column="2"
                    border
                    ><el-descriptions-item label="Provider">{{
                      backupCenter.provider.key
                    }}</el-descriptions-item
                    ><el-descriptions-item label="完成时间">{{
                      backupCenter.latest_verified.consistency_completed_at
                    }}</el-descriptions-item
                    ><el-descriptions-item label="年龄">{{
                      formatBackupAge(backupCenter.latest_verified.age_seconds)
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Runtime source">{{
                      backupCenter.latest_verified.source_matches_runtime ===
                      null
                        ? '当前源码身份未知，未判定匹配'
                        : backupCenter.latest_verified.source_matches_runtime
                        ? '匹配当前 Runtime'
                        : '不匹配当前 Runtime'
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Manifest SHA-256"
                      >{{
                        backupCenter.latest_verified.manifest_sha256.slice(
                          0,
                          12
                        )
                      }}…</el-descriptions-item
                    ></el-descriptions
                  ><p class="muted">最近恢复验证</p
                  ><el-alert
                    v-if="backupCenter.latest_restore_verified === null"
                    :title="
                      backupCenter.restore_contract_status ===
                      'unsupported_schema'
                        ? '需要重新备份并验证恢复，旧记录不适用于当前部署'
                        : '尚无已验证恢复'
                    "
                    type="info"
                    :closable="false" /><el-descriptions
                    v-else
                    :column="2"
                    border
                    ><el-descriptions-item label="Backup reference">{{
                      backupCenter.latest_restore_verified.backup_reference_key
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Target">{{
                      backupCenter.latest_restore_verified.target_key
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Verified at">{{
                      backupCenter.latest_restore_verified.verified_at
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Verification SHA-256"
                      >{{
                        backupCenter.latest_restore_verified.verification_sha256.slice(
                          0,
                          12
                        )
                      }}…</el-descriptions-item
                    ><el-descriptions-item label="表数量">{{
                      backupCenter.latest_restore_verified.table_count
                    }}</el-descriptions-item
                    ><el-descriptions-item label="迁移数量">{{
                      backupCenter.latest_restore_verified.migration_count
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Tenant 数量">{{
                      backupCenter.latest_restore_verified.tenant_count
                    }}</el-descriptions-item
                    ><el-descriptions-item label="Account 数量">{{
                      backupCenter.latest_restore_verified.account_count
                    }}</el-descriptions-item
                    ><el-descriptions-item label="TenantMember 数量">{{
                      backupCenter.latest_restore_verified.tenant_member_count
                    }}</el-descriptions-item
                    ><el-descriptions-item label="文件数量">{{
                      backupCenter.latest_restore_verified.file_count
                    }}</el-descriptions-item></el-descriptions
                  ><p class="muted">备份与恢复任务</p
                  ><p class="table-scroll-hint"
                    >表格可左右滚动；键盘可聚焦表格后使用方向键查看全部列。</p
                  ><el-table
                    :data="backupCenter.tasks"
                    empty-text="暂无备份或恢复任务"
                    scrollbar-tabindex="0"
                    aria-label="备份与恢复任务表；可水平滚动查看全部列"
                    ><el-table-column
                      prop="task_key"
                      label="任务标识" /><el-table-column
                      prop="task_type"
                      label="任务类型" /><el-table-column
                      prop="status"
                      label="状态"
                      ><template #default="{ row }">{{
                        statusLabel(row.status)
                      }}</template></el-table-column
                    ><el-table-column label="尝试次数"
                      ><template #default="{ row }"
                        >{{ row.attempt_count }} /
                        {{ row.max_attempts }}</template
                      ></el-table-column
                    ><el-table-column prop="last_error_code" label="失败原因"
                      ><template #default="{ row }">{{
                        row.last_error_code || '—'
                      }}</template></el-table-column
                    ><el-table-column
                      prop="created_at"
                      label="提交时间" /><el-table-column
                      prop="completed_at"
                      label="完成时间"
                      ><template #default="{ row }">{{
                        row.completed_at || '未完成'
                      }}</template></el-table-column
                    ><el-table-column
                      prop="updated_at"
                      label="更新时间" /></el-table
                  ><el-pagination
                    :current-page="backupCenter.page"
                    :page-size="backupCenter.page_size"
                    :total="backupCenter.total"
                    layout="prev, pager, next, total"
                    @current-change="loadBackupCenter" /></template
                ><el-empty v-else description="备份中心快照尚未加载" /></el-card
            ></template>
            <template #providers
              ><el-alert
                v-if="opsErrors.providers"
                :title="opsErrors.providers"
                type="warning"
                :closable="false" /><provider-qualification-card
                :loading="providerQualificationsLoading"
                :snapshot="providerQualifications"
                @page-change="loadProviderQualifications"
            /></template>
            <template #diagnostics
              ><div v-if="can('platform.ops.logs.read')" class="toolbar"
                ><el-button
                  :loading="diagnosticLoading"
                  @click="downloadDiagnosticBundle"
                  >下载脱敏诊断包（最近 1 小时）</el-button
                ></div
              ></template
            >
          </ops-console-page>
        </template>
        <template v-else-if="view === 'tenants'"
          ><div class="toolbar"
            ><el-button type="primary" @click="openProvisionDialog"
              >新建租户</el-button
            ></div
          ><el-table
            v-loading="loading"
            :data="tenants"
            row-key="id"
            empty-text="暂无租户"
            ><el-table-column prop="code" label="租户编码" /><el-table-column
              prop="display_name"
              label="名称"
            /><el-table-column prop="status" label="状态"
              ><template #default="{ row }"
                ><el-tag :type="statusType(row.status)">{{
                  statusLabel(row.status)
                }}</el-tag></template
              ></el-table-column
            ><el-table-column
              prop="revision"
              label="版本"
              width="90"
            /><el-table-column label="操作" width="300"
              ><template #default="{ row }"
                ><el-button link type="primary" @click="showTenant(row)"
                  >详情</el-button
                ><el-button
                  v-if="can('platform.tenant.read')"
                  link
                  type="primary"
                  @click="
                    targetTenantId = row.id;
                    selectView('modules');
                  "
                  >许可证</el-button
                ><el-button
                  v-if="row.status !== 'active' && row.status !== 'closed'"
                  link
                  type="success"
                  @click="changeTenant(row, 'activate')"
                  >启用</el-button
                ><el-button
                  v-if="row.status === 'active'"
                  link
                  type="warning"
                  @click="changeTenant(row, 'suspend')"
                  >暂停</el-button
                ><el-button
                  v-if="row.status !== 'closed'"
                  link
                  type="danger"
                  @click="changeTenant(row, 'close')"
                  >关闭</el-button
                ></template
              ></el-table-column
            ></el-table
          ></template
        >
        <template v-else-if="view === 'owners'"
          ><tenant-picker /><div class="two-col"
            ><el-card
              ><template #header>发起租户所有者邀请</template
              ><el-form label-position="top" @submit.prevent="sendInvitation"
                ><el-form-item label="所有者邮箱"
                  ><el-input v-model="inviteForm.owner_email" /></el-form-item
                ><el-form-item label="所有者显示名"
                  ><el-input
                    v-model="inviteForm.owner_display_name" /></el-form-item
                ><el-form-item label="有效期（小时）"
                  ><el-input-number
                    v-model="inviteForm.expires_in_hours"
                    :min="1"
                    :max="720" /></el-form-item
                ><el-button
                  native-type="submit"
                  type="primary"
                  :loading="loading"
                  :disabled="!targetTenant"
                  >发送邀请</el-button
                ></el-form
              ></el-card
            ><el-card
              ><template #header>目标租户所有者</template
              ><el-descriptions v-if="owner" :column="1"
                ><el-descriptions-item label="姓名">{{
                  owner.display_name
                }}</el-descriptions-item
                ><el-descriptions-item label="邮箱">{{
                  owner.email || '未登记'
                }}</el-descriptions-item
                ><el-descriptions-item label="角色"
                  >租户所有者（{{ owner.role_key }}）</el-descriptions-item
                ></el-descriptions
              ><el-empty
                v-else
                description="尚未找到租户所有者" /></el-card></div
          ><el-table
            v-loading="loading"
            :data="invitations"
            empty-text="暂无邀请"
            ><el-table-column prop="email" label="邮箱" /><el-table-column
              prop="display_name"
              label="显示名"
            /><el-table-column prop="status" label="状态"
              ><template #default="{ row }">{{
                statusLabel(row.status)
              }}</template></el-table-column
            ><el-table-column prop="delivery_status" label="投递状态"
              ><template #default="{ row }">{{
                statusLabel(row.delivery_status)
              }}</template></el-table-column
            ><el-table-column
              prop="expires_at"
              label="过期时间"
            /><el-table-column label="操作" width="160"
              ><template #default="{ row }"
                ><el-button
                  v-if="row.status === 'pending'"
                  link
                  type="primary"
                  @click="resendInvitation(row)"
                  >重发</el-button
                ><el-button
                  v-if="row.status === 'pending'"
                  link
                  type="danger"
                  @click="revokeInvitation(row)"
                  >撤销</el-button
                ></template
              ></el-table-column
            ></el-table
          ></template
        >
        <template v-else-if="view === 'endpoints'"
          ><tenant-picker /><el-alert
            v-if="!targetTenant"
            title="当前显示全部租户入口；选择租户后可启用绑定。"
            type="info"
            :closable="false"
          /><div class="two-col"
            ><el-card
              ><template #header>启用入口绑定</template
              ><el-form label-position="top" @submit.prevent="enableBinding"
                ><el-form-item label="域名"
                  ><el-input
                    v-model="bindingForm.host"
                    placeholder="admin.example.com" /></el-form-item
                ><el-form-item label="客户端"
                  ><el-select v-model="bindingForm.client_key" class="full"
                    ><el-option
                      label="管理端网页"
                      value="admin-web" /><el-option
                      label="会员接口"
                      value="member-api" /></el-select></el-form-item
                ><el-form-item label="变更原因"
                  ><el-input
                    v-model="bindingForm.change_reason" /></el-form-item
                ><el-button
                  native-type="submit"
                  type="primary"
                  :disabled="!targetTenant"
                  :loading="loading"
                  >启用绑定</el-button
                ></el-form
              ></el-card
            ><el-card
              ><template #header>绑定规则</template
              ><p class="muted"
                >一个域名和客户端只能绑定一个有效租户。停用操作会立即撤销该入口识别。</p
              ></el-card
            ></div
          ><el-table
            v-loading="loading"
            :data="bindings"
            :empty-text="
              bindingLoadState === 'error'
                ? '入口绑定加载失败，请刷新重试'
                : bindingLoadState === 'ready'
                ? '暂无入口绑定'
                : '正在加载入口绑定'
            "
            ><el-table-column prop="host" label="域名" /><el-table-column
              prop="tenant_name"
              label="租户"
            /><el-table-column prop="client_key" label="客户端"
              ><template #default="{ row }">{{
                clientLabel(row.client_key)
              }}</template></el-table-column
            ><el-table-column prop="status" label="状态"
              ><template #default="{ row }">{{
                statusLabel(row.status)
              }}</template></el-table-column
            ><el-table-column label="操作" width="120"
              ><template #default="{ row }"
                ><el-button
                  v-if="row.status === 'active'"
                  link
                  type="danger"
                  @click="disableBinding(row)"
                  >停用</el-button
                ></template
              ></el-table-column
            ></el-table
          ></template
        >
        <PlansPanel
          v-else-if="view === 'plans'"
          :can-manage="can('platform.plan.manage')"
        />
        <template v-else-if="view === 'modules'"
          ><el-alert
            title="模块权益由当前许可证的套餐及有效期派生；实例安装与成员授权分别管理。此表只读。"
            type="info"
            :closable="false" /><tenant-picker /><TenantLicensePanel
            v-if="targetTenantId"
            :key="targetTenantId"
            :tenant-id="Number(targetTenantId)"
            :can-manage="can('platform.tenant.license.manage')"
            @saved="loadView" /><el-empty
            v-if="!targetTenant"
            description="请先选择操作目标租户" /><el-table
            v-else
            v-loading="loading"
            :data="modules"
            :empty-text="
              loading ? '模块列表正在加载…' : '本实例没有已安装的可分配模块'
            "
            ><el-table-column
              prop="module_key"
              label="模块键" /><el-table-column
              prop="installed_version"
              label="安装版本" /><el-table-column
              prop="installation_status"
              label="实例安装状态"
              ><template #default="{ row }">{{
                statusLabel(row.installation_status)
              }}</template></el-table-column
            ><el-table-column prop="status" label="租户开通状态"
              ><template #default="{ row }">{{
                statusLabel(row.status)
              }}</template></el-table-column
            ><el-table-column prop="source" label="配置来源" /><el-table-column
              prop="config_revision"
              label="配置版本" /><el-table-column
              prop="disabled_reason"
              label="停用原因" /></el-table
        ></template>
        <template v-else-if="view === 'storage'"
          ><el-alert
            title="一个实例可配置多个账号和多个公开/私有 Space；切换默认路由只影响新文件，旧文件始终绑定原 Space。"
            type="info"
            :closable="false" /><div class="toolbar"
            ><el-button type="primary" @click="createStorageAccount"
              >新增账号</el-button
            ><el-button @click="createStorageSpace">新增 Space</el-button></div
          ><el-card
            ><template #header>存储账号</template
            ><el-table :data="storage.accounts"
              ><el-table-column
                prop="account_key"
                label="标识"
              /><el-table-column prop="driver" label="供应商" /><el-table-column
                prop="name"
                label="名称"
              /><el-table-column prop="status" label="状态"
                ><template #default="{ row }">{{
                  statusLabel(row.status)
                }}</template></el-table-column
              ></el-table
            ></el-card
          ><el-card
            ><template #header>Space / Bucket</template
            ><el-table :data="storage.spaces"
              ><el-table-column prop="space_key" label="标识" /><el-table-column
                prop="account_key"
                label="账号"
              /><el-table-column prop="name" label="名称" /><el-table-column
                prop="access_type"
                label="公开属性"
              /><el-table-column prop="bucket" label="Bucket/目录"
                ><template #default="{ row }">{{
                  row.bucket || row.local_path
                }}</template></el-table-column
              ><el-table-column prop="status" label="状态"
                ><template #default="{ row }">{{
                  statusLabel(row.status)
                }}</template></el-table-column
              ><el-table-column label="操作" width="120"
                ><template #default="{ row }"
                  ><el-button
                    v-if="row.status === 'active'"
                    link
                    type="primary"
                    @click="setDefaultStorageRoute(row)"
                    >设为默认</el-button
                  ></template
                ></el-table-column
              ></el-table
            ></el-card
          ><el-card
            ><template #header>上传路由</template
            ><el-table :data="storage.routes"
              ><el-table-column
                prop="route_key"
                label="用途/默认" /><el-table-column
                prop="access_type"
                label="公开属性" /><el-table-column
                prop="space_name"
                label="目标 Space" /><el-table-column
                prop="driver"
                label="供应商" /></el-table></el-card
        ></template>
        <template v-else-if="view === 'operators'"
          ><div class="two-col"
            ><el-card
              ><template #header>创建平台操作员</template
              ><el-form label-position="top" @submit.prevent="createOperator"
                ><el-form-item label="邮箱"
                  ><el-input v-model="operatorForm.email" /></el-form-item
                ><el-form-item label="显示名"
                  ><el-input
                    v-model="operatorForm.display_name" /></el-form-item
                ><el-form-item label="初始密码（已有账户可留空）"
                  ><el-input
                    v-model="operatorForm.initial_password"
                    type="password"
                    show-password /></el-form-item
                ><el-button
                  native-type="submit"
                  type="primary"
                  :loading="loading"
                  >创建操作员</el-button
                ></el-form
              ></el-card
            ><el-card
              ><template #header>角色分配</template
              ><p class="muted"
                >在下表操作中调整操作员角色；变更会使其下一次会话按新权限生效。</p
              ></el-card
            ></div
          ><el-table
            v-loading="loading"
            :data="operators"
            empty-text="暂无平台操作员"
            ><el-table-column
              prop="display_name"
              label="显示名"
            /><el-table-column prop="email" label="邮箱" /><el-table-column
              prop="status"
              label="状态"
              ><template #default="{ row }">{{
                statusLabel(row.status)
              }}</template></el-table-column
            ><el-table-column label="角色"
              ><template #default="{ row }">{{
                row.role_keys.join(', ') || '无'
              }}</template></el-table-column
            ><el-table-column label="操作" width="310"
              ><template #default="{ row }"
                ><el-button link @click="editOperator(row)">编辑</el-button
                ><el-button
                  link
                  type="primary"
                  @click="assignOperatorRoles(row)"
                  >角色</el-button
                ><el-button
                  v-if="row.status !== 'active' && row.status !== 'closed'"
                  link
                  type="success"
                  @click="transitionOperator(row, 'activate')"
                  >启用</el-button
                ><el-button
                  v-if="row.status === 'active'"
                  link
                  type="warning"
                  @click="transitionOperator(row, 'suspend')"
                  >暂停</el-button
                ><el-button
                  v-if="row.status !== 'closed'"
                  link
                  type="danger"
                  @click="transitionOperator(row, 'close')"
                  >关闭</el-button
                ></template
              ></el-table-column
            ></el-table
          ></template
        >
        <template v-else-if="view === 'roles'"
          ><PermissionBundlesPanel
            v-if="can('platform.permission.read')"
            :can-manage="can('platform.permission.api.manage')"
          /><div class="two-col"
            ><el-card
              ><template #header>创建平台角色</template
              ><el-form label-position="top" @submit.prevent="createRole"
                ><el-form-item label="角色键"
                  ><el-input
                    v-model="roleForm.key"
                    placeholder="platform.example.manage" /></el-form-item
                ><el-form-item label="名称"
                  ><el-input v-model="roleForm.name" /></el-form-item
                ><el-form-item label="说明"
                  ><el-input
                    v-model="roleForm.description"
                    type="textarea" /></el-form-item
                ><el-button
                  native-type="submit"
                  type="primary"
                  :loading="loading"
                  >创建角色</el-button
                ></el-form
              ></el-card
            ><el-card
              ><template #header
                >权限目录{{
                  permissionRole ? `：${permissionRole.name}` : ''
                }}</template
              ><p v-if="permissionRole">
                当前已授予
                {{ permissionRole.permission_keys.length }} 项，编辑已选
                {{ permissionSelection.length }} 项
              </p>
              <el-alert
                v-if="permissionEditingBlockedReason"
                :title="permissionEditingBlockedReason"
                type="warning"
                :closable="false"
              />
              <div v-if="unmappedPermissionKeys.length">
                <p>以下权限已保留，但当前目录无法展示其定义：</p>
                <ul>
                  <li v-for="key in unmappedPermissionKeys" :key="key">
                    {{ key }}（目录外权限）
                  </li>
                </ul>
              </div>
              <el-scrollbar height="220px"
                ><el-checkbox-group
                  v-model="permissionSelection"
                  :disabled="!canEditPermissions || permissionSubmitting"
                  ><el-checkbox
                    v-for="permission in permissions"
                    :key="permission.key"
                    :value="permission.key"
                    >{{
                      permission.name === permission.key
                        ? permission.key
                        : `${permission.name}（${permission.key}）`
                    }}</el-checkbox
                  ></el-checkbox-group
                ></el-scrollbar
              ><el-button
                type="primary"
                :disabled="!canEditPermissions || permissionSubmitting"
                :loading="loading || permissionSubmitting"
                @click="savePermissions"
                >保存当前角色权限</el-button
              ></el-card
            ></div
          ><el-table v-loading="loading" :data="roles" empty-text="暂无平台角色"
            ><el-table-column prop="key" label="角色键" /><el-table-column
              prop="name"
              label="名称"
            /><el-table-column prop="status" label="状态"
              ><template #default="{ row }">{{
                statusLabel(row.status)
              }}</template></el-table-column
            ><el-table-column label="权限"
              ><template #default="{ row }">{{
                row.permission_count
              }}</template></el-table-column
            ><el-table-column label="操作" width="250"
              ><template #default="{ row }"
                ><el-button
                  link
                  :disabled="
                    row.status !== 'active' || !can('platform.role.update')
                  "
                  @click="editRole(row)"
                  >编辑</el-button
                ><el-button
                  link
                  type="primary"
                  :disabled="
                    row.status !== 'active' ||
                    !can('platform.permission.read') ||
                    !can('platform.role.permission.assign')
                  "
                  @click="editPermissions(row)"
                  >编辑权限</el-button
                ><span v-if="row.status === 'archived'" class="muted"
                  >已归档，仅可查看</span
                ><el-button
                  v-if="!row.is_builtin && row.status === 'active'"
                  link
                  type="danger"
                  @click="archiveRole(row)"
                  >归档</el-button
                ></template
              ></el-table-column
            ></el-table
          ></template
        >
        <template v-else-if="view === 'audit'"
          ><p
            v-if="auditLoadState === 'loading'"
            role="status"
            aria-live="polite"
            >正在加载平台审计事件…</p
          ><template v-else-if="auditLoadState === 'error'"
            ><el-alert
              :title="error || '平台审计事件加载失败'"
              type="error"
              show-icon
              :closable="false"
            /><el-button @click="loadView">重试</el-button></template
          ><template v-else-if="auditLoadState === 'ready'"
            ><el-table :data="audits" empty-text="暂无平台审计事件"
              ><el-table-column
                prop="occurred_at"
                label="时间"
                width="180" /><el-table-column
                prop="event_type"
                label="事件类型" /><el-table-column
                prop="action"
                label="操作" /><el-table-column
                prop="outcome"
                label="结果" /><el-table-column
                prop="target_type"
                label="目标" /><el-table-column
                prop="target_id"
                label="目标 ID" /><el-table-column
                prop="request_id"
                label="请求 ID" /></el-table
            ><el-pagination
              v-if="auditTotal > 0"
              :current-page="auditPage"
              :page-size="auditPageSize"
              :total="auditTotal"
              layout="prev, pager, next, total"
              @current-change="changeAuditPage" /></template
        ></template>
        <template v-else-if="view === 'developer'"
          ><developer-center-page
        /></template> </el-main
    ></el-container>
  </component>
  <el-dialog
    v-model="provisionDialog"
    title="新建租户"
    width="520px"
    :close-on-click-modal="!loading"
    :close-on-press-escape="!loading"
    :show-close="!loading"
    ><el-form
      ref="provisionFormRef"
      :model="provision"
      :rules="provisionRules"
      label-position="top"
      @submit.prevent="createTenant"
      ><el-alert
        v-if="provisionError"
        :title="provisionError"
        type="error"
        :closable="false"
        show-icon /><el-form-item label="租户编码" prop="tenant_code"
        ><el-input
          v-model="provision.tenant_code"
          :maxlength="64"
          placeholder="小写字母开头，可含数字和连字符，最多 64 位" /></el-form-item
      ><el-form-item label="租户名称" prop="tenant_name"
        ><el-input
          v-model="provision.tenant_name"
          :maxlength="160"
          show-word-limit /></el-form-item
      ><el-form-item label="首位所有者邮箱" prop="owner_email"
        ><el-input
          v-model="provision.owner_email"
          type="email"
          :maxlength="255" /></el-form-item
      ><el-form-item label="首位所有者显示名" prop="owner_display_name"
        ><el-input
          v-model="provision.owner_display_name"
          :maxlength="120"
          show-word-limit /></el-form-item
      ><el-form-item label="邀请有效期（小时）" prop="expires_in_hours"
        ><el-input-number
          v-model="provision.expires_in_hours"
          :min="1"
          :max="720" /></el-form-item></el-form
    ><template #footer
      ><el-button :disabled="loading" @click="provisionDialog = false"
        >取消</el-button
      ><el-button type="primary" :loading="loading" @click="createTenant"
        >创建并发送邀请</el-button
      ></template
    ></el-dialog
  >
</template>

<script setup lang="ts">
  import {
    computed,
    defineComponent,
    h,
    nextTick,
    onMounted,
    onUnmounted,
    provide,
    reactive,
    ref,
  } from 'vue';
  import {
    ElMessage,
    ElMenuItemGroup,
    ElSubMenu,
    ElMessageBox,
    ElButton,
    ElInput,
    ElOption,
    ElSelect,
    type FormInstance,
    type FormRules,
  } from 'element-plus';
  import {
    createOpsConsoleRuntime,
    OpsConsolePage,
    opsConsoleRuntimeKey,
  } from './modules/peanut-ops/contribution';
  import {
    api,
    createPlatformOpsTransport,
    hasPlatformSession,
    isPlatformSessionCurrent,
    onPlatformSessionChange,
    type AuditEvent,
    type EntryBinding,
    type Invitation,
    type InvitationInspection,
    type ModuleState,
    type Operator,
    type OpsBackupCenterSnapshot,
    type OpsUpgradeCenterSnapshot,
    type OpsUpgradeReadinessSnapshot,
    type OpsUpgradeReadinessState,
    type Permission,
    type PlatformRole,
    type ProviderQualificationSnapshot,
    type StorageSnapshot,
    type StorageSpace,
    type Tenant,
    type TenantOwner,
  } from './api/platform';
  import ProviderQualificationCard from './components/ProviderQualificationCard.vue';
  import DeveloperCenterPage from './components/DeveloperCenterPage.vue';
  import PermissionBundlesPanel from './components/PermissionBundlesPanel.vue';
  import PlansPanel from './components/PlansPanel.vue';
  import TenantLicensePanel from './components/TenantLicensePanel.vue';
  import {
    platformPresentation,
    platformShell,
    platformUserOptions,
    selectPlatformPreset,
  } from './peanut.overrides';

  const hasPresentationChoices = Object.values(platformUserOptions).some(
    (values) => values.length > 1
  );

  const navigationGroup = computed(() =>
    platformPresentation.navigationMode === 'grouped'
      ? ElMenuItemGroup
      : ElSubMenu
  );

  const tokenFromUrl = new URLSearchParams(window.location.search).get(
    'invitation'
  );
  const invitationToken = ref(tokenFromUrl || '');
  const invitation = ref<InvitationInspection | null>(null);
  const acceptPassword = ref('');
  const authenticated = ref(hasPlatformSession());
  const platformAccount = ref('');
  const loading = ref(false);
  const error = ref('');
  const view = ref('overview');
  const navigationOpen = ref(false);
  const navigationViewport = window.matchMedia('(max-width: 760px)');
  function handleNavigationViewportChange(event: MediaQueryListEvent) {
    if (!event.matches) navigationOpen.value = false;
  }
  function closeNavigation() {
    if (!navigationOpen.value) return;
    navigationOpen.value = false;
    (
      document.querySelector('.navigation-toggle') as HTMLElement | null
    )?.focus();
  }
  function toggleNavigation() {
    navigationOpen.value = !navigationOpen.value;
    if (navigationOpen.value) {
      nextTick(() =>
        (
          document.querySelector(
            '.platform-navigation .el-menu-item'
          ) as HTMLElement | null
        )?.focus()
      );
    }
  }
  const tenants = ref<Tenant[]>([]);
  const invitations = ref<Invitation[]>([]);
  const bindings = ref<EntryBinding[]>([]);
  const bindingLoadState = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
  const modules = ref<ModuleState[]>([]);
  const operators = ref<Operator[]>([]);
  const roles = ref<PlatformRole[]>([]);
  const permissions = ref<Permission[]>([]);
  const permissionCatalogState = ref<'idle' | 'loading' | 'ready' | 'error'>(
    'idle'
  );
  const permissionCatalogError = ref('');
  let permissionCatalogRequest = 0;
  const audits = ref<AuditEvent[]>([]);
  const auditPageSize = 20;
  const auditPage = ref(1);
  const auditTotal = ref(0);
  const auditLoadState = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
  const storage = ref<StorageSnapshot>({
    accounts: [],
    spaces: [],
    routes: [],
    purposes: [],
  });
  const owner = ref<TenantOwner | null>(null);
  const targetTenantId = ref<number | null>(null);
  let viewRequest = 0;
  const provisionDialog = ref(false);
  const permissionSelection = ref<string[]>([]);
  const permissionRole = ref<PlatformRole | null>(null);
  const permissionSubmitting = ref(false);
  const platformPermissions = ref<string[]>([]);
  const diagnosticLoading = ref(false);
  const backupCenter = ref<OpsBackupCenterSnapshot | null>(null);
  let backupCenterRequest = 0;
  let providerQualificationsRequest = 0;
  const backupCenterLoading = ref(false);
  const opsErrors = ref<Record<string, string>>({});
  const upgradeReadiness = ref<OpsUpgradeReadinessSnapshot | null>(null);
  const upgradeReadinessLoading = ref(false);
  const upgradeCenter = ref<OpsUpgradeCenterSnapshot | null>(null);
  const upgradeCenterLoading = ref(false);
  const upgradeSubmitting = ref(false);
  const providerQualifications = ref<ProviderQualificationSnapshot | null>(
    null
  );
  const providerQualificationsLoading = ref(false);
  const stopPlatformSessionSync = onPlatformSessionChange((present) => {
    authenticated.value = present;
    platformPermissions.value = [];
    platformAccount.value = '';
    tenants.value = [];
    invitations.value = [];
    bindings.value = [];
    bindingLoadState.value = 'idle';
    modules.value = [];
    operators.value = [];
    roles.value = [];
    permissions.value = [];
    permissionCatalogRequest += 1;
    permissionCatalogState.value = 'idle';
    permissionCatalogError.value = '';
    audits.value = [];
    auditPage.value = 1;
    auditTotal.value = 0;
    auditLoadState.value = 'idle';
    owner.value = null;
    storage.value = { accounts: [], spaces: [], routes: [], purposes: [] };
    permissionRole.value = null;
    permissionSelection.value = [];
    provisionDialog.value = false;
    view.value = 'overview';
    targetTenantId.value = null;
    backupCenter.value = null;
    upgradeReadiness.value = null;
    upgradeCenter.value = null;
    providerQualifications.value = null;
    opsRuntime.dispose();
  });
  const can = (permission: string) =>
    platformPermissions.value.includes(permission);
  const unmappedPermissionKeys = computed(() => {
    const keys = new Set(permissions.value.map((permission) => permission.key));
    return [
      ...new Set([
        ...(permissionRole.value?.permission_keys || []),
        ...permissionSelection.value,
      ]),
    ].filter((key) => !keys.has(key));
  });
  const permissionEditingBlockedReason = computed(() => {
    if (!can('platform.permission.read'))
      return '缺少读取权限目录的权限，无法安全编辑。';
    if (!can('platform.role.permission.assign'))
      return '缺少分配角色权限的权限，无法保存。';
    if (permissionCatalogState.value === 'loading')
      return '权限目录正在加载，暂不能编辑或保存。';
    if (permissionCatalogState.value !== 'ready')
      return (
        permissionCatalogError.value || '权限目录尚未加载，暂不能编辑或保存。'
      );
    if (!permissionRole.value) return '请选择需要编辑权限的角色。';
    if (
      roles.value.find((role) => role.id === permissionRole.value?.id)
        ?.status !== 'active'
    )
      return '角色已归档或不可用，仅可查看，不能编辑或保存权限。';
    if (
      permissionRole.value.permission_count !==
      new Set(permissionRole.value.permission_keys).size
    )
      return '角色已授予权限列表不完整，已禁止编辑和保存，请刷新后重试。';
    if (unmappedPermissionKeys.value.length)
      return '当前目录未包含全部已授予权限，已禁止编辑和保存；请先修复权限目录，再刷新重试。';
    return '';
  });
  const canEditPermissions = computed(
    () =>
      view.value === 'roles' &&
      !loading.value &&
      !permissionEditingBlockedReason.value
  );
  const opsRuntime = createOpsConsoleRuntime({
    transport: createPlatformOpsTransport(),
    providers: [
      {
        key: 'peanut.paired-db-files',
        backup: true,
        restoreTargets: ['isolated-new-target'],
      },
    ],
    maintenanceReasons: [
      'planned-upgrade',
      'database-maintenance',
      'security-maintenance',
    ],
    logSources: ['platform.audit'],
    canRead: () => can('platform.ops.read'),
    canBackup: () => can('platform.ops.backup.manage'),
    canRestore: () => can('platform.ops.restore.manage'),
    canMaintain: () => can('platform.ops.maintenance.manage'),
    canReadLogs: () => can('platform.ops.logs.read'),
  });
  provide(opsConsoleRuntimeKey, opsRuntime);
  const credentials = reactive({ email: '', password: '' });
  const provision = reactive({
    tenant_code: '',
    tenant_name: '',
    owner_email: '',
    owner_display_name: '',
    expires_in_hours: 72,
  });
  const provisionFormRef = ref<FormInstance>();
  const provisionError = ref('');
  const requiredTextRule = (label: string, max: number) => ({
    validator: (
      _rule: unknown,
      value: string,
      callback: (error?: Error) => void
    ) => {
      const length = Array.from(String(value ?? '').trim()).length;
      callback(
        length < 1 || length > max
          ? new Error(`${label}不能为空且最多 ${max} 个字`)
          : undefined
      );
    },
    trigger: 'blur',
  });
  const provisionRules: FormRules = {
    tenant_code: [
      { required: true, message: '请输入租户编码', trigger: 'blur' },
      {
        pattern: /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/,
        message: '租户编码须以小写字母开头，只能使用小写字母、数字和连字符',
        trigger: 'blur',
      },
      { max: 64, message: '租户编码最多 64 位', trigger: 'blur' },
    ],
    tenant_name: [requiredTextRule('租户名称', 160)],
    owner_email: [
      { required: true, message: '请输入所有者邮箱', trigger: 'blur' },
      { type: 'email', message: '请输入有效的所有者邮箱', trigger: 'blur' },
      { max: 255, message: '所有者邮箱最多 255 位', trigger: 'blur' },
    ],
    owner_display_name: [requiredTextRule('所有者显示名', 120)],
    expires_in_hours: [
      {
        validator: (
          _rule: unknown,
          value: number,
          callback: (error?: Error) => void
        ) => {
          callback(
            !Number.isInteger(value) || value < 1 || value > 720
              ? new Error('邀请有效期须为 1～720 小时的整数')
              : undefined
          );
        },
        trigger: 'change',
      },
    ],
  };
  const inviteForm = reactive({
    owner_email: '',
    owner_display_name: '',
    expires_in_hours: 72,
  });
  const bindingForm = reactive({
    host: '',
    client_key: 'admin-web',
    change_reason: '',
  });
  const operatorForm = reactive({
    email: '',
    display_name: '',
    initial_password: '',
  });
  const roleForm = reactive({ key: '', name: '', description: '' });
  const labels: Record<string, string> = {
    overview: '概览',
    ops: '运行与维护',
    tenants: '租户与生命周期',
    owners: '租户所有者邀请管理',
    endpoints: '入口域名与客户端',
    modules: '租户许可证与模块权益',
    plans: '套餐管理',
    developer: '开发者中心',
    storage: '存储基础设施',
    operators: '实例平台操作员',
    roles: '平台角色与权限',
    audit: '平台审计',
  };
  const title = computed(() => labels[view.value]);
  const activeCount = computed(
    () => tenants.value.filter((tenant) => tenant.status === 'active').length
  );
  const targetTenant = computed(() =>
    tenants.value.find((tenant) => tenant.id === targetTenantId.value)
  );
  const latestUpgrade = computed(() => upgradeCenter.value?.tasks[0] || null);
  const activeUpgrade = computed(() =>
    ['queued', 'running'].includes(latestUpgrade.value?.status || '')
  );
  const TenantPicker = defineComponent({
    setup: () => () =>
      h('div', { class: 'toolbar' }, [
        h('span', { class: 'muted' }, '操作目标租户'),
        h(
          ElSelect,
          {
            'modelValue': targetTenantId.value,
            'disabled': loading.value,
            'onUpdate:modelValue': (id: number) => {
              if (loading.value || id === targetTenantId.value) return;
              targetTenantId.value = id;
              invitations.value = [];
              owner.value = null;
              bindings.value = [];
              modules.value = [];
              void loadView();
            },
            'placeholder': '选择目标租户',
            'style': 'width: 280px',
          },
          () =>
            tenants.value.map((tenant) =>
              h(ElOption, {
                key: tenant.id,
                label: `${tenant.display_name} (${tenant.code})`,
                value: tenant.id,
              })
            )
        ),
      ]),
  });
  function statusType(status: string) {
    return status === 'active' || status === 'enabled'
      ? 'success'
      : status === 'suspended'
      ? 'warning'
      : status === 'closed' || status === 'disabled'
      ? 'danger'
      : 'info';
  }
  function statusLabel(status: string) {
    return (
      (
        {
          active: '正常',
          enabled: '已启用',
          not_enabled: '未开通',
          provisioning: '待配置',
          pending: '待接受',
          pending_delivery: '待投递',
          delivered: '已投递',
          accepted: '已接受',
          revoked: '已撤销',
          expired: '已过期',
          suspended: '已暂停',
          disabled: '已停用',
          closed: '已关闭',
          archived: '已归档',
          queued: '排队中',
          running: '执行中',
          succeeded: '已完成',
          dead: '失败',
          cancelled: '已取消',
        } as Record<string, string>
      )[status] || status
    );
  }
  function clientLabel(client: string) {
    return (
      (
        { 'admin-web': '管理端网页', 'member-api': '会员接口' } as Record<
          string,
          string
        >
      )[client] || client
    );
  }
  function formatBackupAge(seconds: number) {
    const safeSeconds = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(safeSeconds / 60);
    if (minutes < 1) return `${safeSeconds} 秒`;
    const hours = Math.floor(minutes / 60);
    if (hours < 1) return `${minutes} 分钟`;
    const days = Math.floor(hours / 24);
    return days < 1 ? `${hours} 小时` : `${days} 天`;
  }
  function opsReason(code: string) {
    return (
      (
        {
          SOURCE_IDENTITY_UNKNOWN:
            '当前为源码运行，未提供可验证发行身份。健康、维护与日志可独立使用；升级需先固定来源与目标。',
          UPGRADE_TARGET_NOT_STAGED: '尚未配置固定升级目标。',
          UPGRADE_SOURCE_APPLICATION_MANIFEST_REQUIRED:
            '当前应用尚未提供升级来源清单。',
          UPGRADE_REPOSITORY_DIRTY: '源码存在未提交变更，暂不具备升级条件。',
        } as Record<string, string>
      )[code] || code
    );
  }
  function readinessLabel(state: OpsUpgradeReadinessState) {
    return (
      {
        ready: '已就绪',
        blocked: '已阻塞',
        configuration_required: '待配置',
      } as Record<OpsUpgradeReadinessState, string>
    )[state];
  }
  function readinessType(state: OpsUpgradeReadinessState) {
    return state === 'ready'
      ? 'success'
      : state === 'blocked'
      ? 'danger'
      : 'warning';
  }
  function upgradeStepLabel(step: string) {
    return (
      (
        {
          preflight: '静态预检',
          backup: '新鲜配对备份',
          restore_verification: '隔离恢复验证',
          maintenance: '维护门禁',
          deployment: '部署与迁移',
          smoke: 'Runtime smoke',
          recovery_pointer: '恢复指针',
          completed: '已完成',
        } as Record<string, string>
      )[step] || step
    );
  }
  function message(cause: unknown) {
    return cause instanceof Error ? cause.message : '请求失败，请稍后重试';
  }
  async function run(action: () => Promise<unknown>, success: string) {
    loading.value = true;
    error.value = '';
    try {
      await action();
      ElMessage.success(success);
      await loadView();
    } catch (cause) {
      error.value = message(cause);
    } finally {
      loading.value = false;
    }
  }
  async function loadTenants(isCurrent: () => boolean = () => true) {
    const result = await api.tenants();
    if (!isCurrent()) return;
    tenants.value = result.lists;
    if (targetTenantId.value && !targetTenant.value)
      targetTenantId.value = null;
  }
  async function loadSessionInfo() {
    const info = await api.sessionInfo();
    platformAccount.value = info.account_id;
    platformPermissions.value = info.permissions;
    if (!can('platform.tenant.read') && can('platform.ops.read'))
      view.value = 'ops';
  }
  async function loadBackupCenter(page = 1) {
    const current = ++backupCenterRequest;
    backupCenterLoading.value = true;
    opsErrors.value.backups = '';
    try {
      const snapshot = await api.backupCenter(page);
      if (current === backupCenterRequest) backupCenter.value = snapshot;
    } catch (cause) {
      if (current !== backupCenterRequest) return;
      opsErrors.value.backups = message(cause);
      if (!hasPlatformSession()) authenticated.value = false;
    } finally {
      if (current === backupCenterRequest) backupCenterLoading.value = false;
    }
  }
  async function loadUpgradeReadiness() {
    upgradeReadinessLoading.value = true;
    opsErrors.value.readiness = '';
    try {
      upgradeReadiness.value = await api.upgradeReadiness();
    } catch (cause) {
      opsErrors.value.readiness = message(cause);
      if (!hasPlatformSession()) authenticated.value = false;
    } finally {
      upgradeReadinessLoading.value = false;
    }
  }
  async function loadUpgradeCenter() {
    upgradeCenterLoading.value = true;
    opsErrors.value.upgrades = '';
    try {
      upgradeCenter.value = await api.upgradeCenter();
    } catch (cause) {
      opsErrors.value.upgrades = message(cause);
      if (!hasPlatformSession()) authenticated.value = false;
    } finally {
      upgradeCenterLoading.value = false;
    }
  }
  async function loadProviderQualifications(page = 1) {
    const current = ++providerQualificationsRequest;
    providerQualificationsLoading.value = true;
    opsErrors.value.providers = '';
    try {
      const snapshot = await api.providerQualifications(page);
      if (current === providerQualificationsRequest)
        providerQualifications.value = snapshot;
    } catch (cause) {
      if (current !== providerQualificationsRequest) return;
      opsErrors.value.providers = message(cause);
      if (!hasPlatformSession()) authenticated.value = false;
    } finally {
      if (current === providerQualificationsRequest)
        providerQualificationsLoading.value = false;
    }
  }
  async function loadOpsView() {
    await Promise.all([
      loadUpgradeReadiness(),
      loadUpgradeCenter(),
      loadBackupCenter(),
      loadProviderQualifications(),
    ]);
  }
  async function loadView() {
    const request = ++viewRequest;
    const requestedView = view.value;
    const requestedTenantId = targetTenantId.value;
    const isCurrentRequest = () =>
      request === viewRequest && requestedView === view.value;
    const isCurrentTenantRequest = (tenantId: number | null) =>
      isCurrentRequest() &&
      (requestedTenantId === null || requestedTenantId === tenantId) &&
      targetTenantId.value === tenantId;
    if (requestedView === 'ops') {
      loading.value = true;
      error.value = '';
      try {
        await Promise.all([opsRuntime.load(), loadOpsView()]);
      } finally {
        if (request === viewRequest) loading.value = false;
      }
      return;
    }
    loading.value = true;
    error.value = '';
    if (requestedView === 'endpoints') {
      bindingLoadState.value = 'loading';
      bindings.value = [];
    } else if (requestedView === 'audit') {
      auditLoadState.value = 'loading';
      audits.value = [];
      auditTotal.value = 0;
    }
    try {
      if (requestedView === 'overview') {
        await Promise.all([
          loadTenants(isCurrentRequest),
          api.operators().then((result) => {
            if (isCurrentRequest()) operators.value = result.lists;
          }),
        ]);
      } else if (requestedView === 'tenants') {
        await loadTenants(isCurrentRequest);
      } else if (requestedView === 'owners') {
        await loadTenants(isCurrentRequest);
        if (!isCurrentRequest()) return;
        const tenantId = targetTenantId.value;
        const ownerData = tenantId
          ? await Promise.all([
              api.invitations(tenantId).then((result) => result.lists),
              api.tenantOwner(tenantId).catch(() => null),
            ])
          : null;
        if (!isCurrentTenantRequest(tenantId)) return;
        if (ownerData) [invitations.value, owner.value] = ownerData;
        else [invitations.value, owner.value] = [[], null];
      } else if (requestedView === 'endpoints') {
        await loadTenants(isCurrentRequest);
        if (!isCurrentRequest()) return;
        const tenantId = targetTenantId.value;
        const bindingData = await api.entryBindings(tenantId ?? undefined);
        if (!isCurrentTenantRequest(tenantId)) return;
        bindings.value = bindingData;
        bindingLoadState.value = 'ready';
      } else if (requestedView === 'modules') {
        await loadTenants(isCurrentRequest);
        if (!isCurrentRequest()) return;
        const tenantId = targetTenantId.value;
        const moduleData = tenantId
          ? (await api.moduleStates(tenantId)).lists
          : [];
        if (!isCurrentTenantRequest(tenantId)) return;
        modules.value = moduleData;
      } else if (requestedView === 'storage') {
        await Promise.all([
          loadTenants(isCurrentRequest),
          api.storageSnapshot().then((result) => {
            if (isCurrentRequest()) storage.value = result;
          }),
        ]);
      } else if (requestedView === 'operators') {
        await Promise.all([
          loadTenants(isCurrentRequest),
          api.operators().then((result) => {
            if (isCurrentRequest()) operators.value = result.lists;
          }),
          api.roles().then((result) => {
            if (isCurrentRequest()) roles.value = result.lists;
          }),
        ]);
      } else if (requestedView === 'roles') {
        await Promise.all([
          loadTenants(isCurrentRequest),
          api.roles().then((result) => {
            if (isCurrentRequest()) roles.value = result.lists;
          }),
          loadPermissionCatalog(),
        ]);
      } else if (requestedView === 'audit') {
        const requestedPage = auditPage.value;
        const result = await api.audit(requestedPage, auditPageSize);
        if (!isCurrentRequest() || requestedPage !== auditPage.value) return;
        const lastPage = Math.max(1, Math.ceil(result.count / auditPageSize));
        if (requestedPage > lastPage) {
          auditPage.value = lastPage;
          await loadView();
          return;
        }
        audits.value = result.lists;
        auditTotal.value = result.count;
        auditLoadState.value = 'ready';
      }
    } catch (cause) {
      if (isCurrentRequest()) {
        error.value = message(cause);
        if (requestedView === 'endpoints') bindingLoadState.value = 'error';
        if (requestedView === 'audit') auditLoadState.value = 'error';
        if (!hasPlatformSession()) authenticated.value = false;
      }
    } finally {
      if (request === viewRequest) loading.value = false;
    }
  }
  async function refreshView() {
    if (view.value === 'ops')
      await Promise.all([opsRuntime.load(), loadOpsView()]);
    else await loadView();
  }
  async function loadPermissionCatalog() {
    const request = ++permissionCatalogRequest;
    permissionCatalogState.value = 'loading';
    permissionCatalogError.value = '';
    permissions.value = [];
    try {
      if (!can('platform.permission.read'))
        throw new Error('缺少读取权限目录的权限。');
      const result = await api.permissions();
      if (request !== permissionCatalogRequest) return;
      if (
        !Array.isArray(result.lists) ||
        !result.lists.every(
          (permission) =>
            permission &&
            typeof permission.key === 'string' &&
            permission.key.length > 0 &&
            typeof permission.name === 'string'
        ) ||
        !Number.isInteger(result.count) ||
        result.count !== result.lists.length ||
        result.count !==
          new Set(result.lists.map((permission) => permission.key)).size
      )
        throw new Error('权限目录不完整或格式异常，无法安全编辑。');
      permissions.value = result.lists;
      permissionCatalogState.value = 'ready';
    } catch (cause) {
      if (request !== permissionCatalogRequest) return;
      permissionCatalogState.value = 'error';
      permissionCatalogError.value = `${message(
        cause
      )} 已禁止编辑和保存，请刷新后重试。`;
    }
  }
  async function downloadDiagnosticBundle() {
    diagnosticLoading.value = true;
    error.value = '';
    try {
      const artifact = await api.downloadDiagnostics(60);
      const url = URL.createObjectURL(
        new Blob([artifact.bytes], { type: 'application/json' })
      );
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = artifact.filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      ElMessage.success(
        `诊断包已校验并下载（SHA-256 ${artifact.sha256.slice(0, 12)}…）`
      );
    } catch (cause) {
      error.value = message(cause);
    } finally {
      diagnosticLoading.value = false;
    }
  }
  async function executeUpgrade() {
    if (!upgradeReadiness.value?.target) return;
    try {
      await ElMessageBox.confirm(
        `将提交升级到 ${upgradeReadiness.value.target.release_key}。控制 worker 会先创建新备份并完成隔离恢复验证，再进入维护和部署。`,
        '确认执行固定升级',
        { type: 'warning' }
      );
      upgradeSubmitting.value = true;
      error.value = '';
      await api.submitUpgrade();
      ElMessage.success('升级任务已提交，等待登记的 deployment-control worker');
      await loadOpsView();
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    } finally {
      upgradeSubmitting.value = false;
    }
  }
  async function selectView(next: string) {
    if (next === 'audit' && view.value !== 'audit') auditPage.value = 1;
    view.value = next;
    closeNavigation();
    await loadView();
  }
  function changeAuditPage(page: number) {
    if (page === auditPage.value || auditLoadState.value === 'loading') return;
    auditPage.value = page;
    void loadView();
  }
  async function login() {
    await run(async () => {
      await api.login(credentials.email.trim(), credentials.password);
      await loadSessionInfo();
      authenticated.value = true;
      credentials.password = '';
    }, '登录成功');
  }
  async function logout() {
    const cleared = await api.logout();
    if (!cleared || !isPlatformSessionCurrent(cleared)) return;
    opsRuntime.dispose();
    authenticated.value = false;
    platformPermissions.value = [];
    tenants.value = [];
    targetTenantId.value = null;
    backupCenter.value = null;
    upgradeReadiness.value = null;
    upgradeCenter.value = null;
    providerQualifications.value = null;
    view.value = 'overview';
  }
  async function inspectInvitation() {
    if (!invitationToken.value) return;
    loading.value = true;
    error.value = '';
    try {
      invitation.value = await api.inspectInvitation(invitationToken.value);
    } catch (cause) {
      error.value = message(cause);
    } finally {
      loading.value = false;
    }
  }
  async function acceptInvitation() {
    if (loading.value || !invitation.value || !invitationToken.value) return;
    loading.value = true;
    error.value = '';
    try {
      await api.acceptInvitation(invitationToken.value, acceptPassword.value);
      invitation.value = null;
      invitationToken.value = '';
      window.history.replaceState({}, '', window.location.pathname);
      ElMessage.success('邀请已接受，请使用账户登录 Tenant');
    } catch (cause) {
      error.value = message(cause) || '接受邀请失败，请稍后重试';
    } finally {
      loading.value = false;
    }
  }
  function goToTenantLogin() {
    window.location.replace('/admin/');
  }
  function openProvisionDialog() {
    provisionError.value = '';
    provisionFormRef.value?.clearValidate();
    provisionDialog.value = true;
  }
  async function createTenant() {
    if (loading.value) return;
    provisionError.value = '';
    const valid = await provisionFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    loading.value = true;
    let result: Invitation | null = null;
    try {
      result = await api.provision({ ...provision });
    } catch (cause) {
      provisionError.value = message(cause);
    } finally {
      loading.value = false;
    }
    if (!result) return;
    provisionDialog.value = false;
    Object.assign(provision, {
      tenant_code: '',
      tenant_name: '',
      owner_email: '',
      owner_display_name: '',
      expires_in_hours: 72,
    });
    try {
      await showManualInvitation(result);
      ElMessage.success('租户已创建，所有者邀请已生成');
      await loadView();
    } catch (cause) {
      error.value = message(cause);
    }
  }
  async function changeTenant(
    tenant: Tenant,
    action: 'activate' | 'suspend' | 'close'
  ) {
    const actionLabel = { activate: '启用', suspend: '暂停', close: '关闭' }[
      action
    ];
    try {
      await ElMessageBox.confirm(
        `确认${actionLabel} ${tenant.display_name}？`,
        '确认生命周期变更',
        { type: 'warning' }
      );
      const result = await ElMessageBox.prompt('填写变更原因', '变更原因', {
        inputPattern: /\S+/,
        inputErrorMessage: '必须填写原因',
      });
      await run(
        () => api.transition(action, tenant, result.value),
        '租户状态已更新'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function showTenant(tenant: Tenant) {
    error.value = '';
    loading.value = true;
    let detail: Record<string, unknown>;
    try {
      detail = await api.tenantDetail(tenant.id);
    } catch (cause) {
      error.value = message(cause);
      return;
    } finally {
      loading.value = false;
    }
    try {
      await ElMessageBox.alert(
        `<pre>${escapeHtml(JSON.stringify(detail, null, 2))}</pre>`,
        `${tenant.display_name} 详情`,
        { dangerouslyUseHTMLString: true, closeOnPressEscape: true }
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  function escapeHtml(value: string) {
    return value.replace(
      /[&<>'"]/g,
      (char) =>
        ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          "'": '&#39;',
          '"': '&quot;',
        }[char] || char)
    );
  }
  async function showManualInvitation(result: Invitation) {
    if (!result.accept_token) return;
    let url = `${
      window.location.origin
    }/platform/?invitation=${encodeURIComponent(result.accept_token)}`;
    result.accept_token = undefined;
    const selectLink = (event: FocusEvent) => {
      if (event.target instanceof HTMLInputElement) event.target.select();
    };
    const copyLink = async () => {
      try {
        if (!navigator.clipboard?.writeText)
          throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(url);
        ElMessage.success('邀请链接已复制');
      } catch {
        ElMessage.error('复制失败，请选中链接手动复制');
      }
    };
    try {
      await ElMessageBox.alert(
        h('div', [
          h(
            'p',
            '当前实例使用人工交付模式。该链接只显示一次，请交给租户所有者：'
          ),
          h(ElInput, {
            'modelValue': url,
            'readonly': true,
            'aria-label': '一次性邀请链接',
            'onFocus': selectLink,
            'onClick': selectLink,
          }),
          h(
            'div',
            {
              style: {
                display: 'flex',
                alignItems: 'center',
                gap: '12px',
                marginTop: '12px',
              },
            },
            [
              h(ElButton, { onClick: copyLink }, () => '复制链接'),
              h(
                'a',
                {
                  href: url,
                  target: '_blank',
                  rel: 'noopener noreferrer',
                },
                '打开接受页面'
              ),
            ]
          ),
        ]),
        '一次性邀请链接',
        { confirmButtonText: '已记录', closeOnPressEscape: true }
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') throw cause;
    } finally {
      url = '';
    }
  }
  async function sendInvitation() {
    if (!targetTenantId.value) return;
    loading.value = true;
    error.value = '';
    try {
      const result = await api.inviteOwner({
        tenant_id: targetTenantId.value!,
        ...inviteForm,
      });
      await showManualInvitation(result);
      ElMessage.success('租户所有者邀请已创建');
      await loadView();
    } catch (cause) {
      error.value = message(cause);
    } finally {
      loading.value = false;
    }
  }
  async function resendInvitation(item: Invitation) {
    loading.value = true;
    error.value = '';
    try {
      const result = await api.resendInvitation(item.id);
      await showManualInvitation(result);
      ElMessage.success('邀请已重新生成');
      await loadView();
    } catch (cause) {
      error.value = message(cause);
    } finally {
      loading.value = false;
    }
  }
  async function revokeInvitation(item: Invitation) {
    try {
      await ElMessageBox.confirm(`撤销 ${item.email} 的邀请？`, '确认撤销', {
        type: 'warning',
      });
      await run(() => api.revokeInvitation(item.id), '邀请已撤销');
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function enableBinding() {
    if (!targetTenantId.value) return;
    await run(
      () =>
        api.enableEntryBinding({
          tenant_id: targetTenantId.value!,
          ...bindingForm,
        }),
      '入口绑定已启用'
    );
  }
  async function disableBinding(item: EntryBinding) {
    try {
      const result = await ElMessageBox.prompt(
        '填写停用原因',
        `停用 ${item.host}`,
        { inputPattern: /\S+/, inputErrorMessage: '必须填写原因' }
      );
      await run(
        () => api.disableEntryBinding(item.id, result.value),
        '入口绑定已停用'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function createStorageAccount() {
    try {
      const key = await ElMessageBox.prompt(
        '账号标识（如 qiniu-main）',
        '新增存储账号',
        {
          inputPattern: /^[a-z][a-z0-9._-]{2,63}$/,
          inputErrorMessage:
            '账号标识须为 3–64 位小写字母、数字、点、下划线或连字符，且以字母开头',
        }
      );
      const driver = await ElMessageBox.prompt(
        '供应商：local / qiniu / aliyun / qcloud',
        '供应商',
        {
          inputPattern: /^(local|qiniu|aliyun|qcloud)$/,
          inputErrorMessage: '请选择 local、qiniu、aliyun 或 qcloud',
        }
      );
      const name = await ElMessageBox.prompt('显示名称', '账号名称', {
        inputValidator: (value: string) => {
          const length = Array.from(value.trim()).length;
          return length > 0 && length <= 128;
        },
        inputErrorMessage: '显示名称不能为空且最多 128 个字',
      });
      let credentials: Record<string, string> = {};
      if (driver.value !== 'local') {
        const access = await ElMessageBox.prompt('Access Key', '存储凭据', {
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写 Access Key',
        });
        const secret = await ElMessageBox.prompt('Secret Key', '存储凭据', {
          inputType: 'password',
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写 Secret Key',
        });
        credentials = { access_key: access.value, secret_key: secret.value };
      }
      await run(
        () =>
          api.createStorageAccount({
            account_key: key.value,
            driver: driver.value,
            name: name.value,
            credentials,
          }),
        '存储账号已创建'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function createStorageSpace() {
    try {
      const account = await ElMessageBox.prompt(
        '账号标识（见存储账号列表）',
        '新增 Space',
        {
          inputValidator: (value: string) =>
            storage.value.accounts.some((a) => a.account_key === value.trim()),
          inputErrorMessage: '请输入存储账号列表中存在的账号标识',
        }
      );
      const accountRecord = storage.value.accounts.find(
        (a) => a.account_key === account.value.trim()
      );
      if (!accountRecord) throw new Error('存储账号已变化，请重新加载后重试');
      const key = await ElMessageBox.prompt('Space 标识', '新增 Space', {
        inputPattern: /^[a-z][a-z0-9._-]{2,63}$/,
        inputErrorMessage:
          'Space 标识须为 3–64 位小写字母、数字、点、下划线或连字符，且以字母开头',
      });
      const name = await ElMessageBox.prompt('显示名称', '新增 Space', {
        inputValidator: (value: string) => {
          const length = Array.from(value.trim()).length;
          return length > 0 && length <= 128;
        },
        inputErrorMessage: '显示名称不能为空且最多 128 个字',
      });
      const access = await ElMessageBox.prompt(
        '公开属性：public / private',
        '新增 Space',
        {
          inputPattern: /^(public|private)$/,
          inputErrorMessage: '请选择 public 或 private',
        }
      );
      const accountId = accountRecord.id;
      const isLocal = accountRecord.driver === 'local';
      const bucket = await ElMessageBox.prompt(
        'Bucket；本地账号填写 public/storage 或 private/storage',
        '物理位置',
        {
          inputPattern: isLocal
            ? new RegExp(`^${access.value}/storage$`)
            : /\S+/,
          inputErrorMessage: isLocal
            ? `本地账号须填写 ${access.value}/storage`
            : '必须填写 Bucket',
        }
      );
      const region = await ElMessageBox.prompt(
        'Region（不适用可留空）',
        '物理位置'
      );
      const endpoint = await ElMessageBox.prompt(
        'Endpoint（不适用可留空）',
        '物理位置'
      );
      const domain = await ElMessageBox.prompt(
        '访问域名（不适用可留空）',
        '交付地址'
      );
      await run(
        () =>
          api.createStorageSpace({
            account_id: accountId,
            space_key: key.value,
            name: name.value,
            access_type: access.value,
            bucket: isLocal ? '' : bucket.value,
            local_path: isLocal ? bucket.value : '',
            region: region.value,
            endpoint: endpoint.value,
            access_domain: domain.value,
          }),
        'Space 已创建'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function setDefaultStorageRoute(space: StorageSpace) {
    await run(
      () =>
        api.setStorageRoute({
          route_key: `default.${space.access_type}`,
          access_type: space.access_type,
          space_id: space.id,
        }),
      `默认${space.access_type === 'public' ? '公开' : '私有'}路由已更新`
    );
  }
  async function createOperator() {
    await run(async () => {
      await api.createOperator({ ...operatorForm });
      Object.assign(operatorForm, {
        email: '',
        display_name: '',
        initial_password: '',
      });
    }, '平台操作员已创建');
  }
  async function editOperator(item: Operator) {
    try {
      const name = await ElMessageBox.prompt(
        '显示名',
        `编辑 ${item.display_name}`,
        {
          inputValue: item.display_name,
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写显示名',
        }
      );
      const reason = await ElMessageBox.prompt('变更原因', '变更原因', {
        inputPattern: /\S+/,
        inputErrorMessage: '必须填写原因',
      });
      await run(
        () => api.updateOperator(item, name.value, reason.value),
        '操作员已更新'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function assignOperatorRoles(item: Operator) {
    try {
      const result = await ElMessageBox.prompt(
        `输入角色 ID，多个用逗号分隔；留空撤销全部角色。可选：${roles.value
          .map((role) => `${role.id}:${role.name}`)
          .join('，')}`,
        '分配角色',
        {
          inputValue: '',
          inputPattern: /^\s*(?:[1-9]\d*(?:\s*,\s*[1-9]\d*)*)?\s*$/,
          inputErrorMessage: '请输入正整数角色 ID，或留空撤销全部角色',
        }
      );
      const reason = await ElMessageBox.prompt('变更原因', '变更原因', {
        inputPattern: /\S+/,
        inputErrorMessage: '必须填写原因',
      });
      await run(
        () =>
          api.replaceOperatorRoles(
            item,
            result.value.trim() === ''
              ? []
              : result.value.split(',').map((id) => Number(id.trim())),
            reason.value
          ),
        '角色分配已更新'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function transitionOperator(
    item: Operator,
    action: 'activate' | 'suspend' | 'close'
  ) {
    const actionLabel = { activate: '启用', suspend: '暂停', close: '关闭' }[
      action
    ];
    try {
      const reason = await ElMessageBox.prompt(
        '变更原因',
        `${actionLabel} ${item.display_name}`,
        { inputPattern: /\S+/, inputErrorMessage: '必须填写原因' }
      );
      await run(
        () => api.transitionOperator(action, item, reason.value),
        '操作员状态已更新'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  async function createRole() {
    await run(async () => {
      await api.createRole({ ...roleForm });
      Object.assign(roleForm, { key: '', name: '', description: '' });
    }, '平台角色已创建');
  }
  async function editRole(item: PlatformRole) {
    if (
      !can('platform.role.update') ||
      roles.value.find((role) => role.id === item.id)?.status !== 'active'
    ) {
      error.value = '角色已归档、不可用或缺少编辑权限，不能编辑。';
      return;
    }
    try {
      const name = await ElMessageBox.prompt('角色名称', `编辑 ${item.key}`, {
        inputValue: item.name,
        inputPattern: /\S+/,
        inputErrorMessage: '必须填写名称',
      });
      const description = await ElMessageBox.prompt('说明', '角色说明', {
        inputValue: item.description || '',
      });
      const reason = await ElMessageBox.prompt('变更原因', '变更原因', {
        inputPattern: /\S+/,
        inputErrorMessage: '必须填写原因',
      });
      if (
        !can('platform.role.update') ||
        roles.value.find((role) => role.id === item.id)?.status !== 'active'
      ) {
        error.value = '角色状态或编辑权限已变化，请刷新后重试。';
        return;
      }
      await run(
        () => api.updateRole(item, name.value, description.value, reason.value),
        '平台角色已更新'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  function editPermissions(item: PlatformRole) {
    if (
      !can('platform.permission.read') ||
      !can('platform.role.permission.assign') ||
      roles.value.find((role) => role.id === item.id)?.status !== 'active'
    ) {
      error.value = '角色已归档、不可用或缺少权限，仅可查看，不能编辑权限。';
      return;
    }
    permissionRole.value = item;
    permissionSelection.value = [...item.permission_keys];
  }
  async function savePermissions() {
    if (
      !canEditPermissions.value ||
      permissionSubmitting.value ||
      !permissionRole.value
    )
      return;
    const role = permissionRole.value;
    const selection = [...permissionSelection.value];
    permissionSubmitting.value = true;
    try {
      const reason = await ElMessageBox.prompt(
        '变更原因',
        `保存 ${role.name} 的权限`,
        { inputPattern: /\S+/, inputErrorMessage: '必须填写原因' }
      );
      if (!canEditPermissions.value || permissionRole.value !== role) {
        error.value =
          permissionEditingBlockedReason.value ||
          '角色或页面状态已变化，请重新选择角色后再保存。';
        return;
      }
      await run(
        () => api.replaceRolePermissions(role, selection, reason.value),
        '角色权限已更新'
      );
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    } finally {
      permissionSubmitting.value = false;
    }
  }
  async function archiveRole(item: PlatformRole) {
    try {
      await ElMessageBox.confirm(`归档 ${item.name}？`, '确认归档', {
        type: 'warning',
      });
      const reason = await ElMessageBox.prompt('变更原因', '变更原因', {
        inputPattern: /\S+/,
        inputErrorMessage: '必须填写原因',
      });
      await run(() => api.archiveRole(item, reason.value), '平台角色已归档');
    } catch (cause) {
      if (cause !== 'cancel' && cause !== 'close') error.value = message(cause);
    }
  }
  onMounted(async () => {
    navigationViewport.addEventListener(
      'change',
      handleNavigationViewportChange
    );
    try {
      if (invitationToken.value) await inspectInvitation();
      else if (authenticated.value) {
        await loadSessionInfo();
        await loadView();
      }
    } catch (cause) {
      error.value = message(cause);
    }
  });
  onUnmounted(() => {
    navigationViewport.removeEventListener(
      'change',
      handleNavigationViewportChange
    );
    stopPlatformSessionSync();
    opsRuntime.dispose();
  });
</script>
