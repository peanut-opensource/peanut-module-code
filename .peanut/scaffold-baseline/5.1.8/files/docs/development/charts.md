# 图表模块

`peanut.chart` 提供 ECharts 6.1.0 / vue-echarts 8.3.0 的管理端公共入口；引擎依赖属于模块，不进入必装 Core。`peanut.chart-examples` 是独立可选模块，只含静态示例、菜单和查看权限，单向依赖 `peanut.chart ^1.0`，没有业务表或 API。

两个模块的开发与维护源码位于独立模块应用 `peanut-opensource/peanut-module-code`，分别由该应用的 `server/app/modules/peanut/chart`、`chart_examples` 及 `web/src/modules/peanut-chart`、`peanut-chart-examples` 承接。通用 Code 脚手架不携带这两个模块，不将图表包列入首次安装选择或宿主依赖；默认工作台使用原生表格呈现已落库的可选 Demo 样本。

需要图表的应用或业务模块通过现有原生 Module 安装和依赖声明采用能力；需要图库示例时再安装 `peanut.chart-examples`、为租户开通并授予 `peanut.chart-examples.view`，入口为 `/examples/charts`。基础模块 `tenant.enableable=false`，属于安装后可用的部署基础能力；`lifecycle.protected=false` 允许在无消费者时退出。源码发现、包安装、租户开通和人员授权仍是独立动作。正式交付须采用已获授权的固定 Core/模块版本，本指南不表示已发布或完成外部运行验收。

## 公共入口

```vue
<template>
  <Chart
    ref="chart"
    :option="option"
    :loading="loading"
    autoresize
    style="width: 100%; height: 320px"
    @click="onClick"
  />
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { Chart, type EChartsOption } from '@peanut-admin/peanut-chart/charts';

  const chart = ref<InstanceType<typeof Chart> | null>(null);
  const loading = ref(false);
  const option: EChartsOption = {
    xAxis: { type: 'category', data: ['A', 'B', 'C'] },
    yAxis: { type: 'value' },
    series: [{ type: 'bar', data: [12, 20, 15] }],
  };
  const onClick = (event: { name?: string }) => console.log(event.name);
  // 挂载后可使用 chart.value?.getDataURL()、dispatchAction() 等原生实例方法。
</script>
```

入口按需登记 Canvas、折线、柱、饼、雷达、散点、仪表盘，以及 grid、tooltip、legend、dataZoom、graphic、title、dataset、aria 组件；没有另一套配置 DSL。直接传 ECharts option。Chart 是原生 vue-echarts 组件的公开重导出，所有 props、slots、事件、实例方法、自动尺寸处理及销毁均由上游维护。容器须有实际高度；隐藏后恢复、加载状态使用原生 `autoresize` 和 `loading`，不另注册 window resize 或手工 dispose。

需要统一主题的采用应用可用官方 `THEME_KEY` 提供响应式明暗主题；具体页面也可用 `theme` 显式设置。通用脚手架 `App.vue` 不再直接导入 Chart 主题键。ECharts 6 的暗色主题名为 `dark`，恢复默认亮色使用 `default`，不要传未注册的 `light`。实例 ref 类型使用 `InstanceType<typeof Chart>`。上游完整 API、手动更新和其他图形能力见 [vue-echarts 文档](https://github.com/ecomfe/vue-echarts)。新增图形类型时在基础模块的公开入口登记实际所需 ECharts 模块，不向宿主恢复全局 Chart 或重复注册。

业务模块应在自己的 `module.json` 声明 `{"module_key":"peanut.chart","version":"^1.0"}`，在前端 `package.json` 声明 `@peanut-admin/peanut-chart: workspace:^1.0.0` 后导入公共子路径。不要跨模块访问源码内部路径。基础模块不导入示例，公共文档位于本文件，不随示例退出。

## 独立交付与退出

在应用根已配置工具环境后，分别使用原生入口：

```sh
peanut module pack peanut.chart --output=/absolute/release/modules/peanut.chart-1.0.2.tar
peanut module pack peanut.chart-examples --output=/absolute/release/modules/peanut.chart-examples-1.0.0.tar
peanut module install /absolute/release/modules/peanut.chart-1.0.2.tar --sha256=<archive-sha256>
peanut module install /absolute/release/modules/peanut.chart-examples-1.0.0.tar --sha256=<archive-sha256>
peanut module disable peanut.chart-examples
peanut module uninstall peanut.chart-examples
```

install 的来源摘要取对应制包输出，不把例子中的占位符当实际参数。后续安装示例前先安装其基础依赖；已有源码仍走同一原生包入口，不另设子插件系统。uninstall 先返回精确预览，按现有 UI 确认或将返回的 `confirm_plan` 保存为 JSON，再以 `--confirm-plan-file=<file> --confirm-plan-digest=<digest>` 执行同一操作。不加 `--purge` 时是 retire：退出该包源码、锁和原生依赖，重建受影响前端；不是仅隐藏菜单。保留的恢复现场按原服务管理。

脚手架默认宿主已解除图表调用及 Web/Composer 直接依赖。采用应用若仍声明 `web/package.json` 的图表依赖，其生命周期反向依赖检查会返回 `host:web->peanut.chart` 并阻止基础模块退出；示例及其他已安装业务模块的依赖也按原合同检查。移除实际调用与依赖后，再走正常卸载入口。由安装器自动装配的 Module Composer require 不等同于宿主业务依赖；后端投影没有 Web 源码时不读取 Web 文件。模块专用应用拥有这些模块源码；上游脚手架升级删除旧受管路径前，必须沿原生升级 plan/resolve 的精确保留决策登记为 app-owned，不能改旧 baseline 或摘要冒充迁移。

`uninstalled` 是安装历史状态，不能单独证明已清洁。确认清洁须核原生 purge 回执与同一模块版本／摘要、已移除源码和依赖、归属表及目录记录；已执行迁移、安装审计和必要恢复材料保留不属于运行残留。普通 UI 整理不授权清除模块数据，purge 仍按[模块卸载合同](../public/module-development.md)取得精确范围授权。菜单未显示或示例已退出不代表基础图表无消费者；媒体、模块开发等应用的有效调用及依赖继续保留。
