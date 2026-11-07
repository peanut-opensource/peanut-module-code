<template>
  <main class="workspace">
    <header
      ><div
        ><p class="eyebrow">AI ASSISTANT · 人工制作与采纳</p
        ><h1>{{ names[kind] }}</h1
        ><p
          >先配置机构服务和适用模型，再用固定来源制作候选。技术结果、人工采纳与业务审批分别记录。</p
        ></div
      ><div
        ><el-button :loading="loading" @click="refresh">刷新</el-button
        ><el-button v-if="canCreate" type="primary" @click="openForm()"
          >新建{{ names[kind] }}</el-button
        ></div
      ></header
    >
    <nav
      ><router-link
        v-for="(name, key) in names"
        :key="key"
        :to="'/ai-assistant/' + key"
        >{{ name }}</router-link
      ></nav
    >
    <el-alert
      v-if="overview?.execution.state === 'blocked'"
      type="warning"
      :closable="false"
      show-icon
      :title="overview.execution.reason"
    />
    <section v-if="permissions.configure" class="setup-guide">
      <strong>本模块设置顺序</strong>
      <p
        >连接保存后单独写入凭据并启用；分别准备模型、模板和路由，再准备制作任务。配置启用不代表提供方已连通或来源授权已接入。</p
      >
      <ol>
        <li
          ><router-link to="/ai-assistant/connection"
            >机构连接与凭据</router-link
          ></li
        >
        <li
          ><router-link to="/ai-assistant/model"
            >模型标识与限制</router-link
          ></li
        >
        <li
          ><router-link to="/ai-assistant/template"
            >制作模板与复核责任</router-link
          ></li
        >
        <li
          ><router-link to="/ai-assistant/route"
            >能力与适用模块路由</router-link
          ></li
        >
        <li
          ><router-link to="/ai-assistant/task"
            >制作任务与人工候选</router-link
          ></li
        >
      </ol>
    </section>
    <el-alert v-if="error" type="error" :closable="false" :title="error" />
    <section
      v-if="pendingRequests.length || pendingError"
      class="original-requests"
    >
      <h2>原请求与恢复</h2>
      <el-alert
        type="info"
        :closable="false"
        title="原输入按服务端确认的租户与实际成员保存；同一成员重新登录后可重新核权续办。关闭窗口不撤销业务，未知仍保留原UUID。旧SESSION桶保留，凭据不明文落盘。"
      />
      <el-alert
        v-if="pendingError"
        type="error"
        :closable="false"
        :title="pendingError"
      />
      <el-table :data="pendingRequests">
        <el-table-column label="原请求" min-width="250"
          ><template #default="{ row }"
            ><code>{{ row.id }}</code
            ><p
              >{{ row.endpoint }} · {{ row.kind }} · 对象
              {{ row.payload.id || '新建' }}</p
            ><p
              >原输入摘要 {{ row.digest.slice(0, 16) }} ·
              {{ new Date(row.createdAt).toLocaleString() }}</p
            ><el-alert
              v-if="pendingRequests.filter((r) => r.id === row.id).length > 1"
              type="warning"
              :closable="false"
              title="同UUID存在多个未确认原请求版本：全部保留供逐项查看，不能猜选、恢复或清除。" /></template
        ></el-table-column>
        <el-table-column label="恢复状态" min-width="210"
          ><template #default="{ row }"
            >{{ pendingLabels[row.phase]
            }}<p
              >发送尝试 {{ row.attempts }}；不代表服务方调用或计费</p
            ></template
          ></el-table-column
        >
        <el-table-column label="原操作" min-width="260"
          ><template #default="{ row }">
            <el-button
              :loading="pendingRunning[row.id]"
              @click="checkOriginal(row)"
              >仅核对回执</el-button
            >
            <el-button
              :disabled="pendingRunning[row.id]"
              @click="openRecovery(row)"
              >查看原输入并恢复</el-button
            >
            <el-button
              v-if="pendingRunning[row.id]"
              :disabled="pendingStopping[row.id]"
              @click="stopWaiting(row)"
              >{{
                pendingStopping[row.id] ? '正在停止等待' : '停止本次等待'
              }}</el-button
            >
            <el-button
              v-if="
                row.phase === 'rejected' &&
                row.kind === kind &&
                pendingRequests.filter((r) => r.id === row.id).length === 1
              "
              :disabled="pendingRunning[row.id]"
              @click="releaseOriginal(row)"
              >核实拒绝后释放并重编</el-button
            >
            <router-link
              v-if="row.kind !== kind"
              :to="'/ai-assistant/' + row.kind"
              >进入原业务页面</router-link
            >
          </template></el-table-column
        >
      </el-table>
    </section>
    <section class="filters"
      ><el-input
        v-model="search"
        placeholder="业务名称"
        clearable
        @keyup.enter="searchNow"
      /><el-select v-model="capability" clearable placeholder="制作能力"
        ><el-option
          v-for="c in capabilities"
          :key="c"
          :value="c"
          :label="capabilityNames[c]" /></el-select
      ><el-input
        v-model="statusFilter"
        placeholder="状态，可留空"
        clearable
      /><el-button @click="searchNow">查询</el-button></section
    >
    <el-empty
      v-if="!loading && !rows.length"
      :description="
        kind === 'task'
          ? '当前范围没有真实任务。请先建立并分别启用连接、模型、模板和路由。'
          : '当前范围没有记录。目录登记不代表已有账号、额度或模型开通。'
      "
    />
    <el-table v-else v-loading="loading" :data="rows" @row-click="openDetail"
      ><el-table-column prop="id" label="编号" width="85" /><el-table-column
        prop="title"
        label="业务名称"
        min-width="200"
      /><el-table-column label="状态" width="190"
        ><template #default="{ row }"
          ><el-tag>{{ stateNames[row.status] || row.status }}</el-tag></template
        ></el-table-column
      ><el-table-column
        prop="version"
        label="修订"
        width="80"
      /><el-table-column
        v-if="kind === 'usage'"
        label="真实用量来源"
        min-width="260"
        ><template #default="{ row }"
          >{{ display(row.details.usage)
          }}<p>请求额度：{{ display(row.details.quota) }}</p></template
        ></el-table-column
      ><el-table-column label="办理" width="100"
        ><template #default="{ row }"
          ><el-button link type="primary" @click.stop="openDetail(row)"
            >查看</el-button
          ></template
        ></el-table-column
      ></el-table
    >
    <el-pagination
      v-model:current-page="page"
      :total="total"
      :page-size="30"
      layout="total,prev,pager,next"
      @current-change="refresh"
    />
    <el-alert
      v-if="kind === 'usage'"
      :closable="false"
      title="请求额度区分预留、确认和未知占用，单位为请求次数。token/秒数仅显示服务方实际回报；未提供保持未知，不表示零消耗。没有配置商业费率或费用承诺。"
    />
    <el-drawer
      v-model="drawer"
      :title="selected?.title || '记录详情'"
      size="min(980px,96vw)"
    >
      <el-alert
        v-if="detailError"
        type="error"
        :closable="false"
        :title="detailError"
      />
      <template v-if="selected">
        <p
          >编号 {{ selected.id }} · 修订 {{ selected.version }} ·
          {{ stateNames[selected.status] || selected.status }}</p
        >
        <div class="actions" v-if="!taskPage && permissions.configure"
          ><el-button @click="openForm(selected)">修订配置</el-button
          ><el-button @click="openAction('enable')">启用</el-button
          ><el-button @click="openAction('disable')">停用</el-button
          ><el-button
            v-if="kind === 'connection'"
            @click="openAction('credential')"
            >写入/轮换凭据</el-button
          ></div
        >
        <template v-if="taskPage">
          <el-alert
            v-if="selected.details.execution_block"
            :closable="false"
            type="warning"
            :title="
              String(selected.details.execution_block) ===
              'SOURCE_AUTHORIZATION_BRIDGE_REQUIRED'
                ? '来源当前授权接口缺失，任务未向提供方发送。请联系系统维护方完成接口接入后再办理。'
                : String(selected.details.execution_block)
            "
          />
          <p v-if="selected.native_task"
            >实际执行任务：{{ selected.native_task.status }} · 尝试
            {{ selected.native_task.attempt_count }} · 修订
            {{ selected.native_task.revision }}</p
          >
          <div class="actions"
            ><el-button v-if="permissions.task_read" @click="refreshTask"
              >刷新执行实况</el-button
            ><el-button
              v-if="
                permissions.use &&
                permissions.task_manage &&
                api.canCancelTask(selected)
              "
              @click="openAction('cancel')"
              >{{
                api.canCancelTaskPreparation(selected)
                  ? '取消准备并释放额度'
                  : '取消本系统任务'
              }}</el-button
            ><el-button
              v-if="
                permissions.use &&
                permissions.task_manage &&
                selected.native_task &&
                ['dead', 'cancelled'].includes(selected.native_task.status) &&
                !selected.details.collection_claim
              "
              @click="openAction('retry')"
              >本人重新授权恢复</el-button
            ><el-button
              v-if="permissions.use && selected.details.stage === 'sending'"
              @click="openAction('observeUnknown')"
              >核对中断并登记未知</el-button
            ><el-button
              v-if="
                permissions.use &&
                selected.details.stage === 'provider_pending' &&
                selected.native_task?.status === 'succeeded'
              "
              @click="openAction('poll')"
              >按真实服务方ID核对结果</el-button
            ><el-button
              v-if="
                permissions.use &&
                selected.native_task &&
                !['queued', 'running'].includes(selected.native_task.status) &&
                !selected.details.fixed_result &&
                !selected.details.manual_request_id &&
                !selected.details.candidate &&
                !selected.details.collection_claim &&
                !['candidate', 'collected'].includes(
                  String(selected.details.stage)
                )
              "
              @click="openAction('manual')"
              >人工提供候选（非服务方回执）</el-button
            ><el-button
              v-if="
                permissions.use &&
                selected.details.stage === 'candidate' &&
                !selected.details.collection_claim &&
                selected.native_task?.status === 'succeeded'
              "
              type="primary"
              @click="openAction('collect')"
              >当前授权领取候选</el-button
            ><el-button
              v-if="selected.details.collection_state === 'collected'"
              @click="compare"
              >比较来源与候选</el-button
            ><el-button
              v-if="
                permissions.review &&
                ['ready_for_review', 'returned'].includes(selected.status)
              "
              type="primary"
              @click="openAction('accept')"
              >人工接受候选</el-button
            ><el-button
              v-if="
                permissions.review && selected.status === 'ready_for_review'
              "
              @click="openAction('return')"
              >退回候选</el-button
            ><el-button
              v-if="permissions.review && selected.status === 'accepted'"
              @click="openAction('withdraw')"
              >撤回采纳</el-button
            ><el-button
              v-if="
                permissions.review &&
                ['accepted', 'acceptance_withdrawn'].includes(selected.status)
              "
              @click="openAction('rework')"
              >撤回旧采纳并登记重做</el-button
            ><el-button
              v-if="
                permissions.review &&
                [
                  'accepted',
                  'acceptance_withdrawn',
                  'rework_required',
                ].includes(selected.status)
              "
              @click="openAction('supersede')"
              >由另一已采纳任务显式替代</el-button
            ><el-button
              v-if="
                kind === 'task' &&
                permissions.use &&
                selected.status === 'rework_required'
              "
              @click="beginReworkTask"
              >为本次重做建立新任务</el-button
            ></div
          >
          <p
            >取消只证明本系统停止继续处理；未获服务方明确确认，不代表远端取消或退款。未知POST不自动重发。</p
          >
          <section v-if="comparison" class="comparison"
            ><div
              ><h3>原固定来源</h3
              ><pre>{{
                comparison.source_text || display(comparison.source)
              }}</pre></div
            ><div
              ><h3>已领取候选</h3
              ><pre>{{
                comparison.candidate_text || display(comparison.fixed_result)
              }}</pre
              ><el-button
                v-if="comparison.fixed_result.file_key"
                @click="preview('preview')"
                >预览真实文件</el-button
              ><el-button
                v-if="comparison.fixed_result.file_key"
                @click="preview('download_original')"
                >下载</el-button
              ></div
            ></section
          >
        </template>
        <el-descriptions :column="1" border
          ><el-descriptions-item
            v-for="(value, key) in selected.details"
            :key="key"
            :label="labels[key] || key"
          >
            <pre>{{ display(value) }}</pre>
          </el-descriptions-item></el-descriptions
        >
        <h3>固定历史</h3
        ><el-timeline
          ><el-timeline-item
            v-for="event in selected.history"
            :key="event.id"
            :timestamp="new Date(event.created_at * 1000).toISOString()"
            ><strong>{{ event.title }}</strong
            ><pre>{{ display(event.details) }}</pre>
          </el-timeline-item></el-timeline
        ><el-button
          v-if="selected.history_next_before_id"
          @click="older"
          :loading="olderBusy"
          >继续读取较早记录</el-button
        >
      </template></el-drawer
    >
    <el-dialog
      v-model="formVisible"
      destroy-on-close
      :title="(editing ? '修订' : '新建') + names[kind]"
      width="min(780px,96vw)"
      :close-on-click-modal="false"
      ><el-alert
        v-if="formError"
        type="error"
        :closable="false"
        :title="formError"
      /><el-form label-position="top" :disabled="!!formFrozen" @submit.prevent>
        <el-form-item label="业务名称" required
          ><el-input v-model="form.title" maxlength="200"
        /></el-form-item>
        <template v-if="kind === 'connection'">
          <el-form-item label="服务商协议" required
            ><el-select v-model="form.protocol"
              ><el-option
                v-for="p in protocols"
                :key="p"
                :value="p"
                :label="protocolNames[p]" /></el-select
          ></el-form-item>
          <el-form-item label="服务商 HTTPS 地址" required
            ><el-input
              v-model="form.origin"
              placeholder="https://机构已批准的服务域名（不含路径/查询/密钥）"
          /></el-form-item>
          <p v-if="editing"
            >更换服务商地址、API 前缀或协议后，请重新设置密钥并启用连接。</p
          >
          <el-form-item label="API 路径前缀" required
            ><el-input
              v-model="form.api_prefix"
              placeholder="/v1 或官方Gemini /v1beta"
          /></el-form-item>
          <el-form-item label="服务责任" required
            ><el-select v-model="form.responsibility"
              ><el-option value="self_deployed" label="机构自部署" /><el-option
                value="institution_provider"
                label="机构自有供应方账号" /><el-option
                value="platform_service"
                label="平台服务，开通与费用另核" /></el-select
          ></el-form-item>
          <el-form-item label="账号/地域/数据处理责任说明" required
            ><el-input v-model="form.responsibility_note" type="textarea"
          /></el-form-item>
          <el-form-item label="每日请求次数上限" required
            ><el-input-number
              v-model="numbers.daily_request_limit"
              :min="1"
              :max="100000"
              :precision="0"
          /></el-form-item>
          <p
            >先保存连接，再单独加密写入凭据。没有有效密钥环时不会保存明文；没有“假测试连接成功”。</p
          >
        </template>
        <template v-if="kind === 'model'">
          <el-form-item label="已启用连接" required
            ><EnabledConfigSelect
              v-model="numbers.connection_id"
              kind="connection"
          /></el-form-item>
          <el-form-item label="提供方实际模型标识" required
            ><el-input
              v-model="form.provider_model_id"
              placeholder="由获授权管理员从实际账号目录核实"
          /></el-form-item>
          <el-form-item label="模型能力" required
            ><el-select v-model="form.capability"
              ><el-option
                v-for="c in capabilities"
                :key="c"
                :value="c"
                :label="capabilityNames[c]" /></el-select
          ></el-form-item>
          <el-form-item label="机构输入文字上限" required
            ><el-input-number
              v-model="numbers.input_limit_chars"
              :min="1"
              :max="form.capability === 'speech' ? 4096 : 32000"
              :precision="0"
          /></el-form-item>
          <el-form-item label="文本最大输出token（仅文本请求使用）" required
            ><el-input-number
              v-model="numbers.max_output_tokens"
              :min="1"
              :max="16000"
              :precision="0"
          /></el-form-item>
          <el-form-item
            v-if="form.capability === 'speech'"
            label="已核配音声线"
            required
            ><el-select v-model="form.voice"
              ><el-option
                v-for="v in voices"
                :key="v"
                :value="v"
                :label="v" /></el-select
          ></el-form-item>
          <el-form-item label="模型有效截止UTC，可留空"
            ><el-input
              v-model="form.valid_until"
              placeholder="2027-01-06T00:00:00Z"
          /></el-form-item>
          <el-checkbox v-model="flags.supports_background"
            >已核该模型支持Gemini后台执行（需提供方保留请求，适用视频）</el-checkbox
          >
          <el-alert
            :closable="false"
            title="Sora Videos与Veo 3.1 Preview已停止。公开Speech旧模型已通知2027-01-06停用，系统会限制这些标识的有效期；不会选择默认可用模型。"
          />
        </template>
        <template v-if="kind === 'route'">
          <el-form-item label="制作能力" required
            ><el-select v-model="form.capability"
              ><el-option
                v-for="c in capabilities"
                :key="c"
                :value="c"
                :label="capabilityNames[c]" /></el-select
          ></el-form-item>
          <el-form-item label="已启用模型" required
            ><EnabledConfigSelect
              v-model="numbers.model_id"
              kind="model"
              :capability="form.capability" /></el-form-item
          ><el-form-item label="已启用模板" required
            ><EnabledConfigSelect
              v-model="numbers.template_id"
              kind="template"
              :capability="form.capability"
          /></el-form-item>
          <el-form-item label="适用制作模块" required
            ><el-input
              v-model.trim="form.consumer_module"
              maxlength="96"
              placeholder="模块标识（例如 peanut.article）或 general"
          /></el-form-item>
          <el-form-item label="使用范围和责任说明" required
            ><el-input v-model="form.scope_note" type="textarea"
          /></el-form-item>
        </template>
        <template v-if="kind === 'template'"
          ><el-form-item label="制作能力" required
            ><el-select v-model="form.capability"
              ><el-option
                v-for="c in capabilities"
                :key="c"
                :value="c"
                :label="capabilityNames[c]" /></el-select></el-form-item
          ><el-form-item label="制作指令" required
            ><el-input
              v-model="form.instructions"
              type="textarea"
              :rows="7"
              maxlength="16000" /></el-form-item
          ><el-form-item label="适用范围和人工复核责任" required
            ><el-input
              v-model="form.scope_note"
              type="textarea" /></el-form-item
        ></template>
        <template v-if="kind === 'task'">
          <el-form-item label="已启用能力路由" required
            ><el-select
              v-model="numbers.route_id"
              filterable
              remote
              :remote-method="searchRoutes"
              placeholder="搜索当前获准路由"
              ><el-option
                v-for="r in routes"
                :key="r.id"
                :value="r.id"
                :label="
                  r.title +
                  ' · ' +
                  capabilityNames[r.capability || ''] +
                  ' #' +
                  r.id
                " /></el-select
            ><el-button
              v-if="routes.length < routesCount"
              link
              @click="moreRoutes"
              :loading="lookupBusy"
              :disabled="lookupBusy"
              >继续读取路由</el-button
            ></el-form-item
          >
          <el-form-item label="实际来源Document编号" required
            ><el-input-number
              v-model="numbers.document_id"
              :min="1"
              :precision="0" /></el-form-item
          ><el-form-item label="已提交固定revision编号" required
            ><el-input-number
              v-model="numbers.revision_id"
              :min="1"
              :precision="0"
          /></el-form-item>
          <el-form-item label="本次制作要求，可留空"
            ><el-input
              v-model="form.instruction"
              type="textarea"
              :rows="4"
              maxlength="4000" /></el-form-item
          ><el-form-item label="用于哪个工作台" required
            ><el-input
              v-model.trim="form.consumer_module"
              maxlength="96"
              placeholder="模块标识（例如 peanut.article）或 general"
          /></el-form-item>
          <p v-if="numbers.rework_of_task_id"
            >重做依据：原任务 #{{ numbers.rework_of_task_id }} / 版本
            {{
              numbers.rework_of_task_version
            }}。本次建立独立新任务，保留旧固定产物。可明确选择同一来源文档的新修订；不会自动替代旧任务或批准制作。</p
          >
          <details
            ><summary>原请求结果未知，明确另发</summary
            ><el-form-item label="原未知任务编号"
              ><el-input-number
                v-model="numbers.repeat_of_task_id"
                :min="0"
                :precision="0" /></el-form-item
            ><el-checkbox v-model="flags.confirm_possible_duplicate_cost"
              >我确认这是另一个新请求，原请求可能已消耗额度，存在重复消耗风险</el-checkbox
            ></details
          >
        </template> </el-form
      ><template #footer
        ><el-button @click="formVisible = false">关闭</el-button
        ><el-button :loading="busy" type="primary" @click="submitForm">{{
          kind === 'task' ? '固定来源并提交任务' : '保存真实配置'
        }}</el-button></template
      ></el-dialog
    >
    <el-dialog
      v-model="actionVisible"
      destroy-on-close
      :title="actionNames[action] || action"
      width="min(680px,96vw)"
      :close-on-click-modal="false"
      ><el-alert
        v-if="formError"
        type="error"
        :closable="false"
        :title="formError"
      /><p v-if="actionIntent"
        >固定办理对象 {{ actionIntent.kind }} #{{ actionIntent.row.id }} · 修订
        {{ actionIntent.row.version }}</p
      ><el-alert
        v-if="actionFrozen"
        type="info"
        :closable="false"
        :title="'原请求已固定：' + actionFrozen.id"
      /><template v-if="action === 'credential'"
        ><el-input
          v-model="credential"
          type="password"
          show-password
          autocomplete="new-password"
          placeholder="只写入，不回显"
        /><p
          >凭据以原生认证加密绑定租户和当前连接。请求后输入框清空，请刷新核实已配置状态。</p
        ></template
      ><template v-else
        ><el-form :disabled="!!actionFrozen"
          ><template v-if="action === 'manual'"
            ><el-alert
              :closable="false"
              title="这是人工录入/上传，未验证提供方生成。仅以本地任务验证真实字节，不调用AI服务或把原未知调用改记成功。" />
            <p>本任务接收{{ manualLabels[manualType] }}。</p>
            <el-input
              v-if="['text', 'transcript'].includes(manualType)"
              v-model="manualText"
              type="textarea"
              :rows="8"
              placeholder="实际人工提供候选正文" /><div v-else
              ><el-input
                v-model="manualFile"
                readonly
                placeholder="选择当前真实File" /><FilePicker
                :type="
                  manualType === 'image' ? 10 : manualType === 'video' ? 20 : 30
                "
                :limit="1"
                v-on="manualFileListeners()"
                :key="actionIntent?.requestId" /></div></template
          ><template v-if="action === 'supersede'"
            ><el-form-item label="另一已采纳任务编号"
              ><el-input-number
                v-model="replacementTaskId"
                :min="1"
                :precision="0"
                @change="replacementTask = null" /></el-form-item
            ><el-button :loading="replacementBusy" @click="loadReplacementTask"
              >读取并核对实际候选</el-button
            ><div v-if="replacementTask"
              ><p
                >{{ replacementTask.title }} · #{{ replacementTask.id }} /
                固定任务版本 {{ replacementTask.version }}</p
              ><pre>{{ display(replacementTask.details) }}</pre
              ><p
                >将使旧候选的业务采纳失效；新候选仍须在制作任务中重新登记和验收。</p
              ></div
            ></template
          ><p v-if="['withdraw', 'rework'].includes(action)"
            >撤回后，依赖该采纳的制作产物不得继续使用。原文件与历史保留；重做须另建新任务，不会重新发送旧请求。</p
          ><el-input
            v-model="reason"
            type="textarea"
            :rows="4"
            placeholder="办理依据 / 人工决定理由"
          /><p v-if="action === 'retry'"
            >恢复先重核当前身份、对象与固定配置。未知POST没有已确认本地回执时禁止重发；来源接口缺失仍会停止。</p
          ><p v-if="action === 'collect'"
            >领取将重核当前来源、配置和真实产物字节，不接受客户端路径。File未知写入不自动重传；补充同字节File须在上方原请求恢复面板办理。</p
          ></el-form
        ></template
      ><template #footer
        ><el-button @click="actionVisible = false">关闭</el-button
        ><el-button :loading="busy" type="primary" @click="submitAction"
          >确认办理</el-button
        ></template
      ></el-dialog
    >
    <el-dialog
      v-model="recoveryVisible"
      :title="'恢复原请求 ' + (recoveryTarget?.id || '')"
      width="min(850px,96vw)"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <template v-if="recoveryTarget">
        <el-alert
          type="warning"
          :closable="false"
          title="只能沿下面已保存的原输入和原UUID恢复。未找到回执不是未执行证明，5xx/无完整信封/准备中不会释放请求。"
        />
        <el-alert
          v-if="pendingError"
          type="error"
          :closable="false"
          :title="pendingError"
        />
        <p
          >{{ pendingLabels[recoveryTarget.phase] }} ·
          {{ recoveryTarget.endpoint }} · 对象
          {{ recoveryTarget.payload.id || '新建' }}</p
        >
        <details
          ><summary>不可变原输入（凭据已排除）</summary
          ><pre>{{ display(recoveryTarget.payload) }}</pre>
        </details>
        <p
          >完整原输入摘要：<code>{{ recoveryTarget.digest }}</code></p
        >
        <el-form label-position="top">
          <el-form-item
            v-if="recoveryTarget.endpoint === 'saveCredential'"
            label="需要重试时重新输入原同一凭据；仅核对回执无需输入"
          >
            <el-input
              v-model="recoverySecret"
              type="password"
              show-password
              autocomplete="off"
              placeholder="只核相等性，不保存明文，不改为新密钥"
            />
          </el-form-item>
          <el-form-item
            v-if="recoveryTarget.endpoint === 'collectCandidate'"
            label="仅原领取已有锚点且File写入未知：补充当前可读的同字节File，可留空"
          >
            <el-input v-model="recoveryFile" readonly />
            <FilePicker
              :key="'recovery-doc:' + recoveryTarget.id + ':' + recoveryEpoch"
              :type="30"
              :limit="1"
              button-text="文档/音频"
              v-on="recoveryFileListeners()"
            />
            <FilePicker
              :key="'recovery-image:' + recoveryTarget.id + ':' + recoveryEpoch"
              :type="10"
              :limit="1"
              button-text="图片"
              v-on="recoveryFileListeners()"
            />
            <FilePicker
              :key="'recovery-video:' + recoveryTarget.id + ':' + recoveryEpoch"
              :type="20"
              :limit="1"
              button-text="视频"
              v-on="recoveryFileListeners()"
            />
            <el-button @click="recoveryFile = ''">清空本次补充</el-button>
          </el-form-item>
        </el-form>
      </template>
      <template #footer
        ><el-button @click="recoveryVisible = false"
          >关闭（不撤销业务）</el-button
        ><el-button
          v-if="recoveryTarget && pendingRunning[recoveryTarget.id]"
          :disabled="pendingStopping[recoveryTarget.id]"
          @click="stopWaiting(recoveryTarget)"
          >{{
            pendingStopping[recoveryTarget.id] ? '正在停止等待' : '停止本次等待'
          }}</el-button
        ><el-button
          v-if="recoveryTarget"
          :loading="pendingRunning[recoveryTarget.id]"
          @click="checkOriginal(recoveryTarget)"
          >仅核对</el-button
        ><el-button
          v-if="recoveryTarget"
          type="primary"
          :loading="pendingRunning[recoveryTarget.id]"
          @click="checkOriginal(recoveryTarget, true)"
          >以原UUID和原输入恢复</el-button
        ></template
      >
    </el-dialog>
  </main>
</template>
<script setup lang="ts">
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
  import {
    RequestLane,
    type PendingRequest,
    type MutationEndpoint,
  } from './request-recovery';
  import { useOriginalRequests } from './use-original-requests';
  import { useRoute, useRouter } from 'vue-router';
  import { registerTenantDisposer } from '@peanut-admin/vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import * as api from './api';
  import FilePicker from '@/components/file-picker/index.vue';
  import EnabledConfigSelect from './EnabledConfigSelect.vue';
  import type { FileRecord } from '@/modules/peanut-file/api';
  import type { BusinessRecord, Comparison, Lookup, Overview } from './api';
  const route = useRoute(),
    router = useRouter();
  const names: Record<string, string> = {
    connection: 'AI连接',
    model: '模型目录',
    route: '能力路由',
    template: '制作模板',
    task: 'AI制作任务',
    usage: '用量台账',
  };
  const capabilityNames: Record<string, string> = {
    text: '文本制作',
    image: '配图生成',
    speech: '配音生成',
    transcription: '音视频转写',
    video: '视频生成',
  };
  const protocolNames: Record<string, string> = {
    openai_text: 'OpenAI 兼容 · Chat Completions',
    anthropic_messages: 'Anthropic · Messages',
    openai_image: 'OpenAI Images（base64）',
    openai_speech: 'OpenAI Speech（WAV）',
    openai_transcription: 'OpenAI Transcription（实际文件）',
    gemini_video: 'Gemini Interactions视频（inline）',
  };
  const capabilities = ['text', 'image', 'speech', 'transcription', 'video'],
    protocols = Object.keys(protocolNames),
    voices = [
      'alloy',
      'ash',
      'ballad',
      'coral',
      'echo',
      'fable',
      'onyx',
      'nova',
      'sage',
      'shimmer',
      'verse',
      'marin',
      'cedar',
    ];
  const stateNames: Record<string, string> = {
    disabled: '停用',
    enabled: '启用',
    preparing: '准备固定来源',
    queued: '待执行',
    sending: '已登记发送意图',
    unknown: '结果未知，禁止盲重发',
    blocked_source_authorization: '未发送：来源授权待接入',
    provider_pending: '服务方异步处理中',
    awaiting_collection: '私有候选待领取',
    ready_for_review: '已领取，待人工采纳',
    accepted: '人工已接受候选',
    acceptance_withdrawn: '采纳已撤回，旧业务绑定失效',
    rework_required: '旧采纳已撤回，等待新任务重做',
    superseded: '已被显式替代，旧采纳失效',
    returned: '人工退回候选',
    failed: '执行失败 / 无可用候选',
    cancelled: '本系统已取消',
  };
  const labels: Record<string, string> = {
    protocol: '适配协议',
    origin: '机构服务',
    api_prefix: 'API前缀',
    responsibility: '责任类型',
    responsibility_note: '责任说明',
    credential_configured: '凭据已配置',
    provider_model_id: '提供方模型',
    capability: '制作能力',
    input_limit_chars: '输入上限',
    max_output_tokens: '最大输出token',
    valid_until: '有效截止',
    supports_background: '后台执行已核',
    consumer_module: '适用模块',
    instructions: '制作指令',
    scope_note: '使用范围',
    source: '固定来源',
    configuration: '固定配置版本',
    quota: '请求额度',
    usage: '服务方实际用量',
    stage: '业务阶段',
    execution_block: '当前阻断',
    candidate: '产物实际校验',
    fixed_result: '已领取固定结果',
    decision: '人工决定',
    acceptance_lifecycle: '采纳撤回 / 重做 / 替代事实',
    rework_of: '本次新任务对应的原重做要求',
    result_source: '结果来源',
  };
  const actionNames: Record<string, string> = {
    manual: '人工提供候选（未验证服务方）',
    enable: '启用配置',
    disable: '停用配置',
    credential: '写入 / 轮换凭据',
    cancel: '取消本系统任务',
    retry: '原提交成员恢复',
    poll: 'GET核对服务方结果',
    observeUnknown: '登记中断后的未知结果',
    collect: '当前授权领取候选',
    accept: '人工接受候选',
    return: '退回候选',
    withdraw: '撤回已采纳候选',
    rework: '撤回旧采纳并登记重做',
    supersede: '显式替代旧采纳',
  };
  const kind = computed(() => String(route.meta.kind || 'task')),
    taskPage = computed(() => ['task', 'usage'].includes(kind.value));
  const rows = ref<BusinessRecord[]>([]),
    selected = ref<BusinessRecord | null>(null),
    overview = ref<Overview | null>(null),
    comparison = ref<Comparison | null>(null);
  const permissions = computed(() => overview.value?.permissions || {}),
    canCreate = computed(() =>
      kind.value === 'task'
        ? permissions.value.use
        : kind.value !== 'usage' && permissions.value.configure
    );
  const loading = ref(false),
    busy = ref(false),
    drawer = ref(false),
    formVisible = ref(false),
    actionVisible = ref(false),
    editing = ref(false);
  const error = ref(''),
    detailError = ref(''),
    formError = ref(''),
    search = ref(''),
    statusFilter = ref(''),
    capability = ref(''),
    page = ref(1),
    total = ref(0),
    action = ref(''),
    reason = ref(''),
    credential = ref(''),
    recoveryKey = ref('');
  const form = reactive<Record<string, string>>({}),
    numbers = reactive<Record<string, number | undefined>>({}),
    flags = reactive<Record<string, boolean>>({});
  const manualLabels: Record<string, string> = {
    text: '人工文字候选',
    transcript: '人工转写正文',
    image: 'PNG图片',
    audio: 'WAV音频',
    video: 'MP4视频',
  };
  const manualType = ref(''),
    manualText = ref(''),
    manualFile = ref('');
  const replacementTaskId = ref<number>(),
    replacementTask = ref<BusinessRecord | null>(null),
    replacementBusy = ref(false);
  function manualFileListeners() {
    const intent = actionIntent.value;
    return {
      select: (_urls: unknown, files: FileRecord[]) => {
        if (
          intent &&
          actionIntent.value === intent &&
          intent.modal === modalEpoch &&
          intent.generation === sessionGeneration() &&
          !actionFrozen.value
        )
          pickManual(files);
      },
    };
  }
  function recoveryFileListeners() {
    const target = recoveryTarget.value,
      modal = recoveryEpoch,
      generation = recoveryGeneration;
    return {
      select: (_urls: unknown, files: FileRecord[]) => {
        if (
          target &&
          recoveryTarget.value === target &&
          modal === recoveryEpoch &&
          generation === sessionGeneration() &&
          recoveryVisible.value &&
          files[0]?.file_key
        )
          recoveryFile.value = files[0].file_key;
      },
    };
  }
  function pickManual(files: FileRecord[]) {
    const key = files[0]?.file_key;
    if (!key) {
      formError.value = '所选文件缺少真实稳定key';
      return;
    }
    manualFile.value = key;
  }
  const routes = ref<Lookup[]>([]),
    routesCount = ref(0);
  let routesPage = 1,
    routesSearch = '';
  const sessionGeneration = () => getSessionSnapshot().generation;
  const originals = useOriginalRequests();
  const pendingRequests = originals.requests,
    pendingError = originals.recoveryError,
    pendingRunning = originals.running,
    pendingStopping = originals.stopping;
  const listLane = new RequestLane(sessionGeneration),
    detailLane = new RequestLane(sessionGeneration),
    statusLane = new RequestLane(sessionGeneration),
    historyLane = new RequestLane(sessionGeneration),
    compareLane = new RequestLane(sessionGeneration),
    lookupLane = new RequestLane(sessionGeneration),
    previewLane = new RequestLane(sessionGeneration);
  let viewEpoch = 0,
    modalEpoch = 0,
    detailEpoch = 0,
    recoveryEpoch = 0;
  let overviewGeneration: number | null = null,
    recoveryGeneration: number | null = null;
  let timer: ReturnType<typeof setTimeout> | undefined;
  type FormIntent = {
    kind: string;
    id?: number;
    version?: number;
    requestId: string;
    view: number;
    modal: number;
    generation: number;
  };
  type ActionIntent = {
    kind: string;
    row: BusinessRecord;
    name: string;
    requestId: string;
    view: number;
    modal: number;
    generation: number;
  };
  const formIntent = ref<FormIntent | null>(null),
    actionIntent = ref<ActionIntent | null>(null);
  const formFrozen = ref<PendingRequest | null>(null),
    actionFrozen = ref<PendingRequest | null>(null);
  const recoveryVisible = ref(false),
    recoveryTarget = ref<PendingRequest | null>(null),
    recoverySecret = ref(''),
    recoveryFile = ref('');
  const lookupBusy = ref(false),
    olderBusy = ref(false);
  const pendingLabels: Record<string, string> = {
    prepared: '原输入已保存，待核对',
    unknown: '结果未知，保留原请求',
    preparing: '服务器准备中，沿原请求恢复',
    rejected: '已获完整拒绝及当前无锚点回执',
  };
  const display = (v: unknown) =>
    typeof v === 'string' ? v : JSON.stringify(v, null, 2);
  function currentOwner() {
    if (
      overviewGeneration !== null &&
      overviewGeneration !== sessionGeneration()
    ) {
      clear();
      error.value = '登录身份已切换，请重新加载当前成员后办理。';
      return false;
    }
    if (!overview.value || overviewGeneration === null) {
      error.value = '请先加载当前实际成员，再办理或恢复原请求。';
      return false;
    }
    return true;
  }
  function clear() {
    viewEpoch++;
    modalEpoch++;
    detailEpoch++;
    recoveryEpoch++;
    for (const lane of [
      listLane,
      detailLane,
      statusLane,
      historyLane,
      compareLane,
      lookupLane,
      previewLane,
    ])
      lane.cancel();
    if (timer) clearTimeout(timer);
    timer = undefined;
    originals.clearView();
    rows.value = [];
    selected.value = null;
    overview.value = null;
    overviewGeneration = null;
    recoveryGeneration = null;
    comparison.value = null;
    replacementTask.value = null;
    replacementTaskId.value = undefined;
    replacementBusy.value = false;
    routes.value = [];
    routesCount.value = 0;
    routesSearch = '';
    routesPage = 1;
    for (const key of Object.keys(form)) delete form[key];
    for (const key of Object.keys(numbers)) delete numbers[key];
    for (const key of Object.keys(flags)) delete flags[key];
    drawer.value = false;
    formVisible.value = false;
    actionVisible.value = false;
    credential.value = '';
    manualText.value = '';
    manualFile.value = '';
    recoverySecret.value = '';
    recoveryFile.value = '';
    recoveryVisible.value = false;
    recoveryTarget.value = null;
    formFrozen.value = null;
    actionFrozen.value = null;
    formIntent.value = null;
    actionIntent.value = null;
    busy.value = false;
    loading.value = false;
    lookupBusy.value = false;
    olderBusy.value = false;
    error.value = '';
    detailError.value = '';
    formError.value = '';
  }
  const unregister = registerTenantDisposer(
    'custom.ai-assistant:' + crypto.randomUUID(),
    clear
  );
  onBeforeUnmount(() => {
    clear();
    unregister();
  });
  async function refresh() {
    if (
      overviewGeneration !== null &&
      overviewGeneration !== sessionGeneration()
    )
      clear();
    const ticket = listLane.start(),
      view = viewEpoch,
      currentKind = kind.value;
    loading.value = true;
    error.value = '';
    try {
      const [o, p] = await Promise.all([
        api.overview(ticket.signal),
        api.listing(
          {
            kind: currentKind,
            page: page.value,
            limit: 30,
            search: search.value,
            status: statusFilter.value,
            capability: capability.value,
          },
          ticket.signal
        ),
      ]);
      if (!listLane.current(ticket) || view !== viewEpoch) return;
      overview.value = o;
      overviewGeneration = ticket.generation;
      rows.value = p.list;
      total.value = p.count;
      await originals.bind(o, ticket.generation);
    } catch (e) {
      if (listLane.current(ticket) && view === viewEpoch)
        error.value =
          e instanceof Error ? e.message : '加载失败；原请求没有被撤销';
    } finally {
      if (listLane.current(ticket) && view === viewEpoch) loading.value = false;
    }
  }
  async function searchNow() {
    await router.replace({
      query: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        capability: capability.value || undefined,
      },
    });
    page.value = 1;
  }
  async function openDetail(row: BusinessRecord) {
    if (!currentOwner()) return;
    modalEpoch++;
    recoveryEpoch++;
    formVisible.value = false;
    actionVisible.value = false;
    recoveryVisible.value = false;
    credential.value = '';
    recoverySecret.value = '';
    busy.value = false;
    const ticket = detailLane.start(),
      view = viewEpoch,
      detail = ++detailEpoch,
      currentKind = taskPage.value ? 'task' : kind.value;
    statusLane.cancel();
    historyLane.cancel();
    compareLane.cancel();
    previewLane.cancel();
    if (timer) clearTimeout(timer);
    timer = undefined;
    drawer.value = true;
    selected.value = null;
    comparison.value = null;
    detailError.value = '';
    olderBusy.value = false;
    try {
      const d = await api.detail(currentKind, row.id, ticket.signal);
      if (
        !detailLane.current(ticket) ||
        view !== viewEpoch ||
        detail !== detailEpoch
      )
        return;
      selected.value = d;
      if (currentKind === 'task' && permissions.value.task_read)
        void refreshTask();
    } catch (e) {
      if (
        detailLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch
      )
        detailError.value = e instanceof Error ? e.message : '详情读取失败';
    }
  }
  async function refreshTask() {
    if (!currentOwner()) return;
    const r = selected.value;
    if (!r) return;
    const ticket = statusLane.start(),
      view = viewEpoch,
      detail = detailEpoch,
      id = r.id;
    try {
      const d = await api.status(id, ticket.signal);
      if (
        !statusLane.current(ticket) ||
        view !== viewEpoch ||
        detail !== detailEpoch ||
        selected.value?.id !== id
      )
        return;
      selected.value = { ...selected.value, ...d };
      if (timer) clearTimeout(timer);
      if (
        drawer.value &&
        d.native_task &&
        ['queued', 'running'].includes(d.native_task.status)
      ) {
        timer = setTimeout(() => {
          if (
            statusLane.current(ticket) &&
            view === viewEpoch &&
            detail === detailEpoch
          )
            void refreshTask();
        }, 3000);
      }
    } catch (e) {
      if (
        statusLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch
      )
        detailError.value = e instanceof Error ? e.message : '任务读取失败';
    }
  }
  async function older() {
    if (!currentOwner()) return;
    const r = selected.value;
    if (!r?.history_next_before_id || olderBusy.value) return;
    const ticket = historyLane.start(),
      view = viewEpoch,
      detail = detailEpoch,
      id = r.id,
      cursor = r.history_next_before_id,
      currentKind = taskPage.value ? 'task' : kind.value;
    olderBusy.value = true;
    try {
      const p = await api.history(currentKind, id, cursor, ticket.signal);
      if (
        !historyLane.current(ticket) ||
        view !== viewEpoch ||
        detail !== detailEpoch ||
        selected.value?.id !== id
      )
        return;
      selected.value.history = [...(selected.value.history || []), ...p.list];
      selected.value.history_next_before_id = p.next_before_id;
    } catch (e) {
      if (
        historyLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch
      )
        detailError.value = e instanceof Error ? e.message : '历史读取失败';
    } finally {
      if (
        historyLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch
      )
        olderBusy.value = false;
    }
  }
  function positiveQueryId(value: unknown): number | undefined {
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value))
      return undefined;
    const id = Number(value);
    return Number.isSafeInteger(id) ? id : undefined;
  }
  function openForm(row?: BusinessRecord) {
    if (!currentOwner()) return;
    const currentKind = kind.value;
    formIntent.value = {
      kind: currentKind,
      ...(row ? { id: row.id, version: row.version } : {}),
      requestId: crypto.randomUUID(),
      view: viewEpoch,
      modal: ++modalEpoch,
      generation: sessionGeneration(),
    };
    actionVisible.value = false;
    formFrozen.value = null;
    busy.value = false;
    editing.value = !!row;
    Object.assign(form, {
      title: row?.title || '',
      protocol: 'openai_text',
      origin: '',
      api_prefix: '/v1',
      responsibility: 'institution_provider',
      responsibility_note: '',
      capability: 'text',
      provider_model_id: '',
      voice: '',
      valid_until: '',
      consumer_module: 'general',
      scope_note: '',
      instructions: '',
      instruction: '',
    });
    Object.assign(numbers, {
      daily_request_limit: 100,
      connection_id: undefined,
      input_limit_chars: 16000,
      max_output_tokens: 2000,
      model_id: undefined,
      template_id: undefined,
      route_id: undefined,
      document_id: positiveQueryId(route.query.document_id),
      revision_id: positiveQueryId(route.query.revision_id),
      repeat_of_task_id: undefined,
      rework_of_task_id: undefined,
      rework_of_task_version: undefined,
    });
    Object.assign(flags, {
      supports_background: false,
      confirm_possible_duplicate_cost: false,
    });
    if (row)
      for (const [key, value] of Object.entries(row.details)) {
        if (typeof value === 'string') form[key] = value;
        else if (typeof value === 'number') numbers[key] = value;
        else if (typeof value === 'boolean') flags[key] = value;
      }
    formError.value = '';
    formVisible.value = true;
    if (kind.value === 'task') void searchRoutes('');
  }
  async function searchRoutes(q: string) {
    if (!currentOwner()) return;
    const ticket = lookupLane.start(),
      view = viewEpoch,
      modal = modalEpoch;
    routesSearch = q;
    routesPage = 1;
    lookupBusy.value = true;
    routes.value = [];
    routesCount.value = 0;
    try {
      const p = await api.lookups('route', 'use', ticket.signal, q, 1);
      if (
        !lookupLane.current(ticket) ||
        view !== viewEpoch ||
        modal !== modalEpoch
      )
        return;
      routes.value = p.list;
      routesCount.value = p.count;
    } catch (e) {
      if (
        lookupLane.current(ticket) &&
        view === viewEpoch &&
        modal === modalEpoch
      )
        formError.value = e instanceof Error ? e.message : '获准路由读取失败';
    } finally {
      if (
        lookupLane.current(ticket) &&
        view === viewEpoch &&
        modal === modalEpoch
      )
        lookupBusy.value = false;
    }
  }
  async function moreRoutes() {
    if (!currentOwner()) return;
    if (lookupBusy.value || routes.value.length >= routesCount.value) return;
    const ticket = lookupLane.start(),
      view = viewEpoch,
      modal = modalEpoch,
      q = routesSearch,
      next = routesPage + 1;
    lookupBusy.value = true;
    try {
      const p = await api.lookups('route', 'use', ticket.signal, q, next);
      if (
        !lookupLane.current(ticket) ||
        view !== viewEpoch ||
        modal !== modalEpoch ||
        q !== routesSearch
      )
        return;
      routesPage = next;
      routes.value = [
        ...routes.value,
        ...p.list.filter((r) => !routes.value.some((x) => x.id === r.id)),
      ];
      routesCount.value = p.count;
    } catch (e) {
      if (
        lookupLane.current(ticket) &&
        view === viewEpoch &&
        modal === modalEpoch
      )
        formError.value = e instanceof Error ? e.message : '继续读取失败';
    } finally {
      if (
        lookupLane.current(ticket) &&
        view === viewEpoch &&
        modal === modalEpoch
      )
        lookupBusy.value = false;
    }
  }
  function configData(forKind: string): Record<string, unknown> {
    switch (forKind) {
      case 'connection':
        return {
          protocol: form.protocol,
          origin: form.origin,
          api_prefix: form.api_prefix,
          responsibility: form.responsibility,
          responsibility_note: form.responsibility_note,
          daily_request_limit: numbers.daily_request_limit,
        };
      case 'model':
        return {
          connection_id: numbers.connection_id,
          provider_model_id: form.provider_model_id,
          capability: form.capability,
          input_limit_chars: numbers.input_limit_chars,
          max_output_tokens: numbers.max_output_tokens,
          supports_background: flags.supports_background,
          ...(form.capability === 'speech' ? { voice: form.voice } : {}),
          ...(form.valid_until ? { valid_until: form.valid_until } : {}),
        };
      case 'route':
        return {
          capability: form.capability,
          model_id: numbers.model_id,
          template_id: numbers.template_id,
          consumer_module: form.consumer_module,
          scope_note: form.scope_note,
        };
      case 'template':
        return {
          capability: form.capability,
          instructions: form.instructions,
          scope_note: form.scope_note,
        };
      default:
        throw new Error('配置类型无效');
    }
  }
  async function submitForm() {
    const intent = formIntent.value;
    if (busy.value || !intent || !currentOwner()) return;
    const active = () =>
      intent.generation === sessionGeneration() &&
      intent.view === viewEpoch &&
      intent.modal === modalEpoch &&
      formIntent.value === intent &&
      formVisible.value;
    if (!active()) return;
    busy.value = true;
    formError.value = '';
    try {
      let record = formFrozen.value;
      if (!record) {
        const isTask = intent.kind === 'task';
        const data: Record<string, unknown> = isTask
          ? {
              title: form.title,
              route_id: numbers.route_id,
              document_id: numbers.document_id,
              revision_id: numbers.revision_id,
              instruction: form.instruction,
              consumer_module: form.consumer_module,
              ...(numbers.repeat_of_task_id
                ? { repeat_of_task_id: numbers.repeat_of_task_id }
                : {}),
              ...(numbers.rework_of_task_id
                ? {
                    rework_of_task_id: numbers.rework_of_task_id,
                    rework_of_task_version: numbers.rework_of_task_version,
                  }
                : {}),
              confirm_possible_duplicate_cost:
                flags.confirm_possible_duplicate_cost,
              request_id: intent.requestId,
            }
          : {
              kind: intent.kind,
              title: form.title,
              data: configData(intent.kind),
              request_id: intent.requestId,
              ...(intent.id ? { id: intent.id, version: intent.version } : {}),
            };
        record = await originals.prepare(
          isTask ? 'createTask' : 'saveConfiguration',
          intent.kind,
          data
        );
        if (!active()) return;
        formFrozen.value = record;
      }
      const outcome = await originals.run(record, true);
      if (!active()) return;
      if (outcome.state === 'applied') {
        const detailAtCompletion = detailEpoch;
        formVisible.value = false;
        formFrozen.value = null;
        busy.value = false;
        const result = outcome.result as BusinessRecord;
        await refresh();
        if (
          intent.generation === sessionGeneration() &&
          intent.view === viewEpoch &&
          intent.modal === modalEpoch &&
          formIntent.value === intent &&
          detailEpoch === detailAtCompletion
        )
          await openDetail(result);
      } else
        formError.value =
          outcome.state === 'rejected'
            ? '原请求已明确拒绝。请在待恢复列表核对并释放后重新编辑。'
            : '原请求仍待恢复；关闭窗口不会撤销业务。请保留同UUID和原输入。';
    } catch (e) {
      if (active())
        formError.value =
          e instanceof Error ? e.message : '原请求保留，结果尚未确认';
    } finally {
      if (active()) busy.value = false;
    }
  }
  async function loadReplacementTask() {
    const intent = actionIntent.value,
      id = replacementTaskId.value;
    if (
      !currentOwner() ||
      !intent ||
      intent.name !== 'supersede' ||
      !id ||
      actionFrozen.value
    )
      return;
    const ticket = lookupLane.start();
    replacementTask.value = null;
    replacementBusy.value = true;
    const valid = () =>
      lookupLane.current(ticket) &&
      intent === actionIntent.value &&
      intent.modal === modalEpoch &&
      intent.view === viewEpoch &&
      actionVisible.value &&
      replacementTaskId.value === id;
    try {
      const row = await api.acceptedTask(id, ticket.signal);
      if (!valid()) return;
      if (row.id === intent.row.id) throw new Error('不能用同一任务替代自身。');
      replacementTask.value = row;
    } catch (cause) {
      if (valid())
        formError.value =
          cause instanceof Error ? cause.message : '候选读取未完成';
    } finally {
      if (valid()) replacementBusy.value = false;
    }
  }
  function beginReworkTask() {
    if (!currentOwner() || kind.value !== 'task' || !permissions.value.use)
      return;
    const row = selected.value;
    if (!row || row.status !== 'rework_required') return;
    const source = row.details.source;
    if (!source || typeof source !== 'object' || Array.isArray(source)) return;
    const fixed = source as Record<string, unknown>;
    if (
      typeof fixed.document_id !== 'number' ||
      !Number.isSafeInteger(fixed.document_id) ||
      fixed.document_id < 1 ||
      typeof fixed.revision_id !== 'number' ||
      !Number.isSafeInteger(fixed.revision_id) ||
      fixed.revision_id < 1 ||
      typeof row.details.consumer_module !== 'string' ||
      (row.details.consumer_module !== 'general' &&
        !/^[a-z][a-z0-9-]*(?:[.][a-z][a-z0-9-]*)+$/.test(
          row.details.consumer_module
        ))
    ) {
      detailError.value = '原重做任务的固定来源不完整，请重新核对。';
      return;
    }
    openForm();
    form.title = (row.title + ' · 重做').slice(0, 200);
    form.consumer_module = row.details.consumer_module;
    numbers.document_id = fixed.document_id;
    numbers.revision_id = fixed.revision_id;
    numbers.rework_of_task_id = row.id;
    numbers.rework_of_task_version = row.version;
  }
  function openAction(name: string) {
    if (!currentOwner()) return;
    const row = selected.value;
    if (!row) return;
    if (
      name === 'manual' &&
      (row.details.candidate ||
        row.details.fixed_result ||
        row.details.collection_claim ||
        row.details.manual_request_id ||
        ['candidate', 'collected'].includes(String(row.details.stage)))
    ) {
      detailError.value = '已有不可变候选或领取，只能保留历史并新建任务';
      return;
    }
    if (
      ['cancel', 'retry', 'collect'].includes(name) &&
      row.details.collection_claim
    ) {
      detailError.value =
        '已有原领取锚点，请在持久原请求列表恢复原UUID，不重建领取或更换任务';
      return;
    }
    const resultType =
      overview.value?.result_types?.[String(row.details.capability)];
    if (name === 'manual' && (!resultType || !manualLabels[resultType])) {
      detailError.value = '无法确定本任务的候选类型，请刷新任务后重试。';
      return;
    }
    actionIntent.value = {
      kind: taskPage.value ? 'task' : kind.value,
      row: JSON.parse(JSON.stringify(row)) as BusinessRecord,
      name,
      requestId: crypto.randomUUID(),
      view: viewEpoch,
      modal: ++modalEpoch,
      generation: sessionGeneration(),
    };
    formVisible.value = false;
    actionFrozen.value = null;
    busy.value = false;
    manualType.value = resultType ?? '';
    manualText.value = '';
    manualFile.value = '';
    action.value = name;
    replacementTaskId.value = undefined;
    replacementTask.value = null;
    replacementBusy.value = false;
    reason.value = '';
    credential.value = '';
    recoveryKey.value = '';
    formError.value = '';
    actionVisible.value = true;
  }
  async function submitAction() {
    const intent = actionIntent.value;
    if (busy.value || !intent || !currentOwner()) return;
    const active = () =>
      intent.generation === sessionGeneration() &&
      intent.view === viewEpoch &&
      intent.modal === modalEpoch &&
      actionIntent.value === intent &&
      actionVisible.value;
    if (!active()) return;
    busy.value = true;
    formError.value = '';
    let secret = '';
    try {
      let record = actionFrozen.value;
      if (!record) {
        const r = intent.row,
          name = intent.name;
        let endpoint: MutationEndpoint,
          data: Record<string, unknown> = {
            id: r.id,
            request_id: intent.requestId,
          };
        if (name === 'credential') {
          endpoint = 'saveCredential';
          secret = credential.value;
          data = { ...data, version: r.version, secret };
        } else if (['enable', 'disable'].includes(name)) {
          endpoint = 'configurationState';
          data = {
            ...data,
            kind: intent.kind,
            version: r.version,
            action: name,
            reason: reason.value,
          };
        } else if (name === 'manual') {
          endpoint = 'manualCandidate';
          data = {
            ...data,
            version: r.version,
            result_type: manualType.value,
            reason: reason.value,
            ...(['text', 'transcript'].includes(manualType.value)
              ? { text: manualText.value }
              : { file_key: manualFile.value }),
          };
        } else if (name === 'collect') {
          endpoint = 'collectCandidate';
          data = { ...data, version: r.version };
        } else if (['withdraw', 'rework', 'supersede'].includes(name)) {
          endpoint = 'candidateLifecycle';
          data = {
            ...data,
            version: r.version,
            action: name,
            reason: reason.value,
          };
          if (name === 'supersede') {
            const replacement = replacementTask.value;
            if (!replacement || replacement.id !== replacementTaskId.value)
              throw new Error(
                '请先读取并核对实际已采纳候选，不能手填未知版本。'
              );
            Object.assign(data, {
              replacement_task_id: replacement.id,
              replacement_task_version: replacement.version,
            });
          }
        } else if (['accept', 'return'].includes(name)) {
          endpoint = 'decide';
          data = {
            ...data,
            version: r.version,
            decision: name,
            reason: reason.value,
          };
        } else {
          endpoint = 'taskAction';
          data = api.taskActionPayload(r, name, intent.requestId);
        }
        record = await originals.prepare(endpoint, intent.kind, data);
        if (!active()) return;
        actionFrozen.value = record;
        if (name === 'credential' && credential.value === secret)
          credential.value = '';
      } else if (record.endpoint === 'saveCredential') {
        secret = credential.value;
        if (active()) credential.value = '';
      }
      const outcome = await originals.run(record, true, secret);
      if (!active()) return;
      if (outcome.state === 'applied') {
        const detailAtCompletion = detailEpoch;
        actionVisible.value = false;
        actionFrozen.value = null;
        busy.value = false;
        await refresh();
        if (
          intent.generation === sessionGeneration() &&
          intent.view === viewEpoch &&
          intent.modal === modalEpoch &&
          actionIntent.value === intent &&
          detailEpoch === detailAtCompletion &&
          drawer.value &&
          selected.value?.id === intent.row.id
        )
          await openDetail(outcome.result as BusinessRecord);
      } else
        formError.value =
          outcome.state === 'rejected'
            ? '服务器已明确拒绝且无锚点。请在原请求列表释放后重新办理。'
            : '业务尚未确认；已保留原输入和UUID，请从待恢复列表继续。';
    } catch (e) {
      if (active())
        formError.value =
          e instanceof Error ? e.message : '原请求保留，未确认结果';
    } finally {
      secret = '';
      if (active()) busy.value = false;
    }
  }
  function openRecovery(record: PendingRequest) {
    if (!currentOwner()) return;
    if (
      record.scope.tenantId !== overview.value?.current_tenant_id ||
      record.scope.memberId !== overview.value?.current_member_id
    ) {
      error.value = '该原请求不属于当前实际成员，请重新加载。';
      return;
    }
    recoveryGeneration = sessionGeneration();
    recoveryEpoch++;
    modalEpoch++;
    formVisible.value = false;
    actionVisible.value = false;
    busy.value = false;
    credential.value = '';
    recoveryTarget.value = JSON.parse(JSON.stringify(record)) as PendingRequest;
    recoverySecret.value = '';
    recoveryFile.value = '';
    recoveryVisible.value = true;
  }
  function stopWaiting(record: PendingRequest) {
    if (!currentOwner()) return;
    originals.stopWaiting(record);
  }
  async function checkOriginal(record: PendingRequest, retry = false) {
    if (!currentOwner()) return;
    const view = viewEpoch,
      generation = sessionGeneration(),
      target = recoveryTarget.value,
      recoveryModal = recoveryEpoch;
    if (
      retry &&
      (recoveryGeneration !== generation ||
        target?.id !== record.id ||
        target.digest !== record.digest ||
        !recoveryVisible.value)
    )
      return;
    let secret = retry ? recoverySecret.value : '',
      file = retry ? recoveryFile.value : '';
    if (retry) recoverySecret.value = '';
    try {
      const outcome = await originals.run(
        record,
        retry,
        secret,
        file || undefined
      );
      if (
        generation !== sessionGeneration() ||
        view !== viewEpoch ||
        recoveryModal !== recoveryEpoch
      )
        return;
      if (outcome.state === 'applied') {
        if (
          recoveryTarget.value?.id === record.id &&
          recoveryModal === recoveryEpoch
        )
          recoveryVisible.value = false;
        if (
          kind.value === record.kind ||
          (taskPage.value && record.kind === 'task')
        ) {
          const completedModal = modalEpoch,
            completedDetail = detailEpoch;
          await refresh();
          if (
            generation === sessionGeneration() &&
            view === viewEpoch &&
            modalEpoch === completedModal &&
            detailEpoch === completedDetail &&
            selected.value?.id === (outcome.result as BusinessRecord).id
          )
            await openDetail(outcome.result as BusinessRecord);
        }
      }
    } catch (e) {
      if (generation === sessionGeneration() && view === viewEpoch)
        error.value = e instanceof Error ? e.message : '原请求保留';
    } finally {
      secret = '';
      file = '';
    }
  }
  function applyFields(values: Record<string, unknown>) {
    for (const [key, value] of Object.entries(values)) {
      if (typeof value === 'string') form[key] = value;
      else if (typeof value === 'number') numbers[key] = value;
      else if (typeof value === 'boolean') flags[key] = value;
    }
  }
  async function releaseOriginal(record: PendingRequest) {
    if (!currentOwner()) return;
    const view = viewEpoch,
      generation = sessionGeneration(),
      modal = modalEpoch;
    try {
      const outcome = await originals.release(record);
      if (
        generation !== sessionGeneration() ||
        view !== viewEpoch ||
        modal !== modalEpoch
      )
        return;
      if (outcome.state === 'applied') {
        error.value = '服务器确认原请求已经发生，未建立新请求';
        return;
      }
      if (record.kind !== kind.value) {
        error.value = '原请求已明确拒绝并释放，请到原业务页面重新办理';
        return;
      }
      const p = record.payload;
      if (
        record.endpoint === 'saveConfiguration' ||
        record.endpoint === 'createTask'
      ) {
        let row: BusinessRecord | undefined;
        if (typeof p.id === 'number')
          row = await api.detail(
            record.kind,
            p.id,
            new AbortController().signal
          );
        if (
          generation !== sessionGeneration() ||
          view !== viewEpoch ||
          modal !== modalEpoch
        )
          return;
        openForm(row);
        form.title = String(p.title || '');
        if (p.data && typeof p.data === 'object')
          applyFields(p.data as Record<string, unknown>);
        else applyFields(p);
      } else if (typeof p.id === 'number') {
        const row = await api.detail(
          record.kind,
          p.id,
          new AbortController().signal
        );
        if (
          generation !== sessionGeneration() ||
          view !== viewEpoch ||
          modal !== modalEpoch
        )
          return;
        const opening = openDetail(row),
          expectedModal = modalEpoch;
        await opening;
        if (
          generation !== sessionGeneration() ||
          view !== viewEpoch ||
          modalEpoch !== expectedModal ||
          selected.value?.id !== row.id
        )
          return;
        const name =
          record.endpoint === 'saveCredential'
            ? 'credential'
            : record.endpoint === 'configurationState' ||
              record.endpoint === 'taskAction' ||
              record.endpoint === 'candidateLifecycle'
            ? String(p.action)
            : record.endpoint === 'manualCandidate'
            ? 'manual'
            : record.endpoint === 'collectCandidate'
            ? 'collect'
            : String(p.decision);
        openAction(name);
        reason.value = String(p.reason || '');
        if (
          record.endpoint === 'candidateLifecycle' &&
          typeof p.replacement_task_id === 'number'
        )
          replacementTaskId.value = p.replacement_task_id;
        if (record.endpoint === 'manualCandidate') {
          manualType.value = String(p.result_type);
          manualText.value = String(p.text || '');
          manualFile.value = String(p.file_key || '');
        }
      }
    } catch (e) {
      if (generation === sessionGeneration() && view === viewEpoch)
        error.value =
          e instanceof Error ? e.message : '原请求未释放，结果仍待核对';
    }
  }
  async function compare() {
    if (!currentOwner()) return;
    const r = selected.value;
    if (!r) return;
    const ticket = compareLane.start(),
      view = viewEpoch,
      detail = detailEpoch,
      id = r.id;
    comparison.value = null;
    detailError.value = '';
    try {
      const c = await api.candidate(id, ticket.signal);
      if (
        compareLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch &&
        selected.value?.id === id
      )
        comparison.value = c;
    } catch (e) {
      if (
        compareLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch
      ) {
        comparison.value = null;
        detailError.value = e instanceof Error ? e.message : '候选读取失败';
      }
    }
  }
  async function preview(operation: string) {
    if (!currentOwner()) return;
    const r = selected.value;
    if (!r) return;
    const ticket = previewLane.start(),
      view = viewEpoch,
      detail = detailEpoch,
      id = r.id;
    try {
      const result = await api.resolve(id, operation, ticket.signal);
      if (
        !previewLane.current(ticket) ||
        view !== viewEpoch ||
        detail !== detailEpoch ||
        selected.value?.id !== id
      )
        return;
      const d = result.delivery,
        url =
          typeof result.url === 'string'
            ? result.url
            : d &&
              typeof d === 'object' &&
              'url' in d &&
              typeof d.url === 'string'
            ? d.url
            : null;
      if (!url)
        throw new Error(
          String(result.reason || result.status || '当前文件交付不可用')
        );
      const a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.click();
    } catch (e) {
      if (
        previewLane.current(ticket) &&
        view === viewEpoch &&
        detail === detailEpoch
      )
        detailError.value = e instanceof Error ? e.message : '文件访问失败';
    }
  }
  watch(
    () => route.fullPath,
    () => {
      clear();
      search.value = String(route.query.search || '');
      statusFilter.value = String(route.query.status || '');
      capability.value = String(route.query.capability || '');
      page.value = 1;
      const view = viewEpoch,
        generation = sessionGeneration(),
        id = positiveQueryId(route.query.task_id),
        isTask = taskPage.value;
      void refresh().then(() => {
        if (
          generation === sessionGeneration() &&
          view === viewEpoch &&
          isTask &&
          id
        )
          void openDetail({ id } as BusinessRecord);
      });
    },
    { immediate: true }
  );
  watch(actionVisible, (v) => {
    if (!v) {
      credential.value = '';
      manualText.value = '';
    }
  });
  watch(recoveryVisible, (v) => {
    if (!v) {
      recoveryEpoch++;
      recoverySecret.value = '';
    }
  });
  watch(drawer, (v) => {
    if (!v) {
      detailEpoch++;
      detailLane.cancel();
      statusLane.cancel();
      historyLane.cancel();
      compareLane.cancel();
      previewLane.cancel();
      if (timer) clearTimeout(timer);
      timer = undefined;
      selected.value = null;
      comparison.value = null;
      olderBusy.value = false;
    }
  });
</script>
<style scoped>
  .workspace {
    padding: 28px;
    max-width: 1500px;
    margin: auto;
    color: #203b36;
  }
  header {
    display: flex;
    justify-content: space-between;
    gap: 22px;
    align-items: center;
  }
  h1 {
    font-size: 30px;
    margin: 8px 0;
  }
  .eyebrow {
    font-size: 12px;
    letter-spacing: 0.12em;
    color: #087f6b;
  }
  header p {
    line-height: 1.7;
    color: #61766e;
  }
  nav,
  .actions,
  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 20px 0;
  }
  nav a {
    padding: 9px 15px;
    border-radius: 8px;
    background: #eaf4ee;
    color: #285849;
    text-decoration: none;
  }
  .router-link-active {
    background: #c9e7d8;
  }
  .filters .el-input,
  .filters .el-select {
    max-width: 260px;
  }
  .el-alert {
    margin: 14px 0;
  }
  .setup-guide {
    padding: 16px 20px;
    border: 1px solid #d5e3dd;
    border-radius: 9px;
    background: #f7faf8;
  }
  .setup-guide p {
    margin: 8px 0;
  }
  .setup-guide ol {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 32px;
    padding-left: 20px;
    margin-bottom: 0;
  }
  .setup-guide a {
    color: #087f6b;
  }
  pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-family: inherit;
    line-height: 1.65;
    max-height: 500px;
    overflow: auto;
  }
  .comparison {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin: 20px 0;
  }
  .comparison > div {
    padding: 15px;
    border: 1px solid #d5e3dd;
    border-radius: 9px;
    background: #f7faf8;
  }
  p {
    line-height: 1.7;
  }
  details {
    margin: 14px 0;
  }
  @media (max-width: 700px) {
    header {
      display: block;
    }
    .comparison {
      grid-template-columns: 1fr;
    }
    .workspace {
      padding: 16px;
    }
  }
</style>
