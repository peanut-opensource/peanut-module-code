<script setup lang="ts">
  import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    shallowRef,
    watch,
  } from 'vue';
  import {
    Handle,
    Position,
    useNodesInitialized,
    VueFlow,
    useVueFlow,
  } from '@vue-flow/core';
  import type {
    Connection,
    NodeChange,
    NodeDragEvent,
    ViewportTransform,
  } from '@vue-flow/core';
  import '@vue-flow/core/dist/style.css';
  import '@vue-flow/core/dist/theme-default.css';
  import type { EditorDocument } from '../types';

  const props = defineProps<{
    document: EditorDocument;
    instanceId: string;
    selectedKey: string;
    editable: boolean;
  }>();
  const emit = defineEmits<{
    (event: 'select', key: string): void;
    (event: 'move', key: string, x: number, y: number): void;
    (event: 'connect', from: string, to: string): void;
    (event: 'viewport', value: ViewportTransform): void;
  }>();
  const flow = useVueFlow(props.instanceId);
  const nodesInitialized = useNodesInitialized();
  const dragging = shallowRef<Record<string, { x: number; y: number }>>({});
  const nodes = computed(() =>
    props.document.graph.nodes.map((node, index) => {
      const saved = props.document.layout.nodes.find(
        (entry) => entry.key === node.key
      );
      return {
        id: node.key,
        type: 'workflow',
        position:
          dragging.value[node.key] ||
          (saved
            ? { x: saved.x, y: saved.y }
            : { x: 220 * (index % 3), y: 160 * Math.floor(index / 3) }),
        data: { label: node.label, type: node.type },
        selected: props.selectedKey === node.key,
      };
    })
  );
  const edges = computed(() =>
    props.document.graph.transitions.map((edge) => ({
      id: edge.key,
      source: edge.from,
      target: edge.to,
      label: edge.action,
      animated: false,
      style: edge.return_edge ? { strokeDasharray: '6 4' } : {},
    }))
  );
  function connect(connection: Connection) {
    if (connection.source && connection.target)
      emit('connect', connection.source, connection.target);
  }
  function changes(entries: NodeChange[]) {
    // Measurements are technical canvas state; semantic edits remain in parent history.
    flow.applyNodeChanges(
      entries.filter((entry) => entry.type === 'dimensions')
    );
    entries.forEach((entry) => {
      if (entry.type === 'position' && entry.position)
        dragging.value = { ...dragging.value, [entry.id]: entry.position };
    });
  }
  function move(event: NodeDragEvent) {
    const position = dragging.value[event.node.id] || event.node.position;
    emit('move', event.node.id, position.x, position.y);
    dragging.value = {};
  }
  function viewport(event: {
    event: unknown;
    flowTransform: ViewportTransform;
  }) {
    if (event.event) emit('viewport', event.flowTransform);
  }
  const containerId = `workflow-canvas-${props.instanceId}`;
  let viewportInitialized = false;
  let disposed = false;
  async function updateViewport(force = false) {
    await nextTick();
    if (disposed) return;
    const element = document.getElementById(containerId);
    if (!element || element.clientWidth === 0 || element.clientHeight === 0)
      return;
    const { viewport: savedViewport } = props.document.layout;
    if (!savedViewport && !nodesInitialized.value) return;
    if (!viewportInitialized || force) {
      if (savedViewport) await flow.setViewport(savedViewport);
      else viewportInitialized = await flow.fitView({ padding: 0.2 });
      if (savedViewport) viewportInitialized = true;
    } else if (!savedViewport) await flow.fitView({ padding: 0.2 });
  }
  const observer = new ResizeObserver(() => updateViewport());
  onMounted(() => {
    const element = document.getElementById(containerId);
    if (element) observer.observe(element);
    updateViewport();
  });
  watch(nodesInitialized, (initialized) => {
    if (initialized && !props.document.layout.viewport) updateViewport();
  });
  watch(
    () => props.document.layout.viewport,
    () => updateViewport(true),
    { deep: true }
  );
  onBeforeUnmount(() => {
    disposed = true;
    observer.disconnect();
  });
</script>

<template>
  <div :id="containerId" class="workflow-canvas" aria-label="流程画布">
    <div class="canvas-tools nodrag nopan nowheel"
      ><el-button size="small" @click="flow.fitView({ padding: 0.2 })"
        >适应画布</el-button
      ><el-button size="small" @click="flow.zoomIn()">放大</el-button
      ><el-button size="small" @click="flow.zoomOut()">缩小</el-button></div
    >
    <VueFlow
      :id="instanceId"
      :nodes="nodes"
      :edges="edges"
      :apply-default="false"
      :nodes-draggable="editable"
      :nodes-connectable="editable"
      :min-zoom="0.2"
      :max-zoom="2"
      @nodes-change="changes"
      @connect="connect"
      @node-drag-stop="move"
      @node-click="emit('select', $event.node.id)"
      @move-end="viewport"
    >
      <template #node-workflow="{ id, data }">
        <div class="workflow-node" :class="{ selected: selectedKey === id }">
          <Handle
            v-if="data.type !== 'start'"
            type="target"
            :position="Position.Left"
          />
          <button class="node-title nodrag nopan" @click="emit('select', id)">{{
            data.label
          }}</button
          ><small>{{ data.type }}</small>
          <Handle
            v-if="data.type !== 'end'"
            type="source"
            :position="Position.Right"
          />
        </div>
      </template>
    </VueFlow>
  </div>
</template>

<style scoped>
  .workflow-canvas {
    width: 100%;
    height: 560px;
    min-height: 400px;
    position: relative;
    border: 1px solid var(--el-border-color);
    background: var(--el-fill-color-light);
  }
  .canvas-tools {
    position: absolute;
    z-index: 10;
    top: 8px;
    left: 8px;
  }
  .workflow-node {
    width: 180px;
    min-height: 76px;
    padding: 12px;
    border: 1px solid var(--el-border-color);
    border-radius: 8px;
    color: var(--el-text-color-primary);
    background: var(--el-bg-color);
  }
  .workflow-node.selected {
    border-color: var(--el-color-primary);
  }
  .node-title {
    background: transparent;
    border: 0;
    color: inherit;
    cursor: pointer;
    font: inherit;
    text-align: left;
    width: 100%;
    overflow-wrap: anywhere;
  }
  small {
    display: block;
    color: var(--el-text-color-secondary);
  }
</style>
